<?php
/**
 * EN 16931 rule set: the compiled table, the engine, and the official conformance corpus.
 *
 * The corpus is the falsifier that matters. Every file in tests/fixtures/en16931-1.3.16 is a test set
 * the European Commission publishes, and each case declares its own expectation, so a translation
 * defect in tools/en16931-compile-schematron.php cannot be argued away against an assertion this
 * repository wrote itself.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Validation\SchematronValidator;
use WMCyber\Peppol\WooCommerce\WooCommerceInvoiceHook;

/** A minimal UBL invoice, so a case can say what it changes rather than carry 80 lines. */
function pp_ubl( string $body ): string {
	return '<?xml version="1.0" encoding="UTF-8"?>'
		. '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"'
		. ' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
		. ' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
		. $body
		. '</Invoice>';
}

pp_group( 'SCHEMATRON 1 · the shipped table is the pinned artefact and every rule in it is evaluated', static function (): void {
	$validator = new SchematronValidator();
	$artefact  = $validator->artefact();
	$coverage  = $validator->coverage();

	pp_assert( '1.3.16' === $artefact['version'] && '2026-04-13' === $artefact['released'] && 'EUPL-1.2' === $artefact['licence'], 'the table names the artefact release it was compiled from, so a reader can tell which rules ran' );
	pp_assert( str_contains( $artefact['source'], 'ConnectingEurope/eInvoicing-EN16931' ), 'and where it came from' );

	// 979 assertions: 223 EN 16931 business rules and 756 UBL syntax rules, measured on 1.3.16. The
	// specification of this plugin family says "the 40 mandatory Schematron rules of the EU"; the
	// artefact has five times that in business rules alone, and the number here is the artefact's.
	pp_assert( 979 === $coverage['total'] && 223 === $coverage['business'], 'the table holds every assertion of the artefact: 979, of which 223 are business rules (not the 40 the plugin specification rounds to)' );
	pp_assert( 979 === $coverage['evaluated'] && [] === $coverage['unsupported'], 'and the engine evaluates all of them; a rule it could not translate would be named here instead of silently dropped' );
	pp_assert( 281 === $coverage['fatal'], '281 of them are fatal' );
} );

pp_group( 'SCHEMATRON 2 · the official conformance corpus, case by case', static function (): void {
	$validator = new SchematronValidator();
	$root      = dirname( __DIR__ ) . '/fixtures/en16931-1.3.16';

	$cases    = 0;
	$matched  = 0;
	$rules    = [];
	$mismatch = [];

	foreach ( [ 'Invoice-unit-UBL', 'CreditNote-unit-UBL' ] as $directory ) {
		foreach ( glob( "{$root}/{$directory}/*.xml" ) ?: [] as $file ) {
			$set = new DOMDocument();
			if ( ! @$set->load( $file, LIBXML_NONET ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- reported as a mismatch below.
				$mismatch[] = basename( $file ) . ': not well-formed';
				continue;
			}

			$xpath = new DOMXPath( $set );
			$xpath->registerNamespace( 't', 'http://difi.no/xsd/vefa/validator/1.0' );

			foreach ( $xpath->query( '/t:testSet/t:test' ) as $test ) {
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

				$cases++;
				$problems = [];
				foreach ( $expect_fire as $id ) {
					$rules[ $id ] = true;
					if ( ! in_array( $id, $fired, true ) ) {
						$problems[] = "{$id} did not fire";
					}
				}
				foreach ( $expect_ok as $id ) {
					$rules[ $id ] = true;
					if ( in_array( $id, $fired, true ) ) {
						$problems[] = "{$id} fired on a valid case";
					}
				}

				if ( [] === $problems ) {
					$matched++;
				} else {
					$mismatch[] = basename( $file ) . ': ' . implode( '; ', $problems );
				}
			}
		}
	}

	pp_assert( $cases >= 1100 && count( $rules ) >= 200, "the corpus is present and complete: {$cases} cases over " . count( $rules ) . ' rules (the fixtures directory is not there or was thinned out if this drops)' );
	pp_assert( $matched === $cases, "every one of the {$cases} official cases matches the expectation the standard declares for it" . ( [] === $mismatch ? '' : ' — ' . implode( ' | ', array_slice( $mismatch, 0, 4 ) ) ) );
} );

pp_group( 'SCHEMATRON 3 · the official example invoices produce no fatal finding', static function (): void {
	$validator = new SchematronValidator();
	$root      = dirname( __DIR__ ) . '/fixtures/en16931-1.3.16/examples';

	$checked = 0;
	$guilty  = [];

	foreach ( array_merge( glob( "{$root}/*.xml" ) ?: [], glob( "{$root}/*.XML" ) ?: [] ) as $file ) {
		$result = $validator->validate_xml( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fixture in this repository.
		if ( $result instanceof WP_Error ) {
			$guilty[] = basename( $file ) . ': ' . $result->get_error_message();
			continue;
		}
		$checked++;
		if ( $result['fatal'] > 0 ) {
			$guilty[] = basename( $file ) . ': ' . implode( ',', array_unique( array_column( $result['findings'], 'id' ) ) );
		}
	}

	pp_assert( $checked >= 18, "the example invoices are present: {$checked}" );
	pp_assert( [] === $guilty, 'no official example invoice produces a fatal finding' . ( [] === $guilty ? '' : ': ' . implode( ' | ', $guilty ) ) );
} );

pp_group( 'SCHEMATRON 4 · the translations the corpus cannot reach, and the refusals', static function (): void {
	$validator = new SchematronValidator();

	// xs:boolean has four lexical forms. Every one of the 1129 official cases writes "true" or
	// "false"; ubl-tc434-example2.xml writes 0, and comparing only the words read that allowance as a
	// charge. Neither falsifier alone would have caught it.
	$with_digit = pp_ubl(
		'<cbc:ID>1</cbc:ID>'
		. '<cac:AllowanceCharge><cbc:ChargeIndicator>0</cbc:ChargeIndicator><cbc:Amount currencyID="EUR">100.00</cbc:Amount></cac:AllowanceCharge>'
		. '<cac:LegalMonetaryTotal><cbc:AllowanceTotalAmount currencyID="EUR">100.00</cbc:AllowanceTotalAmount></cac:LegalMonetaryTotal>'
	);
	$result = $validator->validate_xml( $with_digit );
	pp_assert( ! in_array( 'BR-CO-11', array_column( $result['findings'], 'id' ), true ), 'an allowance written <cbc:ChargeIndicator>0</cbc:ChargeIndicator> counts as an allowance, not as a charge (was: BR-CO-11 fired, because only the word "false" was compared)' );

	// A Schematron context is a match pattern: "cbc:Amount" means every cbc:Amount anywhere. Read as
	// a path from the root element it selects nothing, and all 23 BR-CL rules stopped firing while
	// the engine still counted them as evaluated.
	$bad_currency = pp_ubl( '<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="XXXX">1.00</cbc:PayableAmount></cac:LegalMonetaryTotal>' );
	$result       = $validator->validate_xml( $bad_currency );
	pp_assert( in_array( 'BR-CL-03', array_column( $result['findings'], 'id' ), true ), 'a rule whose context names a nested element fires on that element wherever it sits (was: contexts were read as paths from the root and 23 code-list rules never fired)' );

	// A date comparison must not become a comparison of two NaN.
	$backwards = pp_ubl( '<cac:InvoicePeriod><cbc:StartDate>2026-05-01</cbc:StartDate><cbc:EndDate>2026-04-01</cbc:EndDate></cac:InvoicePeriod>' );
	$ordered   = pp_ubl( '<cac:InvoicePeriod><cbc:StartDate>2026-04-01</cbc:StartDate><cbc:EndDate>2026-05-01</cbc:EndDate></cac:InvoicePeriod>' );
	pp_assert(
		in_array( 'BR-29', array_column( $validator->validate_xml( $backwards )['findings'], 'id' ), true )
			&& ! in_array( 'BR-29', array_column( $validator->validate_xml( $ordered )['findings'], 'id' ), true ),
		'an end date before its start date is caught and one after it is not (XPath 1.0 compares relational operands as NUMBERS, so two ISO dates compare as NaN and the rule would fire on everything)'
	);

	$not_xml = $validator->validate_xml( '<Invoice><unclosed>' );
	pp_assert( $not_xml instanceof WP_Error && 'wm_peppol_schematron_not_xml' === $not_xml->get_error_code(), 'a document that is not well-formed is refused before the rules, with its own error code' );
} );

pp_group( 'SCHEMATRON 5 · a rule the engine cannot evaluate is reported, never counted as satisfied', static function (): void {
	// The whole engine is worthless if "I could not evaluate this" reads as "the document satisfies
	// it": every violated rule would come back clean. This drives a table of two rules, one of which
	// carries an expression libxml refuses.
	$table = [
		'artefact'   => [ 'name' => 'test', 'version' => '0', 'released' => '2026-09-16', 'source' => 'test', 'licence' => 'test' ],
		'namespaces' => [ 'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' ],
		'patterns'   => [
			[
				'id'    => 'test',
				'rules' => [
					[
						'context'    => '//cbc:ID',
						'assertions' => [
							[ 'id' => 'X-BROKEN', 'flag' => 'fatal', 'type' => 'assert', 'test' => 'this is not xpath (((', 'message' => 'broken' ],
							[ 'id' => 'X-FAILS', 'flag' => 'fatal', 'type' => 'assert', 'test' => "normalize-space(.) = 'other'", 'message' => 'fails' ],
							[ 'id' => 'X-SKIPPED', 'flag' => 'fatal', 'type' => 'assert', 'test' => 'false()', 'message' => 'skipped', 'unsupported' => 'a construct the compiler does not know' ],
						],
					],
				],
			],
		],
	];

	$validator = new SchematronValidator( $table );
	$result    = $validator->validate_xml( pp_ubl( '<cbc:ID>1</cbc:ID>' ) );
	$fired     = array_column( $result['findings'], 'id' );

	pp_assert( in_array( 'X-BROKEN', $result['unevaluated'], true ) && ! in_array( 'X-BROKEN', $fired, true ), 'an expression libxml refuses is named in unevaluated, and is neither a finding nor a pass' );
	pp_assert( in_array( 'X-FAILS', $fired, true ) && ! in_array( 'X-FAILS', $result['unevaluated'], true ), 'a rule whose test is genuinely false is a finding, not an unevaluated one (DOMXPath answers false for both, and the libxml error buffer is what separates them)' );
	pp_assert( ! in_array( 'X-SKIPPED', $fired, true ) && ! in_array( 'X-SKIPPED', $result['unevaluated'], true ) && [ [ 'id' => 'X-SKIPPED', 'reason' => 'a construct the compiler does not know' ] ] === $validator->coverage()['unsupported'], 'a rule the compiler marked unsupported is not evaluated and is named by the coverage report' );

	// Schematron fires the first rule of a pattern whose context matches a node, not all of them.
	$ordered = $table;
	$ordered['patterns'][0]['rules'] = [
		[ 'context' => '//cbc:ID', 'assertions' => [ [ 'id' => 'FIRST', 'flag' => 'fatal', 'type' => 'assert', 'test' => 'false()', 'message' => 'first' ] ] ],
		[ 'context' => '//cbc:ID', 'assertions' => [ [ 'id' => 'SECOND', 'flag' => 'fatal', 'type' => 'assert', 'test' => 'false()', 'message' => 'second' ] ] ],
	];
	$fired = array_column( ( new SchematronValidator( $ordered ) )->validate_xml( pp_ubl( '<cbc:ID>1</cbc:ID>' ) )['findings'], 'id' );
	pp_assert( [ 'FIRST' ] === $fired, 'a node is offered to the first matching rule of a pattern only' );

	// Schematron has two kinds of assertion and they fire on OPPOSITE truth values: an <assert> fires
	// when its test is false, a <report> when it is true. Artefact 1.3.16 carries 979 asserts and no
	// report, so the shipped table cannot tell the two apart; the engine's contract still can.
	$reports = $table;
	$reports['patterns'][0]['rules'] = [
		[
			'context'    => '//cbc:ID',
			'assertions' => [
				[ 'id' => 'R-TRUE', 'flag' => 'fatal', 'type' => 'report', 'test' => 'true()', 'message' => 'reported' ],
				[ 'id' => 'R-FALSE', 'flag' => 'fatal', 'type' => 'report', 'test' => 'false()', 'message' => 'not reported' ],
				[ 'id' => 'A-TRUE', 'flag' => 'fatal', 'type' => 'assert', 'test' => 'true()', 'message' => 'satisfied' ],
			],
		],
	];
	$fired = array_column( ( new SchematronValidator( $reports ) )->validate_xml( pp_ubl( '<cbc:ID>1</cbc:ID>' ) )['findings'], 'id' );
	pp_assert( [ 'R-TRUE' ] === $fired, 'a report fires when its test is true and an assert when its test is false' );
} );

pp_group( 'SCHEMATRON 6 · each XPath 2.0 translation, on its own', static function (): void {
	// The corpus proves the translations TOGETHER. These pin them one at a time, so a regression names
	// the construct that broke instead of a count dropping from 1129.
	if ( ! defined( 'EN16931_COMPILER_LIBRARY_ONLY' ) ) {
		define( 'EN16931_COMPILER_LIBRARY_ONLY', true );
	}
	require_once dirname( __DIR__, 2 ) . '/tools/en16931-compile-schematron.php';

	$lower = 'abcdefghijklmnopqrstuvwxyz';
	$upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	pp_assert(
		"normalize-space(translate(cac:TaxScheme/cbc:ID, '{$lower}', '{$upper}'))" === translate_expression( 'cac:TaxScheme/normalize-space(upper-case(cbc:ID))' ),
		'a function call that sits as a path step is lifted out and the path pushed into its innermost argument (XPath 1.0 has no function step; libxml refuses the whole test and 52 rules stopped firing)'
	);
	pp_assert(
		"(normalize-space(cbc:ChargeIndicator) = 'false' or normalize-space(cbc:ChargeIndicator) = '0')" === translate_expression( 'cbc:ChargeIndicator=false()' ),
		'a comparison with a boolean literal becomes a comparison of the four xs:boolean lexical forms (XPath 1.0 would take the effective boolean value of the node-set, which is true exactly when the element is ABSENT)'
	);
	pp_assert(
		'//cbc:Amount | //cbc:TaxAmount' === context_to_select( 'cbc:Amount | cbc:TaxAmount' ) && '/ubl:Invoice' === context_to_select( '/ubl:Invoice' ),
		'a relative rule context becomes a match over the whole document, and an absolute one is left alone'
	);
	pp_assert(
		"number(translate(cbc:EndDate, '-', '')) >= number(translate(cbc:StartDate, '-', ''))" === translate_expression( 'xs:date(cbc:EndDate) >= xs:date(cbc:StartDate)' ),
		'a date cast becomes an integer that orders the same way (XPath 1.0 relational operators convert to number, so two ISO dates would compare as NaN)'
	);
	pp_assert(
		'sum((//cac:InvoiceLine | //cac:CreditNoteLine)/cbc:LineExtensionAmount)' === translate_expression( 'sum(//(cac:InvoiceLine|cac:CreditNoteLine)/xs:decimal(cbc:LineExtensionAmount))' ),
		'a parenthesised union step is distributed over its axis, and a numeric cast that sits as a step disappears rather than becoming number()'
	);
	pp_assert(
		"cac:TaxScheme[normalize-space(cbc:ID) = 'VAT']" === translate_expression( "cac:TaxScheme/(normalize-space(cbc:ID) = 'VAT')" ),
		'a parenthesised step that is not a union becomes a predicate'
	);
	pp_assert(
		'boolean(cbc:ID)' === translate_expression( 'exists(cbc:ID)' )
			&& 'substring(name(), string-length(name()) - string-length(\'Amount\') + 1) = \'Amount\'' === translate_expression( "ends-with(name(), 'Amount')" ),
		'exists() and ends-with() become their XPath 1.0 equivalents'
	);
	pp_assert(
		'number(cbc:Percent)' === translate_expression( 'xs:decimal(cbc:Percent)' )
			&& '((cbc:A - cbc:B) * (1 - 2 * ((cbc:A - cbc:B) < 0)))' === translate_expression( 'abs(cbc:A - cbc:B)' ),
		'a cast outside a path becomes number(), and abs() becomes the sign trick'
	);

	[ $bindings, $body ] = extract_bindings( 'every $rate in xs:decimal(cbc:Percent) satisfies (cbc:X = $rate)' );
	pp_assert( [ 'rate' => 'xs:decimal(cbc:Percent)' ] === $bindings && '(cbc:X = $rate)' === $body, 'a universal quantifier is split into a bound variable and the rest of the test, because XPath 1.0 cannot see the outer context from inside a predicate' );
} );

pp_group( 'SCHEMATRON 7 · the dispatch gate is off until the site asks for it', static function (): void {
	pp_reset();
	pp_assert( false === WooCommerceInvoiceHook::schematron_gates(), 'a fatal rule finding does not stop a dispatch by default: a site that dispatches today must not start failing on a rule its access point tolerates' );
	update_option( WooCommerceInvoiceHook::SCHEMATRON_GATE_OPTION, '1' );
	pp_assert( true === WooCommerceInvoiceHook::schematron_gates(), 'and it does once wm_peppol_schematron_gate is 1' );
	update_option( WooCommerceInvoiceHook::SCHEMATRON_GATE_OPTION, '0' );
} );
