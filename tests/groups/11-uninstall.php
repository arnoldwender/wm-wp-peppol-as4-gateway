<?php
/**
 * uninstall.php (D28): options, receipt secret, transients, scheduled dispatches, the directory an earlier version
 * created, and the tables. Runs last: it drops the tables.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Core\Settings;

function pp_run_uninstall(): void {
	if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
		define( 'WP_UNINSTALL_PLUGIN', 'wm-peppol-as4-gateway/wm-peppol-as4-gateway.php' );
	}
	( static function (): void {
		require dirname( __DIR__, 2 ) . '/uninstall.php';
	} )();
}

pp_group( 'UNINSTALL · options, secret, transients, schedule, directory and tables go; nothing else', static function (): void {
	pp_reset();
	global $wpdb;
	$plugin_options = array_merge( array_values( Settings::SELLER_OPTIONS ), [ 'wm_peppol_kosit_url', 'wm_peppol_phase4_url', 'wm_peppol_receipt_secret', 'wm_peppol_trusted_signer_sha256', 'wm_peppol_trust_bundle_pem', 'wm_peppol_require_trust_anchor', 'wm_peppol_settings', 'wm_peppol_db_version', 'wm_peppol_ledger_tip', 'wm_peppol_sml_zone', 'wm_peppol_schematron_gate', 'wm_peppol_schema_version' ] );
	foreach ( array_merge( $plugin_options, [ 'unrelated_option' ] ) as $option ) {
		update_option( $option, 'x' );
	}
	foreach ( [ '_transient_wm_peppol_st_abc', '_transient_timeout_wm_peppol_st_abc', '_transient_wm_peppol_notice_1', '_transient_wm_peppol_smp_deadbeef', '_transient_other_plugin' ] as $name ) {
		$wpdb->insert( 'wp_options', [ 'option_name' => $name, 'option_value' => '1' ] );
	}
	$dir = WP_CONTENT_DIR . '/uploads/wm-peppol/invoices';
	mkdir( $dir, 0777, true );
	foreach ( [ '.htaccess', 'web.config', 'index.php', 'index.html' ] as $file ) {
		file_put_contents( "{$dir}/{$file}", 'guard' );
	}

	pp_run_uninstall();

	$kept_tables = array_filter( [ 'wp_wm_peppol_invoices', 'wp_wm_peppol_transmissions', 'wp_wm_peppol_audit_log' ], static fn( string $t ): bool => null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) );
	pp_assert( count( $plugin_options ) === count( array_intersect( array_keys( $GLOBALS['wp_test']['options'] ), $plugin_options ) ) && 3 === count( $kept_tables ) && is_file( "{$dir}/.htaccess" ), 'by default (wm_peppol_delete_data_on_uninstall unset/0), uninstall preserves options, guard files and all three tables' );
	pp_assert( in_array( 'wm_peppol_dispatch_invoice', $GLOBALS['wp_test']['unscheduled'], true ), 'scheduled dispatches are removed even when data is preserved' );

	update_option( 'wm_peppol_delete_data_on_uninstall', '1' );
	pp_run_uninstall();

	pp_assert( [] === array_intersect( array_keys( $GLOBALS['wp_test']['options'] ), $plugin_options ) && false === get_option( 'wm_peppol_delete_data_on_uninstall', false ) && 'x' === get_option( 'unrelated_option' ), 'when opted in, every option of the plugin is deleted, the receipt secret included (was: left); other options stay' );
	pp_assert( [ '_transient_other_plugin' ] === array_column( $wpdb->rows( 'wp_options' ), 'option_name' ), 'rate-limit, notice and SMP-lookup transients are deleted, other transients stay (was: left)' );
	pp_assert( ! is_dir( $dir ) && ! is_dir( dirname( $dir ) ), 'the guard files and the directories an earlier version created are removed (was: left)' );
	$tables = array_filter( [ 'wp_wm_peppol_invoices', 'wp_wm_peppol_transmissions', 'wp_wm_peppol_audit_log' ], static fn( string $t ): bool => null !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) );
	pp_assert( [] === $tables, 'the three tables are dropped when opted in' );

	mkdir( $dir, 0777, true );
	file_put_contents( "{$dir}/.htaccess", 'guard' );
	file_put_contents( "{$dir}/kept.xml", '<Invoice/>' );
	update_option( 'wm_peppol_delete_data_on_uninstall', '1' );
	pp_run_uninstall();
	pp_assert( is_file( "{$dir}/kept.xml" ) && ! is_file( "{$dir}/.htaccess" ), 'a file the plugin did not create is kept, and so is its directory' );
} );
