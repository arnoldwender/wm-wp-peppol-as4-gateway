<?php
/**
 * WM Suite Peppol AS4 Gateway — uninstaller.
 *
 * Triggered when the plugin is deleted in WordPress. Preserves the plugin's options and its three tables by
 * default so invoices, transmissions and the delivery register are not lost on a plugin re-install. Only when
 * the administrator explicitly enabled `wm_peppol_delete_data_on_uninstall` on the settings screen does this
 * uninstaller delete all plugin options, transients and guard files and DROP the three tables.
 *
 * Until 2026-09-15 it left the receipt secret, the rate-limit transients and the uploads/wm-peppol directory an
 * earlier version created on every request.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_unschedule_hook( 'wm_peppol_dispatch_invoice' );

$wm_peppol_delete_data = get_option( 'wm_peppol_delete_data_on_uninstall', '0' );
if ( '1' !== (string) $wm_peppol_delete_data && true !== $wm_peppol_delete_data ) {
	return;
}

global $wpdb;

// 1. Options: seller settings and service URLs (names as in includes/Core/Settings.php), the receipt secret, the
//    settings array the admin page wrote before 2026-09-15, and the two options of the unwired ledger.
$wm_peppol_as4_gateway_options = [
	'wm_peppol_settings',
	'wm_peppol_endpoint_id',
	'wm_peppol_seller_name',
	'wm_peppol_seller_legal_name',
	'wm_peppol_seller_street',
	'wm_peppol_seller_city',
	'wm_peppol_seller_postal_code',
	'wm_peppol_seller_country',
	'wm_peppol_seller_vat_id',
	'wm_peppol_seller_company_id',
	'wm_peppol_seller_iban',
	'wm_peppol_kosit_url',
	'wm_peppol_phase4_url',
	'wm_peppol_receipt_secret',
	'wm_peppol_trusted_signer_sha256',
	'wm_peppol_trust_bundle_pem',
	'wm_peppol_require_trust_anchor',
	'wm_peppol_db_version',
	'wm_peppol_ledger_tip',
	'wm_peppol_sml_zone',
	'wm_peppol_schematron_gate',
	'wm_peppol_schema_version',
	'wm_peppol_delete_data_on_uninstall',
];

foreach ( $wm_peppol_as4_gateway_options as $wm_peppol_as4_gateway_option ) {
	delete_option( $wm_peppol_as4_gateway_option );
	delete_site_option( $wm_peppol_as4_gateway_option );
}

// 2. Transients: status-lookup rate limits (wm_peppol_st_*) and admin notices (wm_peppol_notice_*).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
		$wpdb->options,
		$wpdb->esc_like( '_transient_wm_peppol_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wm_peppol_' ) . '%'
	)
);

// 3. The directory an earlier version created on every request: its four guard files, then the directories if empty.
$wm_peppol_as4_gateway_dir = WP_CONTENT_DIR . '/uploads/wm-peppol/invoices';
foreach ( [ '.htaccess', 'web.config', 'index.php', 'index.html' ] as $wm_peppol_as4_gateway_file ) {
	if ( is_file( $wm_peppol_as4_gateway_dir . '/' . $wm_peppol_as4_gateway_file ) ) {
		wp_delete_file( $wm_peppol_as4_gateway_dir . '/' . $wm_peppol_as4_gateway_file );
	}
}
foreach ( [ $wm_peppol_as4_gateway_dir, dirname( $wm_peppol_as4_gateway_dir ) ] as $wm_peppol_as4_gateway_empty_dir ) {
	if ( is_dir( $wm_peppol_as4_gateway_empty_dir ) && [] === array_diff( (array) scandir( $wm_peppol_as4_gateway_empty_dir ), [ '.', '..' ] ) ) {
		@rmdir( $wm_peppol_as4_gateway_empty_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty directory of this plugin only.
	}
}

// 5. Tables.
foreach ( [ 'wm_peppol_audit_log', 'wm_peppol_transmissions', 'wm_peppol_invoices' ] as $wm_peppol_as4_gateway_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $wm_peppol_as4_gateway_table ) );
}
