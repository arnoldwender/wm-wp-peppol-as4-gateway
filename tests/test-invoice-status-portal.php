<?php
/**
 * Falsifier for the invoice verification portal.
 *
 * Measured on 2026-08-27: the portal's submit handler was preventDefault(), 500 ms of simulated
 * latency, then a fixed panel reading "E-Rechnung verifiziert & zugestellt" and "Die Rechnung
 * erfüllt zu 100% die Vorgaben nach EN 16931 und dem deutschen Wachstumschancengesetz".
 *
 * It printed that for ANY input, including an invoice number that does not exist. A conformance
 * oracle that always answered yes, on a plugin whose subject is statutory e-invoicing.
 *
 * Mutation checks:
 *   - answer 404 instead of a uniform 200 for an unknown invoice → case 4 fails.
 *   - drop the buyer_participant_id from the WHERE → case 3 fails.
 *
 * Run: wp eval-file wp-content/plugins/wm-peppol-as4-gateway/tests/test-invoice-status-portal.php --allow-root
 *
 * @package WMCyber\Peppol
 */

if ( ! defined( 'ABSPATH' ) ) {
	echo "Must run inside WordPress via WP-CLI.\n";
	exit( 1 );
}

// eval-file runs the file inside a function, so `global` would not bind for the helper below.
$GLOBALS['wm_p_passed'] = 0;
$GLOBALS['wm_p_failed'] = 0;

/**
 * Assert helper.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @return void
 */
function wm_p_assert( $condition, $label ) {
	if ( $condition ) {
		++$GLOBALS['wm_p_passed'];
		echo "  [PASS] {$label}\n";
	} else {
		++$GLOBALS['wm_p_failed'];
		echo "  [FAIL] {$label}\n";
	}
}

global $wpdb;
$invoices = $wpdb->prefix . 'wm_peppol_invoices';

echo "\n=== PEPPOL INVOICE STATUS PORTAL TEST ===\n\n";

// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DELETE FROM {$invoices} WHERE invoice_number LIKE 'PROBE-%'" );

// The endpoint rate-limits per address. Without this reset the suite passes once and then reports
// the limiter as a broken endpoint.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '_transient_wm_peppol_st_%'
	    OR option_name LIKE '_transient_timeout_wm_peppol_st_%'"
);
wp_cache_flush();

$now = current_time( 'mysql', true );
$wpdb->insert(
	$invoices,
	array(
		'invoice_number'        => 'PROBE-2026-0001',
		'buyer_participant_id'  => '0088:4260000000001',
		'seller_participant_id' => '0088:4260000000002',
		'issue_date'            => '2026-08-01',
		'currency_code'         => 'EUR',
		'total_payable_amount'  => 1190.00,
		'ubl_xml'               => '<Invoice/>',
		'xml_hash_sha256'       => str_repeat( 'a', 64 ),
		'status'                => 'draft',
		'created_at'            => $now,
		'updated_at'            => $now,
	)
);
// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * POST a status lookup through the real REST stack.
 *
 * @param array $body Payload.
 * @return WP_REST_Response
 */
function wm_p_post( array $body ) {
	$req = new WP_REST_Request( 'POST', '/wm-peppol/v1/invoice-status' );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	return rest_get_server()->dispatch( $req );
}

wp_set_current_user( 0 );

// --- Case 1: the route exists and is reachable without an account ----------
wm_p_assert( isset( rest_get_server()->get_routes()['/wm-peppol/v1/invoice-status'] ), 'the public status route is registered' );

// --- Case 2: a real invoice reports its REAL status ------------------------
$res  = wm_p_post( array( 'invoice_number' => 'PROBE-2026-0001', 'buyer_participant_id' => '0088:4260000000001' ) );
$data = $res->get_data();
wm_p_assert( 200 === $res->get_status(), 'a known invoice answers 200' );
wm_p_assert( true === ( $data['found'] ?? null ), 'it is reported as found' );
wm_p_assert( 'draft' === ( $data['invoice_status'] ?? '' ), 'the stored status is reported verbatim, not a fixed success' );
wm_p_assert( 'none' === ( $data['transport_status'] ?? '' ), 'no AS4 transmission is reported as none, not as delivered' );
wm_p_assert( false === ( $data['has_receipt'] ?? null ), 'no receipt is reported as absent — the word cryptographic is not used without one' );

// --- Case 3: the buyer id has to match -------------------------------------
$res  = wm_p_post( array( 'invoice_number' => 'PROBE-2026-0001', 'buyer_participant_id' => '0088:9999999999999' ) );
$data = $res->get_data();
wm_p_assert( false === ( $data['found'] ?? null ), 'the invoice number alone is not enough to read a status' );

// --- Case 4: an unknown invoice is not a success, and not an oracle --------
$res  = wm_p_post( array( 'invoice_number' => 'PROBE-DOES-NOT-EXIST', 'buyer_participant_id' => '0088:4260000000001' ) );
$data = $res->get_data();
wm_p_assert( false === ( $data['found'] ?? null ), 'an unknown invoice is reported as not found' );
wm_p_assert( 200 === $res->get_status(), 'and answers with the same status code as a hit — no enumeration oracle' );
wm_p_assert( ! isset( $data['invoice_status'] ), 'nothing about a nonexistent invoice is asserted' );

// --- Case 4b: a demo row is not reported ----------------------------------
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$wpdb->insert( $invoices, array( 'invoice_number' => 'PROBE-DEMO-0001', 'buyer_participant_id' => '0088:4260000000001', 'seller_participant_id' => '0088:4260000000002', 'issue_date' => '2026-08-01', 'currency_code' => 'EUR', 'total_payable_amount' => 1.00, 'ubl_xml' => '', 'xml_hash_sha256' => hash( 'sha256', '' ), 'status' => 'demo', 'created_at' => $now, 'updated_at' => $now ) );
$res  = wm_p_post( array( 'invoice_number' => 'PROBE-DEMO-0001', 'buyer_participant_id' => '0088:4260000000001' ) );
$data = $res->get_data();
wm_p_assert( false === ( $data['found'] ?? null ), 'a demo row is reported as not found' );

// --- Case 5: missing parameters are refused --------------------------------
$res = wm_p_post( array( 'invoice_number' => 'PROBE-2026-0001' ) );
wm_p_assert( 400 === $res->get_status(), 'a lookup without the buyer id is refused' );

// --- Case 6: the portal actually transmits, and gates on the answer --------
$portal = file_get_contents( WM_PEPPOL_PATH . 'includes/Frontend/InvoicePortal.php' );
wm_p_assert( false !== strpos( $portal, 'fetch(' ), 'the portal performs a real request' );

// Strip comments first. The replacement quotes the old wording so the history stays readable, and
// a plain search matches that comment — the same broken-instrument trap this whole session keeps
// hitting. What matters is whether the strings are still SHIPPED to the visitor.
$portal_code = implode(
	"\n",
	array_filter(
		file( WM_PEPPOL_PATH . 'includes/Frontend/InvoicePortal.php', FILE_IGNORE_NEW_LINES ),
		static function ( $line ) {
			$t = ltrim( $line );
			return '' !== $t && 0 !== strpos( $t, '*' ) && 0 !== strpos( $t, '//' ) && 0 !== strpos( $t, '/*' );
		}
	)
);
wm_p_assert( false === strpos( $portal_code, 'zu 100%' ), 'the fixed EN 16931 conformance claim is no longer shipped' );
wm_p_assert( false === strpos( $portal_code, 'verifiziert & zugestellt' ), 'the fixed "verified & delivered" headline is no longer shipped' );

// --- Teardown -------------------------------------------------------------
$wpdb->query( "DELETE FROM {$invoices} WHERE invoice_number LIKE 'PROBE-%'" );

echo "\n=== RESULT: {$GLOBALS['wm_p_passed']} passed, {$GLOBALS['wm_p_failed']} failed ===\n\n";
