<?php
/**
 * Compiles the official EN 16931 UBL Schematron into the PHP rule table the plugin evaluates.
 *
 * Why a compiler and not a hand-written rule list: the rules are the normative content of the
 * standard and they change with every artefact release. A hand-copied list drifts silently and
 * turns into a conformance claim nobody can trace. This reads the artefact, records its version,
 * and marks every rule it could not translate instead of dropping it.
 *
 * PHP ships libxml, which is XPath 1.0. The artefact is written in XPath 2.0. The translations
 * below are the complete set the artefact actually needs, each one measured against the official
 * test corpus by tests/groups/12-schematron.php — not assumed:
 *
 *   exists(X)            -> boolean(X)
 *   upper-case(X)        -> translate(X, 'a..z', 'A..Z')
 *   lower-case(X)        -> translate(X, 'A..Z', 'a..z')
 *   abs(X)               -> ((X) * (1 - 2 * ((X) < 0)))
 *   xs:decimal(X)        -> number(X), or just X when it sits as a path step after "/"
 *   xs:integer(X)        -> same
 *   xs:date(X)           -> X  (the artefact only ever compares ISO 8601 dates, which order lexically)
 *   A eq B               -> A = B
 *   //(a|b)/c            -> (//a|//b)/c
 *   every $v in S satisfies T
 *                        -> a bound variable: the engine evaluates S against the context node,
 *                           substitutes the literal into T, and passes when S is empty (a universal
 *                           quantifier over an empty sequence is true). XPath 1.0 has no way to see
 *                           the outer context from inside a predicate, which is what these rules do.
 *
 * Usage:
 *   php tools/en16931-compile-schematron.php --artefact=<dir> [--out=includes/Validation/En16931RuleSet.php]
 *   php tools/en16931-compile-schematron.php --download            (fetches the pinned release into a temp dir)
 *   php tools/en16931-compile-schematron.php --check               (compiles and diffs against the committed table)
 *
 * Exit: 0 when the table was written (or matches, with --check); 1 on any failure.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

const ARTEFACT_VERSION  = '1.3.16';
const ARTEFACT_RELEASED = '2026-04-13';
const ARTEFACT_SOURCE   = 'https://github.com/ConnectingEurope/eInvoicing-EN16931/releases/tag/validation-1.3.16';
const ARTEFACT_LICENCE  = 'EUPL-1.2';
const SCH_RELATIVE      = 'schematron/preprocessed/EN16931-UBL-validation-preprocessed.sch';

const LOWER = 'abcdefghijklmnopqrstuvwxyz';
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/**
 * The argument of a function call that starts at $open (the index of its "("), balanced.
 *
 * A regular expression cannot do this: every one of these arguments contains further parentheses,
 * and three of them contain nested calls of the same function.
 */
function call_argument( string $expr, int $open ): ?array {
	$depth  = 0;
	$length = strlen( $expr );

	for ( $i = $open; $i < $length; $i++ ) {
		$char = $expr[ $i ];

		// A literal may hold an unbalanced parenthesis; skip it whole.
		if ( "'" === $char || '"' === $char ) {
			$end = strpos( $expr, $char, $i + 1 );
			if ( false === $end ) {
				return null;
			}
			$i = $end;
			continue;
		}

		if ( '(' === $char ) {
			$depth++;
		} elseif ( ')' === $char ) {
			$depth--;
			if ( 0 === $depth ) {
				return [ substr( $expr, $open + 1, $i - $open - 1 ), $i ];
			}
		}
	}

	return null;
}

/**
 * Rewrite every call of $name, innermost last, with $rewrite( argument, preceded_by_slash ).
 */
function rewrite_calls( string $expr, string $name, callable $rewrite ): string {
	$offset = 0;

	while ( true ) {
		$at = strpos( $expr, $name . '(', $offset );
		if ( false === $at ) {
			return $expr;
		}

		// "exists(" inside a longer name (there is none today, but a later artefact may add one).
		$before = $at > 0 ? $expr[ $at - 1 ] : '';
		if ( '' !== $before && ( ctype_alnum( $before ) || '-' === $before || '_' === $before ) ) {
			$offset = $at + 1;
			continue;
		}

		$found = call_argument( $expr, $at + strlen( $name ) );
		if ( null === $found ) {
			return $expr;
		}

		[ $argument, $close ] = $found;
		$replacement          = $rewrite( rewrite_calls( $argument, $name, $rewrite ), '/' === $before );
		$expr                 = substr( $expr, 0, $at ) . $replacement . substr( $expr, $close + 1 );
		$offset               = $at + strlen( $replacement );
	}
}

/**
 * XPath 1.0 has no parenthesised step. The artefact writes two shapes of one:
 *
 *   "//(a|b)/c"            -> "(//a|//b)/c"        a union, distributed over the axis
 *   "A/(normalize-space(x) = 'VAT')"
 *                          -> "A[normalize-space(x) = 'VAT']"   a predicate
 *
 * The second is only equivalent while A selects at most one node, which is what these rules do (a
 * PartyTaxScheme has one TaxScheme). The official corpus is what decides whether that holds.
 */
function expand_union_steps( string $expr ): string {
	$offset = 0;

	while ( true ) {
		$at = strpos( $expr, '/(', $offset );
		if ( false === $at ) {
			return $expr;
		}

		$found = call_argument( $expr, $at + 1 );
		if ( null === $found ) {
			return $expr;
		}

		[ $inside, $close ] = $found;

		// A parenthesised step that is not a union is a predicate: XPath 2.0 "A/(P)" yields P evaluated
		// for each A, and in the boolean context the artefact always uses it that is what "A[P]" says.
		if ( ! str_contains( $inside, '|' ) || preg_match( '/[()\'"]/', $inside ) ) {
			$path_from = path_start( $expr, $at );
			if ( $path_from === $at ) {
				$offset = $at + 2;
				continue;
			}

			$replacement = substr( $expr, $path_from, $at - $path_from ) . '[' . $inside . ']';
			$expr        = substr( $expr, 0, $path_from ) . $replacement . substr( $expr, $close + 1 );
			$offset      = $path_from + strlen( $replacement );
			continue;
		}

		// The axis is the run of slashes that ends at $at.
		$axis_start = $at;
		while ( $axis_start > 0 && '/' === $expr[ $axis_start - 1 ] ) {
			$axis_start--;
		}
		$prefix = substr( $expr, $axis_start, $at - $axis_start + 1 );

		// And the path in front of it, so "cac:X//(a|b)" distributes as "(cac:X//a|cac:X//b)".
		$head_start = $axis_start;
		while ( $head_start > 0 && preg_match( '/[A-Za-z0-9_:.\-\[\]@=\'"\/]/', $expr[ $head_start - 1 ] ) ) {
			$head_start--;
		}
		$head = substr( $expr, $head_start, $axis_start - $head_start );

		$branches = array_map(
			static fn( string $branch ): string => $head . $prefix . trim( $branch ),
			explode( '|', $inside )
		);

		$replacement = '(' . implode( ' | ', $branches ) . ')';
		$expr        = substr( $expr, 0, $head_start ) . $replacement . substr( $expr, $close + 1 );
		$offset      = $head_start + strlen( $replacement );
	}
}

/**
 * Where a relative path expression that ends at $slash begins.
 *
 * Walks left over one location path, keeping predicate brackets balanced so that the path in front
 * of a step inside a predicate is the one inside the predicate, not the whole outer path.
 */
function path_start( string $expr, int $slash ): int {
	$depth = 0;
	$i     = $slash;

	while ( $i > 0 ) {
		$char = $expr[ $i - 1 ];

		if ( ']' === $char ) {
			$depth++;
			$i--;
			continue;
		}
		if ( '[' === $char ) {
			if ( 0 === $depth ) {
				break;
			}
			$depth--;
			$i--;
			continue;
		}
		if ( $depth > 0 ) {
			$i--;
			continue;
		}
		if ( 1 === preg_match( '#[A-Za-z0-9_:\-./@*]#', $char ) ) {
			$i--;
			continue;
		}

		break;
	}

	return $i;
}

/**
 * Lift a function call out of a location step: "A/f(B)" -> "f(A/B)".
 *
 * XPath 2.0 lets a function call BE a step; XPath 1.0 does not, and libxml answers "Invalid
 * expression" for the whole test. The artefact uses exactly three functions that way — measured on
 * 1.3.16: normalize-space 242 times, xs:decimal 137 (removed by the cast rule, not lifted) and
 * upper-case 3. Applying this repeatedly pushes the path down to the innermost argument, so
 * "cac:TaxScheme/normalize-space(upper-case(cbc:ID))" ends as
 * "normalize-space(upper-case(cac:TaxScheme/cbc:ID))".
 */
function lift_path_functions( string $expr ): string {
	$names = [ 'normalize-space', 'upper-case', 'lower-case' ];

	for ( $pass = 0; $pass < 12; $pass++ ) {
		$changed = false;

		foreach ( $names as $name ) {
			$offset = 0;

			while ( true ) {
				$at = strpos( $expr, '/' . $name . '(', $offset );
				if ( false === $at ) {
					break;
				}

				$start = path_start( $expr, $at );
				if ( $start === $at ) {
					// Nothing in front of the slash to lift; leave it for the report.
					$offset = $at + 1;
					continue;
				}

				$found = call_argument( $expr, $at + 1 + strlen( $name ) );
				if ( null === $found ) {
					$offset = $at + 1;
					continue;
				}

				[ $argument, $close ] = $found;
				$path                 = substr( $expr, $start, $at - $start );
				$replacement          = $name . '(' . $path . '/' . $argument . ')';
				$expr                 = substr( $expr, 0, $start ) . $replacement . substr( $expr, $close + 1 );
				$offset               = $start;
				$changed              = true;
			}
		}

		if ( ! $changed ) {
			return $expr;
		}
	}

	return $expr;
}

/**
 * Split an expression on its top-level "|", leaving the ones inside predicates and calls alone.
 *
 * @return array<int, string>
 */
function split_union( string $expr ): array {
	$parts  = [];
	$depth  = 0;
	$start  = 0;
	$length = strlen( $expr );

	for ( $i = 0; $i < $length; $i++ ) {
		$char = $expr[ $i ];

		if ( "'" === $char || '"' === $char ) {
			$end = strpos( $expr, $char, $i + 1 );
			if ( false === $end ) {
				break;
			}
			$i = $end;
			continue;
		}

		if ( '(' === $char || '[' === $char ) {
			$depth++;
		} elseif ( ')' === $char || ']' === $char ) {
			$depth--;
		} elseif ( '|' === $char && 0 === $depth ) {
			$parts[] = substr( $expr, $start, $i - $start );
			$start   = $i + 1;
		}
	}

	$parts[] = substr( $expr, $start );

	return array_values( array_filter( array_map( 'trim', $parts ), static fn( string $p ): bool => '' !== $p ) );
}

/**
 * Turn a Schematron rule context into an expression that selects the same nodes.
 *
 * A context is an XSLT match pattern: "cbc:Amount" means every cbc:Amount ANYWHERE, not a child of
 * the root element. Evaluating it as a path is the defect that kept all 23 BR-CL rules from ever
 * firing while the engine reported them as evaluated — measured against the official corpus.
 */
function context_to_select( string $context ): string {
	$branches = array_map(
		static fn( string $branch ): string => str_starts_with( $branch, '/' ) ? $branch : '//' . $branch,
		split_union( $context )
	);

	return implode( ' | ', $branches );
}

/**
 * Every translation that does not need a bound variable.
 */
function translate_expression( string $expr ): string {
	// First, because the rules below rename the very functions that sit as steps.
	$expr = lift_path_functions( $expr );

	$expr = rewrite_calls( $expr, 'exists', static fn( string $a ): string => 'boolean(' . $a . ')' );
	$expr = rewrite_calls( $expr, 'upper-case', static fn( string $a ): string => "translate(" . $a . ", '" . LOWER . "', '" . UPPER . "')" );
	$expr = rewrite_calls( $expr, 'lower-case', static fn( string $a ): string => "translate(" . $a . ", '" . UPPER . "', '" . LOWER . "')" );
	$expr = rewrite_calls( $expr, 'abs', static fn( string $a ): string => '((' . $a . ') * (1 - 2 * ((' . $a . ') < 0)))' );

	// ends-with(X, 'Y') is XPath 2.0. starts-with() is not, and needs nothing.
	$expr = rewrite_calls(
		$expr,
		'ends-with',
		static function ( string $a ): string {
			$parts = explode( ',', $a, 2 );
			if ( 2 !== count( $parts ) ) {
				return 'ends-with(' . $a . ')';
			}
			$haystack = trim( $parts[0] );
			$needle   = trim( $parts[1] );

			return 'substring(' . $haystack . ', string-length(' . $haystack . ') - string-length(' . $needle . ') + 1) = ' . $needle;
		}
	);

	// A cast that sits as a path step disappears; anywhere else it becomes number(). "A/xs:decimal(B - 1)"
	// becomes "A/B - 1", which is the same value: subtraction binds looser than the path.
	$cast = static fn( string $a, bool $after_slash ): string => $after_slash ? $a : 'number(' . $a . ')';
	$expr = rewrite_calls( $expr, 'xs:decimal', $cast );
	$expr = rewrite_calls( $expr, 'xs:integer', $cast );
	// XPath 1.0 has no date type and its relational operators convert to NUMBER, so comparing
	// "2026-01-31" with "2026-02-01" compares NaN with NaN and is always false. Stripping the hyphens
	// turns an ISO 8601 date into an integer that orders the same way.
	$expr = rewrite_calls( $expr, 'xs:date', static fn( string $a ): string => "number(translate(" . $a . ", '-', ''))" );

	$expr = preg_replace( '/\s+eq\s+/', ' = ', (string) $expr );
	$expr = preg_replace( '/\s+ne\s+/', ' != ', (string) $expr );

	$expr = compare_boolean_literals( (string) $expr );

	return expand_union_steps( $expr );
}

/**
 * "X = false()" -> "normalize-space(X) = 'false'".
 *
 * XPath 1.0 compares a node-set with a boolean by taking the EFFECTIVE BOOLEAN VALUE of the node-set,
 * so "cbc:ChargeIndicator = false()" is true exactly when the element is ABSENT — the opposite of what
 * the document says, since UBL writes <cbc:ChargeIndicator>false</cbc:ChargeIndicator>. XPath 2.0
 * casts the string instead. The artefact writes this 120 times and every allowance and charge rule
 * depends on it.
 *
 * xs:boolean has four lexical forms: true, false, 1 and 0. Comparing only the words passed all 1129
 * official test cases — none of them writes a digit — and still misread ubl-tc434-example2.xml, an
 * official example whose document-level allowance is <cbc:ChargeIndicator>0</cbc:ChargeIndicator>.
 */
function compare_boolean_literals( string $expr ): string {
	$offset = 0;

	while ( preg_match( '/(!?=)\s*(true|false)\(\)/', $expr, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
		$at       = (int) $m[0][1];
		$operator = $m[1][0];
		$literal  = $m[2][0];

		// The operand in front of the operator, skipping the spaces between them.
		$left_end = $at;
		while ( $left_end > 0 && ' ' === $expr[ $left_end - 1 ] ) {
			$left_end--;
		}
		$left_start = path_start( $expr, $left_end );

		if ( $left_start === $left_end ) {
			$offset = $at + strlen( $m[0][0] );
			continue;
		}

		$operand  = substr( $expr, $left_start, $left_end - $left_start );
		$digit    = 'true' === $literal ? '1' : '0';
		$compared = "normalize-space(" . $operand . ")";

		$replacement = '!=' === $operator
			? "(" . $compared . " != '" . $literal . "' and " . $compared . " != '" . $digit . "')"
			: "(" . $compared . " = '" . $literal . "' or " . $compared . " = '" . $digit . "')";
		$expr        = substr( $expr, 0, $left_start ) . $replacement . substr( $expr, $at + strlen( $m[0][0] ) );
		$offset      = $left_start + strlen( $replacement );
	}

	return $expr;
}

/**
 * Pull "every $v in SEQ satisfies REST" apart into a binding and the rest of the test.
 *
 * @return array{0:array<string,string>, 1:string}
 */
function extract_bindings( string $test ): array {
	$bindings = [];

	while ( preg_match( '/\bevery\s+\$([A-Za-z_][A-Za-z0-9_]*)\s+in\s+/', $test, $m, PREG_OFFSET_CAPTURE ) ) {
		$name  = $m[1][0];
		$start = (int) $m[0][1];
		$from  = $start + strlen( $m[0][0] );

		// The sequence runs to " satisfies " at depth zero.
		$depth  = 0;
		$length = strlen( $test );
		$at     = null;
		for ( $i = $from; $i < $length; $i++ ) {
			$char = $test[ $i ];
			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
			} elseif ( 0 === $depth && 0 === substr_compare( $test, ' satisfies ', $i, 11 ) ) {
				$at = $i;
				break;
			}
		}
		if ( null === $at ) {
			break;
		}

		$bindings[ $name ] = trim( substr( $test, $from, $at - $from ) );
		$rest              = substr( $test, $at + 11 );

		// Whatever stood before the quantifier keeps standing; the artefact always has nothing there
		// or an opening parenthesis that the rest closes.
		$test = trim( substr( $test, 0, $start ) ) . $rest;
	}

	return [ $bindings, $test ];
}

/** Constructs that would silently evaluate to something else under XPath 1.0. */
function untranslatable_reason( string $expr ): string {
	foreach (
		[
			'matches('         => 'regular expressions',
			'tokenize('        => 'tokenize()',
			'string-join('     => 'string-join()',
			'distinct-values(' => 'distinct-values()',
			'index-of('        => 'index-of()',
			' instance of '    => 'instance of',
			' castable '       => 'castable',
			' idiv '           => 'idiv',
			' some $'          => 'existential quantifier',
			' for $'           => 'for expression',
			'xs:'              => 'a schema type cast this compiler does not know',
			' if ('            => 'conditional expression',
		] as $needle => $reason
	) {
		if ( str_contains( $expr, $needle ) ) {
			return $reason;
		}
	}

	return '';
}

// ---------------------------------------------------------------------------------------------
// Command line
// ---------------------------------------------------------------------------------------------

// Everything above is the translator, and tests/groups/12-schematron.php requires this file to check
// each translation on its own. Without this guard the require would run the compiler and exit.
if ( defined( 'EN16931_COMPILER_LIBRARY_ONLY' ) ) {
	return;
}


$options  = getopt( '', [ 'artefact:', 'out:', 'download', 'check' ] );
$root     = dirname( __DIR__ );
$out      = $options['out'] ?? $root . '/includes/Validation/En16931RuleSet.php';
$artefact = $options['artefact'] ?? null;

if ( isset( $options['download'] ) ) {
	$artefact = sys_get_temp_dir() . '/en16931-ubl-' . ARTEFACT_VERSION;
	if ( ! is_file( $artefact . '/' . SCH_RELATIVE ) ) {
		$url = 'https://github.com/ConnectingEurope/eInvoicing-EN16931/releases/download/validation-'
			. ARTEFACT_VERSION . '/en16931-ubl-' . ARTEFACT_VERSION . '.zip';
		$zip = $artefact . '.zip';
		fwrite( STDERR, "downloading {$url}\n" );
		if ( ! @copy( $url, $zip ) ) {
			fwrite( STDERR, "download failed\n" );
			exit( 1 );
		}
		$archive = new ZipArchive();
		if ( true !== $archive->open( $zip ) || ! $archive->extractTo( $artefact ) ) {
			fwrite( STDERR, "could not unpack {$zip}\n" );
			exit( 1 );
		}
		$archive->close();
	}
}

if ( null === $artefact ) {
	fwrite( STDERR, "usage: php tools/en16931-compile-schematron.php --artefact=<dir> | --download [--check]\n" );
	exit( 1 );
}

$sch = rtrim( $artefact, '/' ) . '/' . SCH_RELATIVE;
if ( ! is_file( $sch ) ) {
	fwrite( STDERR, "no {$sch}\n" );
	exit( 1 );
}

$document = new DOMDocument();
if ( ! $document->load( $sch ) ) {
	fwrite( STDERR, "{$sch} is not well-formed XML\n" );
	exit( 1 );
}

$xpath = new DOMXPath( $document );
$xpath->registerNamespace( 's', 'http://purl.oclc.org/dsdl/schematron' );

$namespaces = [];
foreach ( $xpath->query( '//s:ns' ) as $ns ) {
	$namespaces[ (string) $ns->getAttribute( 'prefix' ) ] = (string) $ns->getAttribute( 'uri' );
}

// Pattern and rule order are kept, because Schematron fires only the FIRST rule of a pattern whose
// context matches a node. Flattening would be safe against artefact 1.3.16 — measured, no node is
// matched by two rules of one pattern — but that is a property of today's file, not of the format.
$patterns   = [];
$rules      = [];
$skipped    = [];
$assertions = 0;

foreach ( $xpath->query( '//s:pattern' ) as $pattern ) {
	$pattern_rules = [];

	foreach ( $xpath->query( 's:rule', $pattern ) as $rule ) {
		$context     = trim( (string) $rule->getAttribute( 'context' ) );
		$rule_entry  = [ 'context' => context_to_select( translate_expression( $context ) ), 'assertions' => [] ];

		foreach ( $xpath->query( 's:assert | s:report', $rule ) as $assertion ) {
			$id      = trim( (string) $assertion->getAttribute( 'id' ) );
			$flag    = trim( (string) $assertion->getAttribute( 'flag' ) ) ?: 'warning';
			$test    = trim( (string) $assertion->getAttribute( 'test' ) );
			$message = trim( (string) preg_replace( '/\s+/', ' ', (string) $assertion->textContent ) );
			$type    = 'report' === $assertion->localName ? 'report' : 'assert';

			if ( '' === $id ) {
				// The artefact gives every assertion an id; one without is a change worth stopping for.
				fwrite( STDERR, "an assertion has no id (context {$context})\n" );
				exit( 1 );
			}

			[ $bindings, $body ] = extract_bindings( $test );

			$translated_test     = translate_expression( $body );
			$translated_bindings = [];
			foreach ( $bindings as $name => $sequence ) {
				$translated_bindings[ $name ] = translate_expression( $sequence );
			}

			$reason = untranslatable_reason( $rule_entry['context'] . ' ' . $translated_test . ' ' . implode( ' ', $translated_bindings ) );

			$entry = [
				'id'      => $id,
				'flag'    => $flag,
				'type'    => $type,
				'test'    => $translated_test,
				'message' => $message,
			];
			if ( [] !== $translated_bindings ) {
				$entry['bind'] = $translated_bindings;
			}
			if ( '' !== $reason ) {
				$entry['unsupported'] = $reason;
				$skipped[]            = $id . ' (' . $reason . ')';
			}

			$rule_entry['assertions'][] = $entry;
			$rules[]                    = $entry;
			$assertions++;
		}

		if ( [] !== $rule_entry['assertions'] ) {
			$pattern_rules[] = $rule_entry;
		}
	}

	$patterns[] = [
		'id'    => trim( (string) $pattern->getAttribute( 'id' ) ),
		'rules' => $pattern_rules,
	];
}

$export = static function ( array $value, int $indent ) use ( &$export ): string {
	$pad   = str_repeat( "\t", $indent );
	$inner = str_repeat( "\t", $indent + 1 );
	$parts = [];
	foreach ( $value as $key => $item ) {
		$name    = is_int( $key ) ? '' : "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $key ) . "' => ";
		$parts[] = $inner . $name . ( is_array( $item )
			? $export( $item, $indent + 1 )
			: "'" . str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], (string) $item ) . "'" ) . ',';
	}

	return "[\n" . implode( "\n", $parts ) . "\n" . $pad . ']';
};

$header = <<<'PHPDOC'
<?php
/**
 * EN 16931 rules for UBL, compiled from the official validation artefact. DO NOT EDIT BY HAND.
 *
 * Regenerate with:  php tools/en16931-compile-schematron.php --download
 * Check in CI with: php tools/en16931-compile-schematron.php --download --check
 *
 * The rule identifiers, the messages and the shape of every test come from the artefact named in
 * `artefact` below, which the European Commission publishes under the EUPL 1.2. The EUPL is listed
 * by its own Appendix as compatible with the GPL, which this plugin is distributed under. The XPath
 * is mechanically translated from XPath 2.0 to the XPath 1.0 libxml evaluates; the translations are
 * documented in the compiler and measured against the artefact's own test corpus.
 *
 * A rule carrying `unsupported` is NOT evaluated. It stays in the table so the coverage report can
 * name it: a rule that quietly disappears turns into a conformance claim nobody can check.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

PHPDOC;

$table = [
	'artefact'   => [
		'name'     => 'EN16931-UBL-validation-preprocessed.sch',
		'version'  => ARTEFACT_VERSION,
		'released' => ARTEFACT_RELEASED,
		'source'   => ARTEFACT_SOURCE,
		'licence'  => ARTEFACT_LICENCE,
	],
	'namespaces' => $namespaces,
	'patterns'   => $patterns,
];

$php = $header . "\nreturn " . $export( $table, 0 ) . ";\n";

$fatal       = count( array_filter( $rules, static fn( array $r ): bool => 'fatal' === $r['flag'] ) );
$business    = count( array_filter( $rules, static fn( array $r ): bool => ! str_starts_with( $r['id'], 'UBL-' ) ) );
$unsupported = count( array_filter( $rules, static fn( array $r ): bool => isset( $r['unsupported'] ) ) );

if ( isset( $options['check'] ) ) {
	$current = is_file( $out ) ? (string) file_get_contents( $out ) : '';
	if ( $current === $php ) {
		echo "rule table matches the artefact {$table['artefact']['version']} (" . count( $rules ) . " rules)\n";
		exit( 0 );
	}
	fwrite( STDERR, "the committed rule table differs from the artefact " . ARTEFACT_VERSION . "; regenerate it\n" );
	exit( 1 );
}

if ( ! is_dir( dirname( $out ) ) && ! mkdir( dirname( $out ), 0755, true ) ) {
	fwrite( STDERR, "cannot create " . dirname( $out ) . "\n" );
	exit( 1 );
}

file_put_contents( $out, $php );

printf(
	"artefact %s (%s)\nrules: %d (%d business, %d UBL syntax) · fatal: %d · not evaluated: %d\nwritten: %s\n",
	ARTEFACT_VERSION,
	ARTEFACT_RELEASED,
	count( $rules ),
	$business,
	count( $rules ) - $business,
	$fatal,
	$unsupported,
	$out
);

if ( [] !== $skipped ) {
	echo "not evaluated:\n  " . implode( "\n  ", array_unique( $skipped ) ) . "\n";
}

exit( 0 );
