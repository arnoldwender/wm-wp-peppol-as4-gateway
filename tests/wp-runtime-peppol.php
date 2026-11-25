<?php
/**
 * Runtime check of wm-peppol-as4-gateway inside a real WordPress. Writes nothing.
 *
 * Reads the loaded classes, the admin page and widget output, the portal markup, the generator and validator
 * behaviour, the REST route declarations and the /lookup refusal, the hub adapter and the compiled de_DE catalog.
 * The public status route is not called (it counts requests in a transient) and no invoice is stored. The
 * plugin's options and transients and the invoice row count are compared before and after; the check exits 1
 * when one changed or an assertion failed.
 *
 *   wp eval-file wp-content/plugins/wm-peppol-as4-gateway/tests/wp-runtime-peppol.php --user=1
 *   ssh <box> 'wp --path=<www> eval-file - --user=1' < tests/wp-runtime-peppol.php
 *
 * `wp eval-file` runs the file inside a method: state lives in $GLOBALS.
 *
 * @package WMCyber\Peppol
 */

defined( 'ABSPATH' ) || exit;

use WMCyber\Peppol\Admin\AdminDashboard;
use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Core\Plugin;
use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\Frontend\InvoicePortal;
use WMCyber\Peppol\UBL\InvoiceDataException;

if ( ! class_exists( Settings::class ) || ! method_exists( 'WMCyber\Peppol\UBL\UblInvoiceGenerator', 'build' ) ) {
	WP_CLI::error( 'wm-peppol-as4-gateway with the reader-pass fixes (Core\Settings, UblInvoiceGenerator::build) is not loaded.' );
}

global $wpdb;
$GLOBALS['pprt']       = [ 'pass' => 0, 'fail' => 0 ];
$GLOBALS['pprt_check'] = static function ( bool $ok, string $label ): void {
	$GLOBALS['pprt'][ $ok ? 'pass' : 'fail' ]++;
	echo ( $ok ? '  [PASS] ' : '  [FAIL] ' ) . $label . "\n";
};
$GLOBALS['pprt_state'] = static function (): array {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$options = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ORDER BY option_name', $wpdb->options, $wpdb->esc_like( 'wm_peppol' ) . '%', $wpdb->esc_like( '_transient_wm_peppol' ) . '%', $wpdb->esc_like( '_transient_timeout_wm_peppol' ) . '%' ), ARRAY_A );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wm_peppol_invoices' ) );
	return [ $options, $rows ];
};
$GLOBALS['pprt_before'] = $GLOBALS['pprt_state']();

register_shutdown_function(
	static function (): void {
		$same = $GLOBALS['pprt_state']() === $GLOBALS['pprt_before'];
		echo "\n  STORAGE CHECK: " . ( $same ? 'no plugin option, transient or invoice row written' : 'DIFFERENT' ) . "\n";
		echo "  RUNTIME RESULTS: {$GLOBALS['pprt']['pass']} PASSED | {$GLOBALS['pprt']['fail']} FAILED\n";
		exit( $same && 0 === $GLOBALS['pprt']['fail'] ? 0 : 1 );
	}
);

$check = $GLOBALS['pprt_check'];
wp_set_current_user( 1 );
echo "\n=== WM PEPPOL AS4 GATEWAY · RUNTIME ===\n";

// --- Loaded code ------------------------------------------------------------------------------------------------------
$check( ! class_exists( 'WM_Peppol_Ubl_Builder' ) && ! class_exists( 'WM_Peppol_CLI' ) && ! class_exists( 'WMCyber\Peppol\Security\DirectoryProtector' ), 'the second order flow, the unloaded CLI class and the per-request directory writer are gone' );
$check( ! has_action( 'woocommerce_order_status_completed', [ WM_Peppol_Gateway::get_instance(), 'process_order_for_peppol' ] ), 'no second callback builds its own document on order completion' );

// --- Generator and validator ----------------------------------------------------------------------------------------
$refused = false;
try {
	Plugin::instance()->get_ubl_generator()->build( [ 'invoice_number' => 'RUNTIME-1', 'payable_amount' => 119.00 ] );
} catch ( InvoiceDataException $e ) {
	$refused = in_array( 'buyer.endpoint', $e->problems(), true );
}
$check( $refused, 'the generator refuses an invoice without parties (was: "Musterstrasse 1", "Kunde", "Unbekannt")' );
$bare = WM_Peppol_Validator::validate( '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:CustomizationID>urn:cen.eu:en16931:2017</cbc:CustomizationID><cbc:ID>R</cbc:ID><cbc:IssueDate>2026-09-15</cbc:IssueDate><cbc:InvoiceTypeCode>380</cbc:InvoiceTypeCode><cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode></Invoice>' );
$check( ! $bare['valid'] && str_contains( implode( ' ', $bare['errors'] ), '[BR-16]' ), 'the validator refuses a document without totals and lines (was: valid)' );

// --- REST ---------------------------------------------------------------------------------------------------------------
$routes = rest_get_server()->get_routes();
$check( 'string' === ( $routes['/wm-peppol/v1/invoice-status'][0]['args']['invoice_number']['type'] ?? '' ), 'the public status route declares its arguments' );
// A malformed identifier is refused before any DNS request, so this assertion needs no network and
// writes no cache entry. The identifier below is the one the stub of 2026-09-15 answered 200 for,
// with an access point and cert_valid => true.
$lookup = new WP_REST_Request( 'POST', '/wm-peppol/v1/lookup' );
$lookup->set_param( 'participant_id', 'NOBODY-AT-ALL' );
$lookup_response = rest_do_request( $lookup );
$lookup_error    = $lookup_response->as_error();
$check(
	400 === $lookup_response->get_status() && $lookup_error instanceof WP_Error && 'wm_peppol_smp_bad_identifier' === $lookup_error->get_error_code(),
	'POST /wm-peppol/v1/lookup refuses a malformed identifier with its own error code (was: 200 with an access point for any id, then an unconditional 501)'
);

// --- Measured state -----------------------------------------------------------------------------------------------------
$counts  = InvoiceStore::counts();
$missing = Settings::missing_seller_fields();
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'wm_peppol_invoices' ) );
$check( $counts['tables'] && $counts['invoices'] + $counts['demo'] === $total, "invoices {$counts['invoices']} + demo rows {$counts['demo']} = {$total} stored rows" );

if ( interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	$adapter  = new \WMCyber\Peppol\Adapters\Peppol_Spoke_Adapter();
	$expected = ! $counts['tables'] ? 'ERROR' : ( [] !== $missing ? 'NEEDS_CONFIG' : ( Settings::dispatch_configured() && '' === WM_Peppol_Webhook_Receiver::secret() ? 'DEGRADED' : 'HEALTHY' ) );
	$health   = $adapter->get_health_status();
	$check( $expected === $health && ! str_contains( (string) wp_json_encode( $adapter->get_telemetry_metrics() ), 'COMPLIANT' ), "hub health {$health} matches the measured state (was: HEALTHY always)" );
} else {
	echo "  [INFO] hub not installed: adapter not checked\n";
}

// --- Output --------------------------------------------------------------------------------------------------------------
$admin = new AdminDashboard( Plugin::instance()->get_ubl_generator() );
ob_start();
$admin->render_dashboard_widget();
$_GET = [ 'page' => AdminDashboard::PAGE, 'tab' => 'overview' ];
$admin->render_dashboard_page();
$_GET = [];
$html = (string) ob_get_clean();
$check( ! preg_match( '/konform|CONFORME|Pflicht bereit|Factura|style=/u', $html ) && 0 === preg_match_all( '/[\x{1F300}-\x{1FAFF}]/u', $html ), 'widget and overview show no conformance claim, no Spanish, no inline style, no emoji' );
$check( 0 === $counts['demo'] || substr_count( $html, '>Beispiel</span>' ) >= min( 50, $counts['demo'] ), "the {$counts['demo']} demo rows are shown as examples, not as delivered" );

$portal = new InvoicePortal();
$markup = $portal->render_portal() . $portal->render_portal();
preg_match_all( '/\sid="([^"]+)"/', $markup, $ids );
$check( count( $ids[1] ) === count( array_unique( $ids[1] ) ) && ! str_contains( $markup, 'style=' ), 'two portals render without duplicate ids and without inline style' );

// --- Catalog -------------------------------------------------------------------------------------------------------------
require_once ABSPATH . WPINC . '/pomo/mo.php';
$mo = new MO();
$ok = $mo->import_from_file( WM_PEPPOL_PLUGIN_DIR . 'languages/wm-peppol-as4-gateway-de_DE.mo' );
$double = array_filter( array_keys( $mo->entries ), static fn( $k ) => str_contains( (string) $k, 'Ã' ) );
$check( $ok && [] === $double, 'WordPress imports the de_DE catalog and it holds no double-encoded entry (was: rejected)' );
