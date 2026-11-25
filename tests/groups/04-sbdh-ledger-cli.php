<?php
/**
 * SBDH packaging, the HMAC ledger (loaded, not wired to the plugin's tables) and the WP-CLI commands
 * (D3 "conformant" message, D24 CSV cells, D2 lookup, D15 generate-ubl).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\CLI\PeppolCommand;

pp_group( 'SBDH · envelope and participant ids', static function (): void {
	pp_reset();
	$xml     = pp_build( pp_invoice() )['xml'];
	$package = WM_Peppol_AS4_Client::package_sbdh( $xml, '9930:DE111222333', '0088:4000000000001' );
	pp_assert( str_contains( $package['sbdh_xml'], '<HeaderVersion>1.3</HeaderVersion>' ) && hash( 'sha256', $xml ) === $package['payload_hash'], 'SBDH header 1.3 and the SHA-256 of the payload' );
	pp_assert( WM_Peppol_AS4_Client::is_valid_peppol_id( '0204:04011000-1234512345-06' ) && ! WM_Peppol_AS4_Client::is_valid_peppol_id( 'invalid-id' ), 'participant id syntax is checked' );
} );

pp_group( 'LEDGER · the HMAC chain works on its own table and is not wired to the plugin tables', static function (): void {
	pp_reset();
	pp_assert( false === WM_Peppol_Database::record_invoice( [ 'invoice_number' => 'L-0', 'gross_amount' => 119.0, 'payload_hash' => hash( 'sha256', 'x' ) ] ), 'on the table the plugin creates, record_invoice() fails: the ledger columns are not there' );

	$GLOBALS['wpdb'] = new Test_WPDB();
	WM_Peppol_Database::create_tables();
	foreach ( [ 'L-1' => 119.00, 'L-2' => 238.00 ] as $number => $gross ) {
		WM_Peppol_Database::record_invoice( [ 'invoice_number' => $number, 'direction' => 'OUTBOUND', 'gross_amount' => $gross, 'payload_hash' => hash( 'sha256', $number ) ] );
	}
	$chain = WM_Peppol_Database::verify_chain();
	pp_assert( $chain['valid'] && 2 === $chain['checked'], 'on its own table two chained rows verify' );

	$GLOBALS['wpdb']->query( "UPDATE wp_wm_peppol_invoices SET gross_amount = '9999.99' WHERE id = 1" );
	$tampered = WM_Peppol_Database::verify_chain();
	pp_assert( ! $tampered['valid'] && 1 === $tampered['broken_at_id'], 'a changed amount breaks the chain at row 1' );
	pp_assert( "'=SUM(A1)" === WM_Peppol_Database::sanitize_csv_cell( '=SUM(A1)' ) && 'Rechnung 1' === WM_Peppol_Database::sanitize_csv_cell( 'Rechnung 1' ), 'a formula prefix is neutralised, plain text is kept' );
} );

pp_group( 'WP-CLI · list, validate, lookup, generate-ubl, status', static function (): void {
	pp_reset();
	global $wpdb;
	$wpdb->insert(
		$wpdb->prefix . 'wm_peppol_invoices',
		[
			'invoice_number'        => 'C-1',
			'buyer_participant_id'  => '=HYPERLINK("x")',
			'seller_participant_id' => '9930:DE111222333',
			'issue_date'            => '2026-09-15',
			'currency_code'         => 'EUR',
			'total_payable_amount'  => '10.00',
			'ubl_xml'               => '',
			'xml_hash_sha256'       => str_repeat( 'a', 64 ),
			'status'                => 'generated',
			'created_at'            => '2026-09-15 10:00:00',
			'updated_at'            => '2026-09-15 10:00:00',
		]
	);

	$cli = new PeppolCommand();
	$cli->list_invoices( [], [ 'format' => 'csv' ] );
	pp_assert( "'=HYPERLINK(\"x\")" === ( $GLOBALS['wp_test']['cli_items']['items'][0]['buyer_participant_id'] ?? null ), 'wp peppol list --format=csv prefixes a formula cell (was: passed through)' );
	$cli->list_invoices( [], [ 'format' => 'json' ] );
	pp_assert( '=HYPERLINK("x")' === ( $GLOBALS['wp_test']['cli_items']['items'][0]['buyer_participant_id'] ?? null ), 'other formats keep the stored value' );

	$file = WP_CONTENT_DIR . '/cli-invoice.xml';
	file_put_contents( $file, pp_build( pp_invoice() )['xml'] );
	WP_CLI::$out = [];
	$cli->validate( [ $file ], [] );
	$last = WP_CLI::$out[ count( WP_CLI::$out ) - 1 ] ?? [ '', '' ];
	pp_assert( 'success' === $last[0] && ! str_contains( $last[1], 'conformant' ) && str_contains( $last[1], 'no schema or Schematron' ), 'validate names the checks that passed and does not call the file conformant (was: "is EN 16931 conformant")' );

	file_put_contents( $file, '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>' );
	$exited = false;
	try {
		$cli->validate( [ $file ], [] );
	} catch ( Test_CLI_Exit $e ) {
		$exited = true;
	}
	pp_assert( $exited, 'an invoice without content ends in WP_CLI::error' );

	// An identifier without the four-digit EAS scheme is refused before any DNS query, so the suite
	// makes no network request; the DNS and SMP paths are exercised in group 03 with an injected resolver.
	$said = '';
	try {
		$cli->lookup( [ 'DE111222333' ], [] );
	} catch ( Test_CLI_Exit $e ) {
		$said = $e->getMessage();
	}
	pp_assert( str_contains( $said, 'wm_peppol_smp_bad_identifier' ) && str_contains( $said, 'EAS-Schema' ), 'wp peppol lookup reports the error code and what is wrong with the identifier (was: "Receiver discovery successful." for any id)' );
	pp_assert( ! str_contains( $said, 'nicht implementiert' ), 'the command no longer says discovery is not implemented' );

	$said = '';
	try {
		$cli->generate_ubl( [ 'C-2' ], [ 'amount' => '119.00' ] );
	} catch ( Test_CLI_Exit $e ) {
		$said = $e->getMessage();
	}
	pp_assert( str_contains( $said, 'Verkäufers' ) && str_contains( $said, 'Käuferreferenz' ), 'generate-ubl with only an amount names the missing data (was: a document with placeholder parties)' );

	pp_seller_options();
	$data = WP_CONTENT_DIR . '/cli-data.json';
	file_put_contents( $data, (string) json_encode( [ 'buyer' => pp_buyer(), 'buyer_reference' => 'PO-7', 'line_description' => 'Leistung' ] ) );
	$out = WP_CONTENT_DIR . '/cli-out.xml';
	$cli->generate_ubl( [ 'C-3' ], [ 'data' => $data, 'amount' => '119.00', 'output' => $out ] );
	pp_assert( is_file( $out ) && WM_Peppol_Validator::validate( (string) file_get_contents( $out ) )['valid'], 'generate-ubl with --data writes a document that passes the checks' );

	WP_CLI::$out = [];
	$cli->status( [], [] );
	$lines = implode( "\n", array_column( WP_CLI::$out, 1 ) );
	pp_assert( str_contains( $lines, "seller data:              complete" ) && str_contains( $lines, 'not configured, nothing is sent' ) && str_contains( $lines, 'SMP discovery:            SML zone participant.sml.prod.tech.peppol.org' ), 'status reports seller data, dispatch and the SML zone discovery would ask (was: "not implemented", a sentence about a stub that is gone)' );
} );
