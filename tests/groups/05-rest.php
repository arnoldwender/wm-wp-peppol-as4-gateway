<?php
/**
 * REST routes of wm-peppol/v1: declared arguments (D20), /invoices/generate (D19), the public status route with
 * demo rows (D18), /lookup (D2).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\API\RestController;
use WMCyber\Peppol\Discovery\SmpClient;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

function pp_rest_request( string $body, bool $json = true ): WP_REST_Request {
	$request = new WP_REST_Request( 'POST' );
	if ( $json ) {
		$request->set_header( 'Content-Type', 'application/json' );
	}
	$request->set_body( $body );
	return $request;
}

function pp_rest_outcome( $result ): string {
	return $result instanceof WP_Error ? $result->get_error_code() . ':' . ( $result->get_error_data()['status'] ?? '' ) : 'response:' . $result->get_status();
}

pp_group( 'REST 1 · every route declares its arguments and its permission', static function (): void {
	pp_reset();
	( new RestController( new UblInvoiceGenerator(), new SmpClient() ) )->register_routes();
	do_action( 'rest_api_init' );
	$routes = $GLOBALS['wp_test']['routes'];
	$status = $routes['/wm-peppol/v1/invoice-status']['args'] ?? [];

	pp_assert( 'string' === ( $status['invoice_number']['type'] ?? '' ) && true === ( $status['invoice_number']['required'] ?? false ) && 'string' === ( $status['buyer_participant_id']['type'] ?? '' ) && 'rest_validate_request_arg' === ( $status['buyer_participant_id']['validate_callback'] ?? '' ), 'the public status route types both parameters as validated strings (was: none, an array became "Array")' );
	$status_perm = $routes['/wm-peppol/v1/invoice-status']['permission_callback'] ?? null;
	pp_assert( is_callable( $status_perm ) && is_array( $status_perm ) && 'check_public_invoice_lookup_access' === ( $status_perm[1] ?? '' ), 'the public status route uses check_public_invoice_lookup_access instead of __return_true' );
	$GLOBALS['wp_test']['can'] = false;
	$_SERVER['REMOTE_ADDR']    = '198.51.100.42';
	add_filter( 'wm_peppol_rest_rate_limit', static fn(): int => 2 );
	$first_gate  = $status_perm( new WP_REST_Request( 'POST' ) );
	$second_gate = $status_perm( new WP_REST_Request( 'POST' ) );
	$third_gate  = $status_perm( new WP_REST_Request( 'POST' ) );
	$rl_keys     = array_keys( $GLOBALS['wp_test']['transients'] );
	pp_assert( true === $first_gate && true === $second_gate && $third_gate instanceof WP_Error && 'wm_peppol_rate_limited' === $third_gate->get_error_code() && 429 === ( $third_gate->get_error_data()['status'] ?? 0 ), 'visitors can access /invoice-status up to wm_peppol_rest_rate_limit and get 429 afterwards' );
	pp_assert( 1 === count( $rl_keys ) && str_starts_with( $rl_keys[0], 'wm_peppol_rl_' ) && ! str_contains( $rl_keys[0], '198.51.100.42' ), 'rate-limit transients store HMAC hashes, never raw IP addresses' );
	$GLOBALS['wp_test']['can'] = true;
	pp_assert( true === $status_perm( new WP_REST_Request( 'POST' ) ), 'manage_options bypasses the public route rate limit' );
	unset( $_SERVER['REMOTE_ADDR'], $GLOBALS['wp_test']['actions']['wm_peppol_rest_rate_limit'] );
	$GLOBALS['wp_test']['transients'] = [];
	pp_assert( 'string' === ( $routes['/wm-peppol/v1/lookup']['args']['participant_id']['type'] ?? '' ) && 'integer' === ( $routes['/wm-peppol/v1/invoices']['args']['limit']['type'] ?? '' ), '/lookup and /invoices declare theirs' );
	// A parameter the handler defaults must not be declared required, or WordPress refuses the request
	// before the handler runs and the default is unreachable code. Measured on a local WordPress install on 2026-09-16:
	// POST /lookup with only participant_id answered 400 rest_missing_callback_param. This harness calls
	// the callbacks directly and never enforces `required`, so the contract is asserted on the
	// declaration instead.
	$lookup_args = $routes['/wm-peppol/v1/lookup']['args'] ?? [];
	pp_assert( true === ( $lookup_args['participant_id']['required'] ?? null ) && false === ( $lookup_args['document_type']['required'] ?? null ), '/lookup requires the participant id and leaves document_type optional, which is the only way its default can run (was: required, so every caller without it got rest_missing_callback_param)' );

	foreach ( [ '/wm-peppol/v1/invoices', '/wm-peppol/v1/invoices/generate', '/wm-peppol/v1/lookup' ] as $route ) {
		$GLOBALS['wp_test']['can'] = false;
		$denied                    = ! ( $routes[ $route ]['permission_callback'] )();
		$GLOBALS['wp_test']['can'] = true;
		pp_assert( $denied, "{$route} needs manage_options" );
	}
} );

pp_group( 'REST 2 · /invoices/generate stores the document it built, or says why not', static function (): void {
	pp_reset();
	global $wpdb;
	$controller = new RestController( new UblInvoiceGenerator(), new SmpClient() );

	pp_assert( 'wm_peppol_invalid_body:400' === pp_rest_outcome( $controller->generate_invoice( pp_rest_request( 'not json' ) ) ), 'a body that is not JSON answers 400 (was: TypeError, null given)' );
	pp_assert( 'wm_peppol_invalid_body:400' === pp_rest_outcome( $controller->generate_invoice( pp_rest_request( '[1,2]' ) ) ), 'a JSON list answers 400' );
	$missing = $controller->generate_invoice( pp_rest_request( '{"invoice_number":"R-1","payable_amount":100}' ) );
	pp_assert( 'wm_peppol_invoice_data:400' === pp_rest_outcome( $missing ) && in_array( 'buyer.endpoint', (array) ( $missing->get_error_data()['problems'] ?? [] ), true ), 'missing parties answer 400 with the problem codes (was: stored with buyer 9930:DE987654321)' );
	pp_assert( [] === $wpdb->rows( 'wp_wm_peppol_invoices' ), 'nothing was stored for the refused requests' );

	$recorded = [];
	add_action( 'wm_peppol_invoice_recorded', static function ( int $id, string $num, string $src ) use ( &$recorded ): void {
		$recorded[] = [ $id, $num, $src ];
	}, 10, 3 );
	$data    = pp_invoice( [ 'invoice_number' => 'R-2', 'lines' => [ [ 'name' => 'A', 'quantity' => 5, 'unit_price' => 100, 'vat_rate' => 19 ] ] ] );
	$created = $controller->generate_invoice( pp_rest_request( (string) json_encode( $data ) ) );
	$rows    = $wpdb->rows( 'wp_wm_peppol_invoices' );
	pp_assert( 'response:201' === pp_rest_outcome( $created ) && 1 === count( $rows ), 'a complete body answers 201 and stores one row' );
	pp_assert( 1 === count( $recorded ) && 'R-2' === $recorded[0][1] && 'rest' === $recorded[0][2], 'wm_peppol_invoice_recorded fires when an invoice is stored' );
	pp_assert( '595.00' === number_format( (float) ( $rows[0]['total_payable_amount'] ?? 0 ), 2, '.', '' ) && '595.00' === pp_text( pp_xpath( (string) ( $rows[0]['ubl_xml'] ?? '' ) ), '//cac:LegalMonetaryTotal/cbc:PayableAmount' ), 'the stored total is the payable amount of the stored document (was: 100.00 next to an XML with 595.00)' );
	pp_assert( hash( 'sha256', (string) $rows[0]['ubl_xml'] ) === $rows[0]['xml_hash_sha256'] && '0088:4000000000001' === $rows[0]['buyer_participant_id'] && 'generated' === $rows[0]['status'], 'hash, buyer id and status belong to that document' );
	pp_assert( abs( (int) strtotime( $rows[0]['created_at'] . ' UTC' ) - time() ) < 120, 'created_at is GMT' );
	pp_assert( 'wm_peppol_duplicate_invoice:409' === pp_rest_outcome( $controller->generate_invoice( pp_rest_request( (string) json_encode( $data ) ) ) ), 'the same invoice number again answers 409' );

	$wpdb->fail_next_insert = true;
	pp_assert( 'wm_peppol_insert_failed:500' === pp_rest_outcome( $controller->generate_invoice( pp_rest_request( (string) json_encode( pp_invoice( [ 'invoice_number' => 'R-3' ] ) ) ) ) ), 'a failed insert answers 500 (was: 201 with success true)' );

	// A malformed identifier is answered before any DNS query: the suite makes no network request here.
	$lookup = new WP_REST_Request( 'POST' );
	$lookup->set_param( 'participant_id', 'DE111222333' );
	pp_assert( 'wm_peppol_smp_bad_identifier:400' === pp_rest_outcome( $controller->lookup_participant( $lookup ) ), '/lookup answers 400 for an identifier that is not <EAS>:<value> (was: 501 for everything, and before that a fixed access point for any id)' );

	$empty = new WP_REST_Request( 'POST' );
	$empty->set_param( 'participant_id', '' );
	pp_assert( 'wm_peppol_smp_bad_identifier:400' === pp_rest_outcome( $controller->lookup_participant( $empty ) ), '/lookup answers 400 for an empty identifier' );
} );

pp_group( 'REST 3 · the public status route reports stored invoices, not demo rows', static function (): void {
	pp_reset();
	global $wpdb;
	$row = static fn( string $number, string $status, string $xml ): array => [
		'invoice_number'        => $number,
		'buyer_participant_id'  => '0088:4000000000001',
		'seller_participant_id' => '9930:DE111222333',
		'issue_date'            => '2026-09-15',
		'currency_code'         => 'EUR',
		'total_payable_amount'  => '119.00',
		'ubl_xml'               => $xml,
		'xml_hash_sha256'       => hash( 'sha256', $xml ),
		'status'                => $status,
		'created_at'            => '2026-09-15 10:00:00',
		'updated_at'            => '2026-09-15 10:00:00',
	];
	$wpdb->insert( 'wp_wm_peppol_invoices', $row( 'S-1', 'generated', '<Invoice/>' ) );
	$wpdb->insert( 'wp_wm_peppol_invoices', $row( 'DEMO-2026-0001', 'demo', '' ) );

	$controller = new RestController( new UblInvoiceGenerator(), new SmpClient() );
	$ask        = static function ( string $number ) use ( $controller ): array {
		$request = new WP_REST_Request( 'POST' );
		$request->set_param( 'invoice_number', $number );
		$request->set_param( 'buyer_participant_id', '0088:4000000000001' );
		$result = $controller->invoice_status( $request );
		return $result instanceof WP_REST_Response ? (array) $result->get_data() : [ 'error' => $result->get_error_code() ];
	};

	$real = $ask( 'S-1' );
	pp_assert( true === ( $real['found'] ?? null ) && 'generated' === ( $real['invoice_status'] ?? '' ) && 'none' === ( $real['transport_status'] ?? '' ) && false === ( $real['has_receipt'] ?? null ), 'a stored invoice reports its stored status, no transmission and no receipt' );
	pp_assert( false === ( $ask( 'DEMO-2026-0001' )['found'] ?? null ), 'a demo row is not reported' );
	$wpdb->insert( 'wp_wm_peppol_invoices', $row( 'INV-2026-9041', 'delivered', '<Invoice><ID>INV-2026-9041</ID></Invoice>' ) );
	pp_assert( false === ( $ask( 'INV-2026-9041' )['found'] ?? null ), 'a stub row of the old demo button is not reported as delivered (was: "delivered" for INV-2026-9041 on a local WordPress install and the Box)' );

	$source = pp_code( 'includes/API/RestController.php' );
	pp_assert( str_contains( $source, 'später' ) && str_contains( $source, 'Käufer-Peppol-ID' ) && ! str_contains( $source, 'spaeter' ), 'German messages carry their umlauts' );

	$last = [];
	for ( $i = 0; $i < 25; $i++ ) {
		$last = $ask( 'S-1' );
	}
	pp_assert( 'wm_peppol_rate_limited' === ( $last['error'] ?? '' ), 'more than 20 lookups from one address in an hour are refused' );
} );
