<?php
/**
 * Plugin Name:       WM Peppol AS4 Gateway
 * Plugin URI:        https://arnoldwender.com/wordpress-plugins/wm-peppol-as4-gateway/
 * Description:       Erzeugt UBL-2.1-Rechnungen nach Peppol BIS Billing 3.0, prüft sie gegen das amtliche EN-16931-Regelwerk, sucht den Empfänger über SML und SMP, übergibt Rechnungen abgeschlossener WooCommerce-Bestellungen an einen konfigurierten KoSIT-Validator und Phase4-Dienst, prüft die signierte AS4-Quittung und zeigt Käufern den gespeicherten Status. Keine eigene AS4-Übertragung: die übernimmt der Phase4-Dienst.
 * Version:           1.2.2
 * Requires at least: 6.4
 * Tested up to:      7.1
 * Requires PHP:      8.1
 * Author:            Arnold Wender
 * Author URI:        https://arnoldwender.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wm-peppol-as4-gateway
 * Domain Path:       /languages
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'WM_PEPPOL_VERSION', '1.2.2' );
define( 'WM_PEPPOL_FILE', __FILE__ );
define( 'WM_PEPPOL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WM_PEPPOL_PATH', WM_PEPPOL_PLUGIN_DIR );
define( 'WM_PEPPOL_URL', plugin_dir_url( __FILE__ ) );
// Names used by the ledger layer of 2026-08-28.
define( 'WM_PEPPOL_GATEWAY_VERSION', WM_PEPPOL_VERSION );
define( 'WM_PEPPOL_GATEWAY_FILE', __FILE__ );
define( 'WM_PEPPOL_GATEWAY_PATH', WM_PEPPOL_PLUGIN_DIR );

/*
 * includes/ holds code from three dates and this file loads all of it. Twice (2026-08-28, 08-29) a rewrite of
 * this file kept only its own layer and the REST API, admin page, portal and CLI shipped unreachable: extend the
 * loader, do not replace it.
 *
 * Since 2026-09-15 there is one WooCommerce order flow (WMCyber\Peppol\WooCommerce\WooCommerceInvoiceHook). The
 * second one that lived in this file (WM_Peppol_Ubl_Builder + process_order_for_peppol) sent a different document
 * than the one stored, with a placeholder IBAN and supplier id; it is gone. This file keeps the order-edit fields
 * and the receipt webhook route.
 */

// 1. WMCyber\Peppol\* (PSR-4): settings, schema, REST, admin, portal, WP-CLI, UBL, WooCommerce flow.
spl_autoload_register( static function ( string $class ): void {
    $prefix = 'WMCyber\\Peppol\\';
    if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
        return;
    }
    $file = WM_PEPPOL_PLUGIN_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// 2. Validation and ledger layer: structural checks, SBDH packaging, HMAC chain. The ledger's create_tables()
//    describes a different wm_peppol_invoices than Database\Schema and is not run: dbDelta would graft twelve
//    NOT NULL columns onto the table every writer uses. Nothing records invoices in the ledger.
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-validator.php';
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-as4-client.php';
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-database.php';

// 3. Order fields, dispatch client and receipt receiver.
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-leitweg-validator.php';
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-phase4-client.php';
require_once WM_PEPPOL_PLUGIN_DIR . 'includes/class-peppol-webhook-receiver.php';

register_activation_hook( __FILE__, array( \WMCyber\Peppol\Database\Schema::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \WMCyber\Peppol\Database\Schema::class, 'deactivate' ) );

class WM_Peppol_Gateway {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks(): void {
        // Peppol fields on the order edit screen (classic and HPOS).
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_order_peppol_fields' ), 10, 1 );
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_admin_order_peppol_fields' ), 10, 1 );

        add_action( 'rest_api_init', array( 'WM_Peppol_Webhook_Receiver', 'register_routes' ) );
    }

    /**
     * @param WC_Order $order Order being edited.
     */
    public function admin_order_peppol_fields( $order ): void {
        echo '<h4>' . esc_html__( 'Peppol-E-Rechnung', 'wm-peppol-as4-gateway' ) . '</h4>';
        woocommerce_wp_text_input( array(
            'id'            => '_peppol_endpoint_id',
            'label'         => __( 'Peppol-Empfänger-ID (Schema:Kennung)', 'wm-peppol-as4-gateway' ),
            'wrapper_class' => 'form-field-wide',
            'value'         => $order->get_meta( '_peppol_endpoint_id' ),
        ) );
        woocommerce_wp_text_input( array(
            'id'            => '_peppol_leitweg_id',
            'label'         => __( 'Leitweg-ID (öffentliche Auftraggeber)', 'wm-peppol-as4-gateway' ),
            'wrapper_class' => 'form-field-wide',
            'value'         => $order->get_meta( '_peppol_leitweg_id' ),
        ) );
        woocommerce_wp_text_input( array(
            'id'            => '_peppol_buyer_reference',
            'label'         => __( 'Käuferreferenz (wenn keine Leitweg-ID)', 'wm-peppol-as4-gateway' ),
            'wrapper_class' => 'form-field-wide',
            'value'         => $order->get_meta( '_peppol_buyer_reference' ),
        ) );
    }

    /**
     * Save the Peppol fields through the order meta API, which writes to wc_orders_meta under HPOS and to post meta
     * otherwise. Until 2026-09-15 they went through update_post_meta() and were read with $order->get_meta().
     *
     * @param int|string $order_id Order id.
     */
    public function save_admin_order_peppol_fields( $order_id ): void {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
        if ( ! $order ) {
            return;
        }

        // Nonce: WooCommerce verifies woocommerce_meta_nonce before firing woocommerce_process_shop_order_meta.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $changed = false;
        if ( isset( $_POST['_peppol_leitweg_id'] ) ) {
            $leitweg_id = trim( sanitize_text_field( wp_unslash( $_POST['_peppol_leitweg_id'] ) ) );
            if ( '' !== $leitweg_id && ! WM_Peppol_Leitweg_Validator::is_valid( $leitweg_id ) ) {
                WC_Admin_Meta_Boxes::add_error( __( 'Die Leitweg-ID hat nicht den Aufbau der KoSIT-Spezifikation oder ihre Prüfziffer stimmt nicht; sie wurde nicht gespeichert.', 'wm-peppol-as4-gateway' ) );
            } else {
                $order->update_meta_data( '_peppol_leitweg_id', $leitweg_id );
                $changed = true;
            }
        }
        if ( isset( $_POST['_peppol_endpoint_id'] ) ) {
            $endpoint_id = trim( sanitize_text_field( wp_unslash( $_POST['_peppol_endpoint_id'] ) ) );
            if ( '' !== $endpoint_id && ! preg_match( '/^\d{4}:\S+$/', $endpoint_id ) ) {
                WC_Admin_Meta_Boxes::add_error( __( 'Die Peppol-Empfänger-ID hat nicht die Form Schema:Kennung mit vierstelligem Schema; sie wurde nicht gespeichert.', 'wm-peppol-as4-gateway' ) );
            } else {
                $order->update_meta_data( '_peppol_endpoint_id', $endpoint_id );
                $changed = true;
            }
        }
        if ( isset( $_POST['_peppol_buyer_reference'] ) ) {
            $order->update_meta_data( '_peppol_buyer_reference', trim( sanitize_text_field( wp_unslash( $_POST['_peppol_buyer_reference'] ) ) ) );
            $changed = true;
        }
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        if ( $changed ) {
            $order->save();
        }
    }
}

// Boot both runtime layers once every plugin is loaded.
add_action( 'plugins_loaded', static function (): void {
    // A plugin updated in place is never re-activated, so the tables are brought forward here too.
    \WMCyber\Peppol\Database\Schema::maybe_upgrade();
    \WMCyber\Peppol\Core\Plugin::instance()->boot();
    WM_Peppol_Gateway::get_instance();
} );

/**
 * Register with WM Suite Hub if active.
 */
function wm_peppol_as4_gateway_register_spoke_module(): void {
	if ( interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
		require_once WM_PEPPOL_PLUGIN_DIR . 'includes/Adapters/Peppol_Spoke_Adapter.php';
		if ( class_exists( 'WMCyber\Peppol\Adapters\Peppol_Spoke_Adapter' ) ) {
			add_filter( 'wm_register_suite_module', static function ( array $modules ): array {
				$modules[] = new \WMCyber\Peppol\Adapters\Peppol_Spoke_Adapter();
				return $modules;
			} );
		}
	}
}
add_action( 'plugins_loaded', 'wm_peppol_as4_gateway_register_spoke_module', 20 );

/**
 * Provision Peppol database tables when a new multisite blog is initialized
 * while the plugin is network-activated.
 *
 * @param object $new_site Newly initialized site object (WP_Site).
 */
function wm_peppol_as4_gateway_initialize_site( $new_site ): void {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) || ! is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		return;
	}
	if ( ! is_object( $new_site ) || ! isset( $new_site->blog_id ) || (int) $new_site->blog_id <= 0 ) {
		return;
	}
	if ( ! function_exists( 'switch_to_blog' ) || ! function_exists( 'restore_current_blog' ) ) {
		return;
	}

	switch_to_blog( (int) $new_site->blog_id );
	try {
		\WMCyber\Peppol\Database\Schema::activate();
	} finally {
		restore_current_blog();
	}
}
add_action( 'wp_initialize_site', 'wm_peppol_as4_gateway_initialize_site', 10, 1 );
