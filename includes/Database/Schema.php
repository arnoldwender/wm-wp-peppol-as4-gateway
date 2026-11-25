<?php
declare(strict_types=1);

namespace WMCyber\Peppol\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Schema {
	/** Bumped whenever a table changes, so maybe_upgrade() can tell. */
	public const VERSION = '2026-09-16-delivery-register';

	/** Where that version is stored. */
	public const VERSION_OPTION = 'wm_peppol_schema_version';

	/** Required PHP extensions for UBL XML DOM manipulation and X.509/XMLDSig verification. */
	public const REQUIRED_EXTENSIONS = [ 'dom', 'openssl' ];

	/**
	 * Verify required PHP extensions before activating; deactivate cleanly with wp_die() when missing.
	 *
	 * @param callable(string):bool|null $probe Optional extension probe for isolated tests.
	 */
	public static function enforce_prerequisites( ?callable $probe = null ): void {
		$check   = $probe ?? static fn( string $ext ): bool => extension_loaded( $ext );
		$missing = array_values( array_filter( self::REQUIRED_EXTENSIONS, static fn( string $ext ): bool => ! $check( $ext ) ) );
		if ( [] === $missing ) {
			return;
		}
		if ( ! function_exists( 'deactivate_plugins' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( function_exists( 'deactivate_plugins' ) && defined( 'WM_PEPPOL_FILE' ) ) {
			deactivate_plugins( plugin_basename( WM_PEPPOL_FILE ) );
		}
		if ( function_exists( 'wp_die' ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: comma-separated list of missing PHP extensions */
						__( 'WM Peppol AS4 Gateway konnte nicht aktiviert werden, da folgende erforderliche PHP-Erweiterungen auf diesem Server fehlen: %s.', 'wm-peppol-as4-gateway' ),
						implode( ', ', $missing )
					)
				),
				esc_html__( 'Fehlende PHP-Erweiterung', 'wm-peppol-as4-gateway' ),
				[ 'back_link' => true ]
			);
		}
	}

	public static function activate( bool $network_wide = false ): void {
		self::enforce_prerequisites();
		self::for_each_site( $network_wide, static function (): void { self::create_tables(); } );
	}

	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$table_invoices      = $wpdb->prefix . 'wm_peppol_invoices';
		$table_transmissions = $wpdb->prefix . 'wm_peppol_transmissions';
		$table_audit          = $wpdb->prefix . 'wm_peppol_audit_log';

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// 1. Invoices. Status: generated, sent, delivered, failed, dispatch_failed, demo.
		$sql_invoices = "CREATE TABLE $table_invoices (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_number varchar(64) NOT NULL,
			buyer_participant_id varchar(64) NOT NULL,
			seller_participant_id varchar(64) NOT NULL,
			issue_date date NOT NULL,
			currency_code varchar(3) NOT NULL DEFAULT 'EUR',
			total_payable_amount decimal(12,2) NOT NULL DEFAULT 0.00,
			ubl_xml longtext NOT NULL,
			xml_hash_sha256 varchar(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'draft',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_invoice_num (invoice_number),
			KEY idx_status (status)
		) $charset_collate;";
		dbDelta( $sql_invoices );

		// 2. Hand-overs to the Phase4 service and the receipts that answer them.
		$sql_transmissions = "CREATE TABLE $table_transmissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_id bigint(20) unsigned NOT NULL,
			message_id varchar(128) NOT NULL,
			c2_sender_ap varchar(255) NOT NULL,
			c3_receiver_ap varchar(255) NOT NULL,
			transport_status varchar(32) NOT NULL DEFAULT 'pending',
			non_repudiation_receipt text DEFAULT NULL,
			as4_error_code varchar(64) DEFAULT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uq_message_id (message_id),
			KEY idx_invoice_id (invoice_id)
		) $charset_collate;";
		dbDelta( $sql_transmissions );

		// 3. Delivery register. Empty until 2026-09-16, when AS4\DeliveryRegister started writing it:
		// one chained row per verified receipt. message_id and detail_json were added then; dbDelta
		// adds them to an existing table, and the rows written before (there are none, nothing wrote
		// here) would keep their fingerprint because it folds in the columns it had.
		$sql_audit = "CREATE TABLE $table_audit (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			invoice_number varchar(64) NOT NULL,
			action varchar(64) NOT NULL,
			message_id varchar(128) NOT NULL DEFAULT '',
			detail_json longtext NOT NULL,
			previous_hash varchar(64) NOT NULL,
			current_hash varchar(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_message_id (message_id),
			KEY idx_action (action)
		) $charset_collate;";
		dbDelta( $sql_audit );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Run the schema again when the plugin was updated in place.
	 *
	 * Activation is not enough: a plugin updated through the dashboard or through WP-CLI is not
	 * re-activated, so a site that never deactivates would keep a table without the columns the code
	 * writes, and every register insert would fail silently.
	 */
	public static function maybe_upgrade(): void {
		if ( self::VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
			self::create_tables();
		}
	}

	/** Deactivation: scheduled dispatches do not outlive the plugin. Stored data stays. */
	public static function deactivate( bool $network_wide = false ): void {
		self::for_each_site(
			$network_wide,
			static function (): void {
				wp_clear_scheduled_hook( 'wm_peppol_dispatch_invoice' );
			}
		);
	}

	/**
	 * Run a site-level schema or schedule callback across every network blog when network-wide,
	 * or on the active blog otherwise.
	 *
	 * @param bool     $network_wide Whether the plugin action applies network-wide.
	 * @param callable $callback     Per-site callback.
	 */
	private static function for_each_site( bool $network_wide, callable $callback ): void {
		if (
			$network_wide
			&& function_exists( 'is_multisite' ) && is_multisite()
			&& function_exists( 'get_sites' )
			&& function_exists( 'switch_to_blog' ) && function_exists( 'restore_current_blog' )
		) {
			$site_ids = get_sites(
				[
					'fields' => 'ids',
					'number' => 0,
				]
			);
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			return;
		}

		$callback();
	}
}
