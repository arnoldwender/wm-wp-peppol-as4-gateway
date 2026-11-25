<?php
/**
 * Admin page and dashboard widget: no calls to missing methods (D1, D2), settings the generator reads (D16),
 * measured state and no claims (D17), demo rows marked and removable (D18), GMT (D37), German source (D38),
 * family classes and contrast on light surfaces, sheets on the dashboard (D30, D31).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Admin\AdminDashboard;
use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

/** Submit an admin form; the redirect location, '' when the handler returned without one. */
function pp_admin_post( AdminDashboard $admin, array $post ): string {
	$_POST    = $post;
	$location = '';
	try {
		$admin->handle_form_actions();
	} catch ( Test_Redirect $e ) {
		$location = $e->getMessage();
	} finally {
		$_POST = [];
	}
	return $location;
}

function pp_admin_page( AdminDashboard $admin, string $tab ): string {
	$_GET = [ 'page' => AdminDashboard::PAGE, 'tab' => $tab ];
	try {
		return pp_render( [ $admin, 'render_dashboard_page' ] );
	} finally {
		$_GET = [];
	}
}

function pp_invoice_row( string $number, string $status, string $xml ): array {
	return [
		'invoice_number'        => $number,
		'buyer_participant_id'  => '9930:DE987654321',
		'seller_participant_id' => '9930:DE123456789',
		'issue_date'            => '2026-09-12',
		'currency_code'         => 'EUR',
		'total_payable_amount'  => '4500.00',
		'ubl_xml'               => $xml,
		'xml_hash_sha256'       => hash( 'sha256', $xml ),
		'status'                => $status,
		'created_at'            => '2026-09-12 10:00:00',
		'updated_at'            => '2026-09-12 10:00:00',
	];
}

pp_group( 'ADMIN 1 · calls target methods that exist; the generator form stores what it built', static function (): void {
	pp_reset();
	global $wpdb;
	$source = pp_code( 'includes/Admin/AdminDashboard.php' );
	preg_match_all( '/\$this->ubl_generator->(\w+)\(/', $source, $calls );
	$missing = array_filter( $calls[1], static fn( string $method ): bool => ! method_exists( UblInvoiceGenerator::class, $method ) );
	pp_assert( [] !== $calls[1] && [] === $missing && ! str_contains( $source, 'smp_client' ), 'the page calls only generator methods that exist and no SMP lookup (was: generate_invoice_xml() and lookup_endpoint(), both fatal)' );

	$admin = new AdminDashboard( new UblInvoiceGenerator() );
	$form  = [
		'wm_peppol_action'  => 'generate_invoice',
		'invoice_number'    => 'A-1',
		'buyer_reference'   => 'PO-1',
		'buyer_name'        => 'Käufer GmbH',
		'buyer_street'      => 'Platz 2',
		'buyer_city'        => 'Leipzig',
		'buyer_postal_code' => '04109',
		'buyer_country'     => 'de',
		'buyer_endpoint'    => '0088:4000000000001',
		'buyer_vat_id'      => '',
		'line_description'  => 'Beratung',
		'quantity'          => '2',
		'unit_price'        => '50,00',
		'vat_rate'          => '19',
	];

	$location = pp_admin_post( $admin, $form );
	pp_assert( str_contains( $location, 'wm_peppol_notice=generate_failed' ) && str_contains( (string) get_transient( 'wm_peppol_notice_1' ), 'Straße des Verkäufers' ) && [] === $wpdb->rows( 'wp_wm_peppol_invoices' ), 'without seller data the form stores nothing and the notice names what is missing' );
	pp_assert( $GLOBALS['wp_test']['nonces_checked'] > 0, 'the nonce is checked' );

	pp_seller_options();
	$location = pp_admin_post( $admin, $form );
	$rows     = $wpdb->rows( 'wp_wm_peppol_invoices' );
	pp_assert( str_contains( $location, 'wm_peppol_notice=generated' ) && 1 === count( $rows ) && '119.00' === pp_text( pp_xpath( (string) ( $rows[0]['ubl_xml'] ?? '<x/>' ) ), '//cbc:PayableAmount' ), 'with seller data the form stores the invoice it built (2 x 50,00 at 19 %)' );
	pp_assert( abs( (int) strtotime( ( $rows[0]['created_at'] ?? '' ) . ' UTC' ) - time() ) < 120, 'created_at is GMT like every other writer (was: site time)' );
	pp_assert( str_contains( pp_admin_post( $admin, $form ), 'wm_peppol_notice=duplicate' ), 'the same invoice number again is refused as duplicate' );

	$GLOBALS['wp_test']['can'] = false;
	$redirects                 = count( $GLOBALS['wp_test']['redirects'] );
	pp_admin_post( $admin, [ 'wm_peppol_action' => 'seed_demo_data' ] );
	$GLOBALS['wp_test']['can'] = true;
	pp_assert( count( $GLOBALS['wp_test']['redirects'] ) === $redirects && 1 === count( $wpdb->rows( 'wp_wm_peppol_invoices' ) ), 'without manage_options no form action runs' );
} );

pp_group( 'ADMIN 2 · the settings screen writes the options the generator and the dispatch client read', static function (): void {
	pp_reset();
	$admin = new AdminDashboard( new UblInvoiceGenerator() );
	$post  = [ 'wm_peppol_action' => 'save_settings', 'kosit_url' => 'https://kosit.example.test/v', 'phase4_url' => 'javascript:alert(1)' ];
	foreach ( pp_seller() as $field => $value ) {
		$post[ 'seller_' . $field ] = $value;
	}
	$post['seller_iban'] = 'de89 3704 0044 0532 0130 00';
	pp_admin_post( $admin, $post );

	pp_assert( 'Weg 1' === get_option( 'wm_peppol_seller_street' ) && '9930:DE111222333' === get_option( 'wm_peppol_endpoint_id' ) && 'DE89370400440532013000' === get_option( 'wm_peppol_seller_iban' ), 'seller fields land in the wm_peppol_seller_* options, the IBAN normalised (was: an array nothing read)' );
	pp_assert( [] === Settings::missing_seller_fields() && 'https://kosit.example.test/v' === Settings::kosit_url() && '' === Settings::phase4_url() && ! Settings::dispatch_configured(), 'the seller data is complete; a non-http URL is dropped, so dispatch stays unconfigured' );
	pp_assert( false === get_option( 'wm_peppol_settings', false ), 'the old settings array is not written' );

	$page    = pp_admin_page( $admin, 'settings' );
	$missing = array_filter( array_keys( Settings::SELLER_OPTIONS ), static fn( string $field ): bool => ! str_contains( $page, 'name="seller_' . $field . '"' ) );
	pp_assert( [] === $missing, 'the settings form has a field for every seller option' );
	pp_assert( ! str_contains( $page, '04011000-12345-67' ) && ! str_contains( $page, 'environment' ), 'no prefilled Leitweg-ID the validator rejects and no environment switch' );

	$admin->register_settings();
	pp_assert( isset( $GLOBALS['wp_test']['registered_settings'][ Settings::DELETE_DATA_ON_UNINSTALL_OPTION ] ) && '0' === ( $GLOBALS['wp_test']['registered_settings'][ Settings::DELETE_DATA_ON_UNINSTALL_OPTION ]['args']['default'] ?? '' ), 'register_setting registers wm_peppol_delete_data_on_uninstall with default 0' );
	pp_assert( ! Settings::delete_data_on_uninstall() && str_contains( $page, 'name="delete_data_on_uninstall"' ), 'settings page exposes opt-in uninstall checkbox and defaults to false' );
	$post['delete_data_on_uninstall'] = '1';
	$post['trusted_signer_sha256']    = str_repeat( 'C', 64 );
	$post['require_trust_anchor']     = '1';
	pp_admin_post( $admin, $post );
	pp_assert( Settings::delete_data_on_uninstall(), 'enabling delete_data_on_uninstall saves 1' );
	pp_assert( [ str_repeat( 'c', 64 ) ] === Settings::trusted_signer_fingerprints() && Settings::trust_anchor_enforced(), 'settings form normalises and saves trusted_signer_sha256 and require_trust_anchor' );
} );

pp_group( 'ADMIN 3 · measured state, no claims, German, demo rows marked and removable', static function (): void {
	pp_reset();
	global $wpdb;
	$admin = new AdminDashboard( new UblInvoiceGenerator() );
	$pages = '';
	foreach ( [ 'overview', 'generator', 'settings', 'about' ] as $tab ) {
		$pages .= pp_admin_page( $admin, $tab );
	}
	$widget = pp_render( [ $admin, 'render_dashboard_widget' ] );
	$all    = $pages . $widget;

	foreach ( [ 'konform', 'CONFORME', 'Pflicht bereit', 'ELECTRÓNICA OK', 'activo', 'En red Peppol', 'ZUGFeRD', 'AS4 / SMP', 'Factura', 'Generar', 'Buscar', 'Glosario' ] as $claim ) {
		pp_assert( ! str_contains( $all, $claim ), "not rendered: \"{$claim}\"" );
	}
	pp_assert( 0 === preg_match_all( '/[\x{1F300}-\x{1FAFF}\x{2699}]/u', $all ), 'no emoji in page or widget (was: 23)' );
	pp_assert( ! str_contains( $all, 'style=' ) && 0 === preg_match_all( '/#[0-9a-fA-F]{6}\b/', $all ), 'no inline style and no hex colour (was: 33 lines with style=)' );
	pp_assert( ! str_contains( $pages, 'tab=smp' ), 'no SMP tab' );
	pp_assert( str_contains( $widget, 'is-warning' ) && str_contains( $widget, 'Verkäuferdaten unvollständig' ), 'the widget reports missing seller data' );

	$GLOBALS['wpdb'] = new Test_WPDB();
	pp_assert( str_contains( pp_render( [ $admin, 'render_dashboard_widget' ] ), 'Tabellen des Plugins fehlen' ), 'without tables the widget says so (was: "konform" with class is-valid)' );

	pp_reset();
	pp_admin_post( $admin, [ 'wm_peppol_action' => 'seed_demo_data' ] );
	$counts = InvoiceStore::counts();
	pp_assert( 3 === $counts['demo'] && 0 === $counts['invoices'] && 3 === count( $wpdb->get_results( "SELECT id FROM wp_wm_peppol_invoices WHERE status = 'demo'", 'ARRAY_A' ) ), 'seeding writes three rows with status demo that count as no invoice (was: status delivered and queued)' );

	$wpdb->insert( 'wp_wm_peppol_invoices', pp_invoice_row( 'INV-2026-9041', 'delivered', '<Invoice><ID>INV-2026-9041</ID></Invoice>' ) );
	$wpdb->insert( 'wp_wm_peppol_invoices', pp_invoice_row( 'INV-2026-8922', 'delivered', '<Invoice><ID>INV-2026-8922</ID></Invoice>' ) );
	$wpdb->insert( 'wp_wm_peppol_invoices', pp_invoice_row( 'INV-2026-9104', 'generated', '<Invoice><ID>INV-2026-9104</ID><cbc:Note/></Invoice>' ) );
	pp_assert( 5 === count( InvoiceStore::demo_ids() ), 'the stub rows of the old seeder are recognised by number and exact XML; a row with other XML is not' );

	$overview = pp_admin_page( $admin, 'overview' );
	pp_assert( 5 === substr_count( $overview, '>Beispiel</span>' ) && ! str_contains( $overview, 'Zugestellt' ), 'the old demo rows show as examples, not as delivered' );

	pp_admin_post( $admin, [ 'wm_peppol_action' => 'remove_demo_data' ] );
	pp_assert( [ 'INV-2026-9104' ] === array_column( $wpdb->rows( 'wp_wm_peppol_invoices' ), 'invoice_number' ), 'removing deletes the five demo rows and keeps the real row' );
} );

pp_group( 'ADMIN 4 · family classes on light surfaces measure at least 4.5:1; the widget gets its sheets', static function (): void {
	pp_reset();
	$admin = new AdminDashboard( new UblInvoiceGenerator() );
	$admin->enqueue_admin_assets( 'index.php' );
	pp_assert( in_array( 'wm-admin-ui', $GLOBALS['wp_test']['styles'], true ), 'the dashboard gets the family sheets the widget uses (was: none, the widget rendered unstyled)' );
	$GLOBALS['wp_test']['styles'] = [];
	$admin->enqueue_admin_assets( 'edit.php' );
	pp_assert( [] === $GLOBALS['wp_test']['styles'], 'other screens get nothing' );

	pp_assert( ! preg_match( '/wm-badge-(green|cyan|pulse)/', pp_code( 'includes/Admin/AdminDashboard.php' ) ), 'no banner badge classes in the light table (was: 1.67:1 and 1.91:1)' );

	$tokens = pp_css_tokens( (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/wm-admin-tokens.css' ) );
	$pairs  = [
		[ '--wm-success', '--wm-success-bg' ],
		[ '--wm-warning', '--wm-warning-bg' ],
		[ '--wm-danger', '--wm-danger-bg' ],
		[ '--wm-text-secondary', '--wm-bg-surface-subtle' ],
		[ '--wm-text-primary', '--wm-warning-bg' ],
		[ '--wm-text-primary', '--wm-bg-surface' ],
		[ '--wm-text-muted', '--wm-bg-surface' ],
	];
	foreach ( $pairs as [ $fg, $bg ] ) {
		$ratio = isset( $tokens[ $fg ], $tokens[ $bg ] ) ? pp_contrast( $tokens[ $fg ], $tokens[ $bg ] ) : 0.0;
		pp_assert( $ratio >= 4.5, sprintf( '%s on %s measures %.2f:1', $fg, $bg, $ratio ) );
	}
} );
