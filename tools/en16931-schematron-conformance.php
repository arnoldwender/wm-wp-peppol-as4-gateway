<?php
/**
 * Runs the official EN 16931 test corpus against this plugin's Schematron engine.
 *
 * Every file of test/Invoice-unit-UBL and test/CreditNote-unit-UBL in the artefact repository is a
 * <testSet> of cases, and each case declares its own expectation: <success>BR-xx</success> means the
 * embedded document must NOT produce that rule, <error>BR-xx</error> means it must. The expectation
 * comes from the standard, not from this repository, which is the whole point: a translation defect
 * in tools/en16931-compile-schematron.php cannot be argued away against a falsifier someone else wrote.
 *
 * Usage:
 *   php tools/en16931-schematron-conformance.php --corpus=<dir of the artefact checkout>
 *   php tools/en16931-schematron-conformance.php --corpus=<dir> --rule=BR-CO-10   (one rule, verbose)
 *   php tools/en16931-schematron-conformance.php --corpus=<dir> --min=100         (fails under N% passing)
 *
 * Exit: 0 when every case matched its declared expectation (or the --min share did); 1 otherwise.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! function_exists( '__' ) ) {
	/**
	 * The engine translates its two error strings; outside WordPress they pass through.
	 */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the WordPress function, stubbed for a CLI tool.
		unset( $domain );

		return $text;
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in: the tool only asks whether an error came back and what it says.
	 */
	class WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- the WordPress class, stubbed for a CLI tool.
		public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
		public function get_error_code(): string {
			return $this->code;
		}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/Validation/SchematronValidator.php';

use WMCyber\Peppol\Validation\SchematronValidator;

const TESTSET_NS = 'http://difi.no/xsd/vefa/validator/1.0';

$options = getopt( '', [ 'corpus:', 'rule:', 'min:', 'quiet' ] );
$corpus  = $options['corpus'] ?? null;
$only    = $options['rule'] ?? null;
$minimum = isset( $options['min'] ) ? (float) $options['min'] : 100.0;
$quiet   = isset( $options['quiet'] );

if ( null === $corpus ) {
	fwrite( STDERR, "usage: php tools/en16931-schematron-conformance.php --corpus=<dir> [--rule=BR-xx] [--min=100]\n" );
	exit( 1 );
}

$directories = [];
foreach ( [ 'test/Invoice-unit-UBL', 'test/CreditNote-unit-UBL', 'Invoice-unit-UBL', 'CreditNote-unit-UBL' ] as $candidate ) {
	$path = rtrim( $corpus, '/' ) . '/' . $candidate;
	if ( is_dir( $path ) ) {
		$directories[] = $path;
	}
}

if ( [] === $directories ) {
	fwrite( STDERR, "no Invoice-unit-UBL or CreditNote-unit-UBL under {$corpus}\n" );
	exit( 1 );
}

$validator = new SchematronValidator();
$artefact  = $validator->artefact();
$coverage  = $validator->coverage();

$cases        = 0;
$passed       = 0;
$failures     = [];
$unevaluated  = [];
$rules_seen   = [];
$rules_failed = [];

foreach ( $directories as $directory ) {
	$files = glob( $directory . '/*.xml' ) ?: [];
	sort( $files );

	foreach ( $files as $file ) {
		$rule = basename( $file, '.xml' );
		if ( null !== $only && $rule !== $only ) {
			continue;
		}

		$set = new DOMDocument();
		if ( ! @$set->load( $file, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corpus file that does not parse is reported below.
			$failures[] = [ $rule, basename( $file ), 'the test set is not well-formed XML', '', '' ];
			continue;
		}

		$xpath = new DOMXPath( $set );
		$xpath->registerNamespace( 't', TESTSET_NS );

		foreach ( $xpath->query( '/t:testSet/t:test' ) as $index => $test ) {
			// The expectation: <success>ID</success> means the rule must not fire, <error>ID</error>
			// means it must. A case may name several.
			$expect_ok   = [];
			$expect_fire = [];
			foreach ( $xpath->query( 't:assert/t:success', $test ) as $node ) {
				$expect_ok[] = trim( (string) $node->textContent );
			}
			foreach ( $xpath->query( 't:assert/t:error', $test ) as $node ) {
				$expect_fire[] = trim( (string) $node->textContent );
			}
			if ( [] === $expect_ok && [] === $expect_fire ) {
				continue;
			}

			// The document under test is the one element child that is not the <assert>.
			$document = null;
			foreach ( $test->childNodes as $child ) {
				if ( $child instanceof DOMElement && 'assert' !== $child->localName ) {
					$document = $child;
					break;
				}
			}
			if ( null === $document ) {
				continue;
			}

			$carrier = new DOMDocument( '1.0', 'UTF-8' );
			$carrier->appendChild( $carrier->importNode( $document, true ) );

			$result = $validator->validate_document( $carrier );
			$fired  = array_column( $result['findings'], 'id' );

			foreach ( $result['unevaluated'] as $id ) {
				$unevaluated[ $id ] = ( $unevaluated[ $id ] ?? 0 ) + 1;
			}

			foreach ( array_merge( $expect_ok, $expect_fire ) as $id ) {
				$rules_seen[ $id ] = true;
			}

			$cases++;
			$problems = [];
			foreach ( $expect_fire as $id ) {
				if ( ! in_array( $id, $fired, true ) ) {
					$problems[]           = "{$id} did not fire";
					$rules_failed[ $id ]  = true;
				}
			}
			foreach ( $expect_ok as $id ) {
				if ( in_array( $id, $fired, true ) ) {
					$problems[]          = "{$id} fired although the case is valid";
					$rules_failed[ $id ] = true;
				}
			}

			if ( [] === $problems ) {
				$passed++;
				if ( null !== $only && ! $quiet ) {
					printf( "  case %d OK (%s)\n", (int) $index + 1, [] !== $expect_fire ? 'error expected' : 'success expected' );
				}
				continue;
			}

			$description = '';
			$node        = $xpath->query( 't:assert/t:description', $test )->item( 0 );
			if ( $node instanceof DOMNode ) {
				$description = trim( (string) $node->textContent );
			}

			$failures[] = [ $rule, basename( $file ), implode( '; ', $problems ), $description, implode( ',', array_slice( $fired, 0, 6 ) ) ];
		}
	}
}

$share = $cases > 0 ? ( 100 * $passed / $cases ) : 0.0;

printf(
	"artefact %s (%s), %d assertions in the table, %d evaluated\n",
	$artefact['version'],
	$artefact['released'],
	$coverage['total'],
	$coverage['evaluated']
);
printf( "official corpus: %d cases over %d rules · passed %d (%.2f%%)\n", $cases, count( $rules_seen ), $passed, $share );

if ( [] !== $unevaluated ) {
	printf( "expressions this engine could not evaluate: %d rules\n", count( $unevaluated ) );
	arsort( $unevaluated );
	foreach ( array_slice( $unevaluated, 0, 20, true ) as $id => $count ) {
		printf( "  %-14s %d cases\n", $id, $count );
	}
}

if ( [] !== $failures && ! $quiet ) {
	printf( "\n%d case(s) did not match the declared expectation, over %d rules:\n", count( $failures ), count( $rules_failed ) );
	foreach ( array_slice( $failures, 0, 40 ) as [ $rule, $file, $problem, $description, $fired ] ) {
		printf( "  %-14s %s\n      %s\n", $rule, $problem, $description );
		if ( '' !== $fired ) {
			printf( "      fired: %s\n", $fired );
		}
	}
	if ( count( $failures ) > 40 ) {
		printf( "  ... and %d more\n", count( $failures ) - 40 );
	}
	printf( "\nrules with at least one mismatch: %s\n", implode( ' ', array_keys( $rules_failed ) ) );
}

exit( $share + 0.0001 >= $minimum ? 0 : 1 );
