<?php
declare(strict_types=1);

namespace WMCyber\Peppol\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Peppol\UBL\UblInvoiceGenerator;
use WMCyber\Peppol\AS4\As4MessagePackager;
use WMCyber\Peppol\Discovery\SmpClient;
use WMCyber\Peppol\API\RestController;
use WMCyber\Peppol\CLI\PeppolCommand;
use WMCyber\Peppol\Admin\AdminDashboard;
use WMCyber\Peppol\WooCommerce\WooCommerceInvoiceHook;
use WMCyber\Peppol\Frontend\DynamicBindings;
use WMCyber\Peppol\Frontend\InvoicePortal;

final class Plugin {
	private static ?Plugin $instance = null;
	private UblInvoiceGenerator $ubl_generator;
	private As4MessagePackager $as4_packager;
	private SmpClient $smp_client;

	private function __construct() {
		$this->ubl_generator = new UblInvoiceGenerator();
		$this->as4_packager  = new As4MessagePackager();
		$this->smp_client    = new SmpClient();
	}

	public static function instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Hooks of the running plugin.
	 *
	 * Until 2026-09-15 boot also created uploads/wm-peppol/invoices with four guard files on every request (nothing
	 * ever wrote an invoice there) and scanned GET, POST and cookies for stream wrappers, logging the client address
	 * of each hit and ignoring the result. Both calls and their classes are gone; uninstall.php removes the
	 * directory.
	 */
	public function boot(): void {
		add_action( 'init', function (): void {
			load_plugin_textdomain(
				'wm-peppol-as4-gateway',
				false,
				dirname( plugin_basename( WM_PEPPOL_FILE ) ) . '/languages'
			);
		} );

		( new RestController( $this->ubl_generator, $this->smp_client ) )->register_routes();

		if ( is_admin() ) {
			( new AdminDashboard( $this->ubl_generator ) )->init();
		}

		// The one WooCommerce order flow: document, storage, scheduled dispatch.
		( new WooCommerceInvoiceHook( $this->ubl_generator ) )->init();

		( new InvoicePortal() )->init();
		( new DynamicBindings() )->init();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			PeppolCommand::register();
		}

		/**
		 * Fires once WM Peppol AS4 Gateway has registered all runtime hooks.
		 *
		 * @param Plugin $plugin Active plugin instance.
		 */
		do_action( 'wm_peppol_ready', $this );
	}

	public function get_ubl_generator(): UblInvoiceGenerator {
		return $this->ubl_generator;
	}

	/** Builds an ebMS3 envelope; no runtime path sends it (the Phase4 service builds its own). */
	public function get_as4_packager(): As4MessagePackager {
		return $this->as4_packager;
	}

	public function get_smp_client(): SmpClient {
		return $this->smp_client;
	}
}
