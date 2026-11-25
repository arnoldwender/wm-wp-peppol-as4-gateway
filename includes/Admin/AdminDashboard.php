<?php
/**
 * Admin page "Peppol Gateway" and the dashboard widget.
 *
 * Until 2026-09-15 the page was in Spanish, both of its working forms ended in fatal errors (it called
 * generate_invoice_xml() and lookup_endpoint(), which do not exist), the settings form saved an array nothing
 * read and prefilled a Leitweg-ID the plugin's own validator rejects, and the page and widget showed
 * "FACTURACIÓN ELECTRÓNICA OK", "CONFORME", "Transporte AS4 activo", "konform" and "Pflicht bereit" on every
 * install, one without tables included. The demo button wrote rows with status "delivered" that the public
 * status route reported as delivered. The page now shows measured state and says what the plugin does not do.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

final class AdminDashboard {

	public const PAGE = 'wm-peppol-gateway';

	private const NONCE_ACTION = 'wm_peppol_admin_action';
	private const NONCE_FIELD  = 'wm_peppol_nonce';
	private const TABS         = [ 'overview', 'generator', 'settings', 'about' ];

	public function __construct(
		private readonly UblInvoiceGenerator $ubl_generator
	) {}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_init', [ $this, 'handle_form_actions' ] );
		add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
	}

	public function register_settings(): void {
		if ( ! function_exists( 'register_setting' ) ) {
			return;
		}
		register_setting(
			'wm_peppol_settings_group',
			Settings::TRUSTED_SIGNER_SHA256_OPTION,
			[
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static fn( $value ): string => implode( ', ', Settings::parse_fingerprints( is_scalar( $value ) ? (string) $value : '' ) ),
			]
		);
		register_setting(
			'wm_peppol_settings_group',
			Settings::TRUST_BUNDLE_PEM_OPTION,
			[
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static fn( $value ): string => Settings::sanitise_pem_bundle( is_scalar( $value ) ? (string) $value : '' ),
			]
		);
		register_setting(
			'wm_peppol_settings_group',
			Settings::REQUIRE_TRUST_ANCHOR_OPTION,
			[
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static fn( $value ): string => ( '1' === (string) $value || true === $value ) ? '1' : '0',
			]
		);
		register_setting(
			'wm_peppol_settings_group',
			Settings::DELETE_DATA_ON_UNINSTALL_OPTION,
			[
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static fn( $value ): string => ( '1' === (string) $value || true === $value ) ? '1' : '0',
			]
		);
	}

	// -----------------------------------------------------------------------
	// Dashboard widget
	// -----------------------------------------------------------------------

	public function register_dashboard_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'wm_peppol_widget',
			__( 'WM Peppol AS4 Gateway', 'wm-peppol-as4-gateway' ),
			[ $this, 'render_dashboard_widget' ]
		);
	}

	public function render_dashboard_widget(): void {
		$counts  = InvoiceStore::counts();
		$missing = Settings::missing_seller_fields();
		?>
		<div class="wm-dash-widget-box" data-wm-theme="<?php echo esc_attr( function_exists( 'wm_get_suite_theme' ) ? (string) wm_get_suite_theme() : 'cyber-events' ); ?>">
			<?php if ( ! $counts['tables'] ) : ?>
				<div class="wm-dash-banner is-warning">
					<div class="wm-dash-banner-title"><?php esc_html_e( 'Die Tabellen des Plugins fehlen. Bitte das Plugin deaktivieren und wieder aktivieren.', 'wm-peppol-as4-gateway' ); ?></div>
				</div>
			<?php elseif ( [] !== $missing ) : ?>
				<div class="wm-dash-banner is-warning">
					<div class="wm-dash-banner-title"><?php esc_html_e( 'Verkäuferdaten unvollständig: Es kann keine E-Rechnung erzeugt werden.', 'wm-peppol-as4-gateway' ); ?></div>
				</div>
			<?php else : ?>
				<div class="wm-dash-banner">
					<div class="wm-dash-banner-title">
						<?php
						echo esc_html(
							Settings::dispatch_configured()
								? __( 'Verkäuferdaten vollständig. KoSIT-Validator und Phase4-Dienst sind konfiguriert.', 'wm-peppol-as4-gateway' )
								: __( 'Verkäuferdaten vollständig. Versand nicht konfiguriert: Rechnungen werden nur gespeichert.', 'wm-peppol-as4-gateway' )
						);
						?>
					</div>
				</div>
			<?php endif; ?>

			<div class="wm-dash-kpi-grid">
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( $counts['invoices'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Gespeicherte Rechnungen', 'wm-peppol-as4-gateway' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( $counts['transmissions'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Übergaben an Phase4', 'wm-peppol-as4-gateway' ); ?></div>
				</div>
				<div class="wm-dash-kpi-card">
					<div class="wm-dash-kpi-val"><?php echo esc_html( number_format_i18n( $counts['delivered'] ) ); ?></div>
					<div class="wm-dash-kpi-lbl"><?php esc_html_e( 'Empfangsquittungen', 'wm-peppol-as4-gateway' ); ?></div>
				</div>
			</div>

			<div class="wm-dash-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="wm-dash-btn"><?php esc_html_e( 'Peppol Gateway öffnen', 'wm-peppol-as4-gateway' ); ?></a>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Menu and assets
	// -----------------------------------------------------------------------

	/**
	 * Family menu icon: a monochrome hub-and-spoke mark, filled shapes only so the WordPress
	 * svg-painter can recolour it with the admin colour scheme. Placeholder until the family
	 * icon is decided (hackaton plan D12).
	 */
	public static function family_menu_icon(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><circle cx="10" cy="10" r="2.6" fill="black"/><circle cx="10" cy="3" r="1.8" fill="black"/><circle cx="16.1" cy="13.5" r="1.8" fill="black"/><circle cx="3.9" cy="13.5" r="1.8" fill="black"/><rect x="9.3" y="4.5" width="1.4" height="3.4" fill="black"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(-60 10 10)"/><rect x="9.3" y="12.1" width="1.4" height="3.4" fill="black" transform="rotate(60 10 10)"/></svg>';
		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'Peppol Gateway', 'wm-peppol-as4-gateway' ),
			__( 'Peppol Gateway', 'wm-peppol-as4-gateway' ),
			'manage_options',
			self::PAGE,
			[ $this, 'render_dashboard_page' ],
			self::family_menu_icon(),
			31
		);
	}

	/**
	 * Family sheets on the plugin page and on the dashboard, where the widget renders. Until 2026-09-15 the
	 * dashboard got no sheet and the widget's family classes rendered unstyled.
	 *
	 * @param string $hook Admin page hook.
	 */
	public function enqueue_admin_assets( string $hook ): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects the stylesheet only.
		if ( 'index.php' !== $hook && self::PAGE !== $page ) {
			return;
		}

		$root = dirname( __DIR__, 2 ) . '/assets/css/';
		wp_enqueue_style(
			'wm-admin-tokens',
			WM_PEPPOL_URL . 'assets/css/wm-admin-tokens.css',
			[],
			(string) ( @filemtime( $root . 'wm-admin-tokens.css' ) ?: WM_PEPPOL_VERSION )
		);
		wp_enqueue_style(
			'wm-admin-ui',
			WM_PEPPOL_URL . 'assets/css/wm-admin-ui.css',
			[ 'wm-admin-tokens' ],
			(string) ( @filemtime( $root . 'wm-admin-ui.css' ) ?: WM_PEPPOL_VERSION )
		);
	}

	// -----------------------------------------------------------------------
	// Form actions
	// -----------------------------------------------------------------------

	public function handle_form_actions(): void {
		if ( ! isset( $_POST['wm_peppol_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$action = sanitize_key( wp_unslash( $_POST['wm_peppol_action'] ) );

		switch ( $action ) {
			case 'seed_demo_data':
				$added = $this->seed_demo_data();
				/* translators: %d: number of demo rows */
				$this->finish( 'overview', 'seeded', sprintf( _n( '%d Beispielrechnung angelegt.', '%d Beispielrechnungen angelegt.', $added, 'wm-peppol-as4-gateway' ), $added ) );
				return;

			case 'remove_demo_data':
				$removed = $this->remove_demo_data();
				/* translators: %d: number of demo rows */
				$this->finish( 'overview', 'removed', sprintf( _n( '%d Beispielrechnung entfernt.', '%d Beispielrechnungen entfernt.', $removed, 'wm-peppol-as4-gateway' ), $removed ) );
				return;

			case 'generate_invoice':
				$this->generate_invoice();
				return;

			case 'save_settings':
				$this->save_settings();
				$this->finish( 'settings', 'saved' );
				return;
		}
	}

	/** Build and store one invoice from the generator form. Nothing is sent. */
	private function generate_invoice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_form_actions().
		$in = [];
		foreach ( [ 'invoice_number', 'buyer_reference', 'buyer_name', 'buyer_street', 'buyer_city', 'buyer_postal_code', 'buyer_country', 'buyer_endpoint', 'buyer_vat_id', 'line_description', 'quantity', 'unit_price', 'vat_rate' ] as $field ) {
			$in[ $field ] = isset( $_POST[ $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$data = [
			'invoice_number'  => $in['invoice_number'],
			'buyer_reference' => $in['buyer_reference'],
			'issue_date'      => gmdate( 'Y-m-d' ),
			'buyer'           => [
				'endpoint'    => $in['buyer_endpoint'],
				'name'        => $in['buyer_name'],
				'street'      => $in['buyer_street'],
				'city'        => $in['buyer_city'],
				'postal_code' => $in['buyer_postal_code'],
				'country'     => strtoupper( $in['buyer_country'] ),
				'vat_id'      => strtoupper( str_replace( ' ', '', $in['buyer_vat_id'] ) ),
			],
			'lines'           => [
				[
					'name'       => $in['line_description'],
					'quantity'   => str_replace( ',', '.', $in['quantity'] ),
					'unit_price' => str_replace( ',', '.', $in['unit_price'] ),
					'vat_rate'   => str_replace( ',', '.', $in['vat_rate'] ),
				],
			],
		];

		try {
			$document = $this->ubl_generator->build( $data );
		} catch ( InvoiceDataException $e ) {
			$this->finish( 'generator', 'generate_failed', $e->describe() );
			return;
		}

		global $wpdb;
		$table = InvoiceStore::invoices_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE invoice_number = %s', $table, $document['invoice_number'] ) ) ) {
			$this->finish( 'generator', 'duplicate' );
			return;
		}

		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			$table,
			[
				'invoice_number'        => $document['invoice_number'],
				'buyer_participant_id'  => $document['buyer_endpoint'],
				'seller_participant_id' => $document['seller_endpoint'],
				'issue_date'            => $document['issue_date'],
				'currency_code'         => $document['currency'],
				'total_payable_amount'  => $document['totals']['payable'],
				'ubl_xml'               => $document['xml'],
				'xml_hash_sha256'       => hash( 'sha256', $document['xml'] ),
				'status'                => 'generated',
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false !== $ok ) {
			do_action( 'wm_peppol_invoice_recorded', (int) $wpdb->insert_id, $document['invoice_number'], 'admin' );
		}

		$this->finish( false === $ok ? 'generator' : 'overview', false === $ok ? 'insert_failed' : 'generated' );
	}

	/** Write the options the generator, the dispatch client and the adapter read. */
	private function save_settings(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_form_actions().
		foreach ( Settings::SELLER_OPTIONS as $field => $option ) {
			$value = isset( $_POST[ 'seller_' . $field ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ 'seller_' . $field ] ) ) ) : '';
			if ( in_array( $field, [ 'country', 'vat_id', 'iban' ], true ) ) {
				$value = strtoupper( str_replace( ' ', '', $value ) );
			}
			update_option( $option, $value, false );
		}
		foreach ( [ 'kosit_url' => Settings::KOSIT_URL_OPTION, 'phase4_url' => Settings::PHASE4_URL_OPTION ] as $field => $option ) {
			update_option( $option, isset( $_POST[ $field ] ) ? esc_url_raw( trim( (string) wp_unslash( $_POST[ $field ] ) ) ) : '', false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() sanitises the URL.
		}
		$pins_raw = isset( $_POST['trusted_signer_sha256'] ) ? sanitize_text_field( wp_unslash( $_POST['trusted_signer_sha256'] ) ) : '';
		update_option( Settings::TRUSTED_SIGNER_SHA256_OPTION, implode( ', ', Settings::parse_fingerprints( $pins_raw ) ), false );
		$bundle_raw = isset( $_POST['trust_bundle_pem'] ) ? (string) wp_unslash( $_POST['trust_bundle_pem'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitise_pem_bundle() validates strict base64 PEM blocks.
		update_option( Settings::TRUST_BUNDLE_PEM_OPTION, Settings::sanitise_pem_bundle( $bundle_raw ), false );
		$require_anchor = isset( $_POST['require_trust_anchor'] ) && '1' === (string) wp_unslash( $_POST['require_trust_anchor'] ) ? '1' : '0';
		update_option( Settings::REQUIRE_TRUST_ANCHOR_OPTION, $require_anchor, false );
		$delete_on_uninstall = isset( $_POST['delete_data_on_uninstall'] ) && '1' === (string) wp_unslash( $_POST['delete_data_on_uninstall'] ) ? '1' : '0';
		update_option( Settings::DELETE_DATA_ON_UNINSTALL_OPTION, $delete_on_uninstall, false );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Demo rows: status "demo", numbers DEMO-2026-000n, no XML. Rows that already exist are left alone.
	 *
	 * @return int Rows added.
	 */
	private function seed_demo_data(): int {
		global $wpdb;
		$table = InvoiceStore::invoices_table();
		$now   = current_time( 'mysql', true );
		$added = 0;

		foreach ( [ 1 => 4500.00, 2 => 12850.50, 3 => 890.00 ] as $n => $amount ) {
			$number = sprintf( 'DEMO-2026-%04d', $n );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE invoice_number = %s', $table, $number ) ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				$table,
				[
					'invoice_number'        => $number,
					'buyer_participant_id'  => 'DEMO:KAEUFER-' . $n,
					'seller_participant_id' => 'DEMO:VERKAEUFER',
					'issue_date'            => gmdate( 'Y-m-d' ),
					'currency_code'         => 'EUR',
					'total_payable_amount'  => $amount,
					'ubl_xml'               => '',
					'xml_hash_sha256'       => hash( 'sha256', '' ),
					'status'                => InvoiceStore::DEMO_STATUS,
					'created_at'            => $now,
					'updated_at'            => $now,
				],
				[ '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$added += false === $ok ? 0 : 1;
		}

		return $added;
	}

	/**
	 * Delete the demo rows, current and legacy (see InvoiceStore::demo_ids()). Only on the admin's request.
	 *
	 * @return int Rows removed.
	 */
	private function remove_demo_data(): int {
		global $wpdb;
		$removed = 0;
		foreach ( InvoiceStore::demo_ids() as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$removed += (int) $wpdb->delete( InvoiceStore::invoices_table(), [ 'id' => $id ], [ '%d' ] );
		}
		return $removed;
	}

	/**
	 * Redirect back to a tab with a notice; a detail text waits in a transient of the current user.
	 *
	 * @param string $tab    Tab.
	 * @param string $notice Notice code.
	 * @param string $detail Detail text.
	 */
	private function finish( string $tab, string $notice, string $detail = '' ): void {
		if ( '' !== $detail ) {
			set_transient( 'wm_peppol_notice_' . get_current_user_id(), $detail, MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( add_query_arg( [ 'page' => self::PAGE, 'tab' => $tab, 'wm_peppol_notice' => $notice ], admin_url( 'admin.php' ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Page
	// -----------------------------------------------------------------------

	public function render_dashboard_page(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- selects a tab.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'overview';

		$labels = [
			'overview'  => __( 'Übersicht', 'wm-peppol-as4-gateway' ),
			'generator' => __( 'Rechnung erzeugen', 'wm-peppol-as4-gateway' ),
			'settings'  => __( 'Einstellungen', 'wm-peppol-as4-gateway' ),
			'about'     => __( 'Was das Plugin tut', 'wm-peppol-as4-gateway' ),
		];
		?>
		<div class="wrap wm-admin wm-peppol-admin-wrap">
			<header class="wm-admin-header">
				<div class="wm-header-brand">
					<div class="wm-header-icon">
						<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true" focusable="false"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
					</div>
					<div>
						<h1><?php esc_html_e( 'Peppol Gateway', 'wm-peppol-as4-gateway' ); ?></h1>
						<p class="wm-subtitle"><?php esc_html_e( 'UBL-2.1-Rechnungen nach Peppol BIS Billing 3.0 erzeugen, speichern und an einen konfigurierten Phase4-Dienst übergeben.', 'wm-peppol-as4-gateway' ); ?></p>
					</div>
				</div>
				<div class="wm-header-badges">
					<span class="wm-badge-family" title="<?php esc_attr_e( 'Part of the WM Suite plugin family', 'wm-peppol-as4-gateway' ); ?>">
						<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="4" r="2"/><circle cx="19" cy="16" r="2"/><circle cx="5" cy="16" r="2"/><path d="M12 7v2M13.9 13.2l3.4 2M10.1 13.2l-3.4 2"/></svg>
						<?php esc_html_e( 'WM Suite', 'wm-peppol-as4-gateway' ); ?>
						<span class="wm-badge-family-state"><?php echo defined( 'WM_SUITE_HUB_VERSION' ) ? esc_html__( 'Hub', 'wm-peppol-as4-gateway' ) : esc_html__( 'Standalone', 'wm-peppol-as4-gateway' ); ?></span>
					</span>
				</div>
			</header>

			<?php $this->render_notice(); ?>

			<nav class="wm-nav-tabs" aria-label="<?php esc_attr_e( 'Bereiche', 'wm-peppol-as4-gateway' ); ?>">
				<?php foreach ( $labels as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => $key ], admin_url( 'admin.php' ) ) ); ?>" class="wm-tab-link<?php echo $key === $tab ? ' active' : ''; ?>"<?php echo $key === $tab ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php
			switch ( $tab ) {
				case 'generator':
					$this->render_generator();
					break;
				case 'settings':
					$this->render_settings();
					break;
				case 'about':
					$this->render_about();
					break;
				default:
					$this->render_overview();
			}
			?>
		</div>
		<?php
	}

	private function render_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$code = isset( $_GET['wm_peppol_notice'] ) ? sanitize_key( wp_unslash( $_GET['wm_peppol_notice'] ) ) : '';
		if ( '' === $code ) {
			return;
		}
		$key    = 'wm_peppol_notice_' . get_current_user_id();
		$detail = (string) get_transient( $key );
		delete_transient( $key );

		$messages = [
			'generated'       => [ 'success', __( 'Die E-Rechnung wurde erzeugt und gespeichert. Über dieses Formular wird sie nicht versendet.', 'wm-peppol-as4-gateway' ) ],
			'generate_failed' => [ 'error', __( 'Die E-Rechnung wurde nicht erzeugt.', 'wm-peppol-as4-gateway' ) ],
			'duplicate'       => [ 'error', __( 'Eine Rechnung mit dieser Nummer ist bereits gespeichert.', 'wm-peppol-as4-gateway' ) ],
			'insert_failed'   => [ 'error', __( 'Die Rechnung wurde erzeugt, aber nicht gespeichert.', 'wm-peppol-as4-gateway' ) ],
			'saved'           => [ 'success', __( 'Einstellungen gespeichert.', 'wm-peppol-as4-gateway' ) ],
			'seeded'          => [ 'success', __( 'Beispielrechnungen sind als Beispiel markiert und werden im öffentlichen Statusportal nicht gemeldet.', 'wm-peppol-as4-gateway' ) ],
			'removed'         => [ 'success', __( 'Beispielrechnungen entfernt.', 'wm-peppol-as4-gateway' ) ],
		];
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		[ $type, $message ] = $messages[ $code ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $type ),
			esc_html( trim( $message . ' ' . $detail ) )
		);
	}

	private function render_overview(): void {
		global $wpdb;
		$counts  = InvoiceStore::counts();
		$missing = Settings::missing_seller_fields();

		if ( ! $counts['tables'] ) {
			echo '<div class="wm-inline-notice is-danger">' . esc_html__( 'Die Tabellen des Plugins fehlen. Bitte das Plugin deaktivieren und wieder aktivieren.', 'wm-peppol-as4-gateway' ) . '</div>';
			return;
		}

		$demo_ids = InvoiceStore::demo_ids();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invoices = $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_number, buyer_participant_id, issue_date, currency_code, total_payable_amount, status FROM %i ORDER BY id DESC LIMIT 50', InvoiceStore::invoices_table() ), ARRAY_A );
		$invoices = is_array( $invoices ) ? $invoices : [];
		?>
		<?php if ( [] !== $missing ) : ?>
			<div class="wm-inline-notice is-warning">
				<?php
				/* translators: %s: link to the settings tab */
				printf( esc_html__( 'Verkäuferdaten unvollständig: Es kann keine E-Rechnung erzeugt werden. %s', 'wm-peppol-as4-gateway' ), '<a href="' . esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings' ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Zu den Einstellungen', 'wm-peppol-as4-gateway' ) . '</a>' );
				?>
			</div>
		<?php elseif ( ! Settings::dispatch_configured() ) : ?>
			<div class="wm-inline-notice is-warning"><?php esc_html_e( 'Versand nicht konfiguriert: Erzeugte Rechnungen werden gespeichert, aber nicht übergeben.', 'wm-peppol-as4-gateway' ); ?></div>
		<?php endif; ?>

		<div class="wm-grid-4">
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Gespeicherte Rechnungen', 'wm-peppol-as4-gateway' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['invoices'] ) ); ?></span>
				<?php /* translators: %d: number of demo rows */ ?>
				<span class="wm-stat-delta"><?php echo esc_html( sprintf( __( 'ohne %d Beispielrechnungen', 'wm-peppol-as4-gateway' ), $counts['demo'] ) ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Übergaben an Phase4', 'wm-peppol-as4-gateway' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['transmissions'] ) ); ?></span>
				<span class="wm-stat-delta"><?php esc_html_e( 'mit messageId', 'wm-peppol-as4-gateway' ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Empfangsquittungen', 'wm-peppol-as4-gateway' ); ?></span>
				<span class="wm-stat-value"><?php echo esc_html( number_format_i18n( $counts['delivered'] ) ); ?></span>
				<span class="wm-stat-delta"><?php esc_html_e( 'positiv, per Webhook', 'wm-peppol-as4-gateway' ); ?></span>
			</div>
			<div class="wm-stat-card">
				<span class="wm-stat-label"><?php esc_html_e( 'Verkäuferdaten', 'wm-peppol-as4-gateway' ); ?></span>
				<span class="wm-stat-value"><?php echo [] === $missing ? esc_html__( 'vollständig', 'wm-peppol-as4-gateway' ) : esc_html( number_format_i18n( count( $missing ) ) ); ?></span>
				<span class="wm-stat-delta"><?php echo [] === $missing ? esc_html__( 'für die Rechnungserzeugung', 'wm-peppol-as4-gateway' ) : esc_html__( 'Angaben fehlen', 'wm-peppol-as4-gateway' ); ?></span>
			</div>
		</div>

		<section class="wm-card" aria-labelledby="wm-peppol-invoices-title">
			<h2 id="wm-peppol-invoices-title"><?php esc_html_e( 'Rechnungen', 'wm-peppol-as4-gateway' ); ?></h2>
			<p class="wm-card-caption"><?php esc_html_e( 'Die letzten 50 gespeicherten Rechnungen mit ihrem gespeicherten Status.', 'wm-peppol-as4-gateway' ); ?></p>

			<table class="wm-table widefat">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Rechnungsnummer', 'wm-peppol-as4-gateway' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Peppol-ID des Käufers', 'wm-peppol-as4-gateway' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Zahlbetrag', 'wm-peppol-as4-gateway' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'wm-peppol-as4-gateway' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Rechnungsdatum', 'wm-peppol-as4-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( [] === $invoices ) : ?>
						<tr><td colspan="5"><?php esc_html_e( 'Noch keine Rechnungen gespeichert.', 'wm-peppol-as4-gateway' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $invoices as $invoice ) : ?>
						<?php [ $label, $class ] = self::status_pill( (string) $invoice['status'], in_array( (int) $invoice['id'], $demo_ids, true ) ); ?>
						<tr>
							<td><code><?php echo esc_html( $invoice['invoice_number'] ); ?></code></td>
							<td><code><?php echo esc_html( $invoice['buyer_participant_id'] ); ?></code></td>
							<td><?php echo esc_html( number_format_i18n( (float) $invoice['total_payable_amount'], 2 ) . ' ' . $invoice['currency_code'] ); ?></td>
							<td><span class="wm-status-pill <?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></span></td>
							<td><?php echo esc_html( (string) $invoice['issue_date'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>

		<section class="wm-card" aria-labelledby="wm-peppol-demo-title">
			<h2 id="wm-peppol-demo-title"><?php esc_html_e( 'Beispielrechnungen', 'wm-peppol-as4-gateway' ); ?></h2>
			<p class="wm-card-caption"><?php esc_html_e( 'Beispielrechnungen haben kein XML, zählen nicht als Rechnungen und werden im öffentlichen Statusportal nicht gemeldet. Entfernen löscht nur sie, auch die drei Beispielzeilen früherer Versionen.', 'wm-peppol-as4-gateway' ); ?></p>
			<div class="wm-form-row">
				<form method="post">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wm_peppol_action" value="seed_demo_data" />
					<button type="submit" class="button button-secondary"><?php esc_html_e( 'Beispielrechnungen anlegen', 'wm-peppol-as4-gateway' ); ?></button>
				</form>
				<?php if ( [] !== $demo_ids ) : ?>
					<form method="post">
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<input type="hidden" name="wm_peppol_action" value="remove_demo_data" />
						<?php /* translators: %d: number of demo rows */ ?>
						<button type="submit" class="button button-secondary"><?php echo esc_html( sprintf( _n( '%d Beispielrechnung entfernen', '%d Beispielrechnungen entfernen', count( $demo_ids ), 'wm-peppol-as4-gateway' ), count( $demo_ids ) ) ); ?></button>
					</form>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Label and family class of a stored status.
	 *
	 * @param string $status Stored status.
	 * @param bool   $demo   Row is a demo row.
	 * @return array{0: string, 1: string}
	 */
	private static function status_pill( string $status, bool $demo ): array {
		if ( $demo ) {
			return [ __( 'Beispiel', 'wm-peppol-as4-gateway' ), 'wm-status-warn' ];
		}
		return match ( $status ) {
			'generated'       => [ __( 'Erzeugt, nicht versendet', 'wm-peppol-as4-gateway' ), '' ],
			'sent'            => [ __( 'An Phase4 übergeben', 'wm-peppol-as4-gateway' ), '' ],
			'delivered'       => [ __( 'Zugestellt (Quittung)', 'wm-peppol-as4-gateway' ), 'wm-status-healthy' ],
			'failed'          => [ __( 'Zustellung fehlgeschlagen', 'wm-peppol-as4-gateway' ), 'wm-status-error' ],
			'dispatch_failed' => [ __( 'Versand fehlgeschlagen', 'wm-peppol-as4-gateway' ), 'wm-status-error' ],
			default           => [ $status, '' ],
		};
	}

	private function render_generator(): void {
		$missing = Settings::missing_seller_fields();
		if ( [] !== $missing ) {
			echo '<div class="wm-inline-notice is-warning">';
			/* translators: %s: link to the settings tab */
			printf( esc_html__( 'Ohne vollständige Verkäuferdaten wird keine Rechnung erzeugt. %s', 'wm-peppol-as4-gateway' ), '<a href="' . esc_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings' ], admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Zu den Einstellungen', 'wm-peppol-as4-gateway' ) . '</a>' );
			echo '</div>';
			return;
		}

		$fields = [
			'invoice_number'    => [ __( 'Rechnungsnummer', 'wm-peppol-as4-gateway' ), '', true, 64 ],
			'buyer_reference'   => [ __( 'Käuferreferenz (BT-10), z. B. die Leitweg-ID', 'wm-peppol-as4-gateway' ), '', true, 64 ],
			'buyer_name'        => [ __( 'Name des Käufers', 'wm-peppol-as4-gateway' ), '', true, 200 ],
			'buyer_street'      => [ __( 'Straße', 'wm-peppol-as4-gateway' ), '', false, 200 ],
			'buyer_postal_code' => [ __( 'Postleitzahl', 'wm-peppol-as4-gateway' ), '', false, 20 ],
			'buyer_city'        => [ __( 'Ort', 'wm-peppol-as4-gateway' ), '', false, 100 ],
			'buyer_country'     => [ __( 'Land (zweistelliger Code)', 'wm-peppol-as4-gateway' ), 'DE', true, 2 ],
			'buyer_endpoint'    => [ __( 'Peppol-ID des Käufers (Schema:Kennung)', 'wm-peppol-as4-gateway' ), '', true, 64 ],
			'buyer_vat_id'      => [ __( 'USt-IdNr. des Käufers (optional)', 'wm-peppol-as4-gateway' ), '', false, 20 ],
			'line_description'  => [ __( 'Bezeichnung der Leistung', 'wm-peppol-as4-gateway' ), '', true, 200 ],
			'quantity'          => [ __( 'Menge', 'wm-peppol-as4-gateway' ), '1', true, 12 ],
			'unit_price'        => [ __( 'Nettopreis je Einheit', 'wm-peppol-as4-gateway' ), '', true, 16 ],
			'vat_rate'          => [ __( 'Umsatzsteuersatz in Prozent', 'wm-peppol-as4-gateway' ), '19', true, 6 ],
		];
		?>
		<section class="wm-card" aria-labelledby="wm-peppol-generator-title">
			<h2 id="wm-peppol-generator-title"><?php esc_html_e( 'Rechnung erzeugen', 'wm-peppol-as4-gateway' ); ?></h2>
			<p class="wm-card-caption"><?php esc_html_e( 'Erzeugt eine UBL-Rechnung mit einer Position und speichert sie. Versendet wird nichts; Verkäuferdaten kommen aus den Einstellungen.', 'wm-peppol-as4-gateway' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wm_peppol_action" value="generate_invoice" />
				<?php foreach ( $fields as $name => [ $label, $value, $required, $max ] ) : ?>
					<div class="wm-field-card">
						<label class="wm-field-title" for="wm-peppol-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
						<input type="text" class="wm-input" id="wm-peppol-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" maxlength="<?php echo esc_attr( (string) $max ); ?>"<?php echo $required ? ' required' : ''; ?> />
					</div>
				<?php endforeach; ?>
				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Rechnung erzeugen und speichern', 'wm-peppol-as4-gateway' ); ?></button>
			</form>
		</section>
		<?php
	}

	private function render_settings(): void {
		$seller = Settings::seller();
		$labels = [
			'endpoint'    => __( 'Peppol-ID des Verkäufers (Schema:Kennung)', 'wm-peppol-as4-gateway' ),
			'name'        => __( 'Name', 'wm-peppol-as4-gateway' ),
			'legal_name'  => __( 'Rechtlicher Name (leer: wie Name)', 'wm-peppol-as4-gateway' ),
			'street'      => __( 'Straße', 'wm-peppol-as4-gateway' ),
			'postal_code' => __( 'Postleitzahl', 'wm-peppol-as4-gateway' ),
			'city'        => __( 'Ort', 'wm-peppol-as4-gateway' ),
			'country'     => __( 'Land (zweistelliger Code)', 'wm-peppol-as4-gateway' ),
			'vat_id'      => __( 'USt-IdNr.', 'wm-peppol-as4-gateway' ),
			'company_id'  => __( 'Registernummer (optional)', 'wm-peppol-as4-gateway' ),
			'iban'        => __( 'IBAN für Zahlung per Überweisung', 'wm-peppol-as4-gateway' ),
		];
		?>
		<section class="wm-card" aria-labelledby="wm-peppol-settings-title">
			<h2 id="wm-peppol-settings-title"><?php esc_html_e( 'Einstellungen', 'wm-peppol-as4-gateway' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wm_peppol_action" value="save_settings" />

				<h3><?php esc_html_e( 'Verkäufer', 'wm-peppol-as4-gateway' ); ?></h3>
				<p class="wm-card-caption"><?php esc_html_e( 'Diese Angaben stehen auf jeder erzeugten Rechnung. Fehlt eine Pflichtangabe, erzeugt das Plugin keine Rechnung.', 'wm-peppol-as4-gateway' ); ?></p>
				<?php foreach ( $labels as $field => $label ) : ?>
					<div class="wm-field-card">
						<label class="wm-field-title" for="wm-peppol-seller-<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $label ); ?></label>
						<input type="text" class="wm-input" id="wm-peppol-seller-<?php echo esc_attr( $field ); ?>" name="seller_<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( $seller[ $field ] ); ?>" maxlength="200" />
					</div>
				<?php endforeach; ?>

				<h3><?php esc_html_e( 'Versand', 'wm-peppol-as4-gateway' ); ?></h3>
				<p class="wm-card-caption"><?php esc_html_e( 'Beide Dienste sind eigene Programme und nicht Teil des Plugins. Solange eine der Adressen fehlt, wird nichts versendet. Konstanten WM_PEPPOL_KOSIT_URL und WM_PEPPOL_PHASE4_URL in der wp-config.php haben Vorrang.', 'wm-peppol-as4-gateway' ); ?></p>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-kosit-url"><?php esc_html_e( 'Adresse des KoSIT-Validators', 'wm-peppol-as4-gateway' ); ?></label>
					<input type="url" class="wm-input" id="wm-peppol-kosit-url" name="kosit_url" value="<?php echo esc_attr( (string) get_option( Settings::KOSIT_URL_OPTION, '' ) ); ?>" />
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-phase4-url"><?php esc_html_e( 'Adresse des Phase4-Dienstes', 'wm-peppol-as4-gateway' ); ?></label>
					<input type="url" class="wm-input" id="wm-peppol-phase4-url" name="phase4_url" value="<?php echo esc_attr( (string) get_option( Settings::PHASE4_URL_OPTION, '' ) ); ?>" />
				</div>

				<h3><?php esc_html_e( 'Empfangsquittungen', 'wm-peppol-as4-gateway' ); ?></h3>
				<p class="wm-card-caption">
					<?php
					echo esc_html(
						'' !== \WM_Peppol_Webhook_Receiver::secret()
							? __( 'Ein gemeinsames Geheimnis ist gesetzt; Quittungen mit gültiger Signatur werden angenommen.', 'wm-peppol-as4-gateway' )
							: __( 'Kein gemeinsames Geheimnis gesetzt; alle Quittungen werden abgelehnt. Setzen per Konstante WM_PEPPOL_RECEIPT_SECRET oder wp option update wm_peppol_receipt_secret.', 'wm-peppol-as4-gateway' )
					);
					?>
				</p>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-trusted-signer-sha256"><?php esc_html_e( 'Erlaubte Zertifikats-Fingerabdrücke für AS4-Quittungen (SHA-256, optional)', 'wm-peppol-as4-gateway' ); ?></label>
					<input type="text" class="wm-input" id="wm-peppol-trusted-signer-sha256" name="trusted_signer_sha256" value="<?php echo esc_attr( implode( ', ', Settings::trusted_signer_fingerprints() ) ); ?>" />
					<p class="wm-field-desc"><?php esc_html_e( 'Kommagetrennte 64-stellige SHA-256-Fingerabdrücke der erwarteten Access-Point-Signaturzertifikate. Konstante WM_PEPPOL_TRUSTED_SIGNER_SHA256 in der wp-config.php hat Vorrang.', 'wm-peppol-as4-gateway' ); ?></p>
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-trust-bundle-pem"><?php esc_html_e( 'Lokales CA-Zertifikatsbündel im PEM-Format (OpenPeppol-PKI, optional)', 'wm-peppol-as4-gateway' ); ?></label>
					<textarea class="wm-input" id="wm-peppol-trust-bundle-pem" name="trust_bundle_pem" rows="4"><?php echo esc_textarea( (string) get_option( Settings::TRUST_BUNDLE_PEM_OPTION, '' ) ); ?></textarea>
					<p class="wm-field-desc"><?php esc_html_e( 'Ein oder mehrere vertrauenswürdige Root- und Zwischenzertifikate im PEM-Format zur rein lokalen Kettenprüfung ohne Netzwerkzugriff. Konstanten WM_PEPPOL_TRUST_BUNDLE_PEM und WM_PEPPOL_TRUST_BUNDLE_FILE haben Vorrang.', 'wm-peppol-as4-gateway' ); ?></p>
				</div>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-require-trust-anchor">
						<input type="checkbox" id="wm-peppol-require-trust-anchor" name="require_trust_anchor" value="1"<?php echo Settings::trust_anchor_enforced() ? ' checked="checked"' : ''; ?> />
						<?php esc_html_e( 'AS4-Quittungen ohne hinterlegten Fingerabdruck oder CA-Zertifikatsbündel ablehnen', 'wm-peppol-as4-gateway' ); ?>
					</label>
					<p class="wm-field-desc"><?php esc_html_e( 'Wenn aktiviert oder per Konstante WM_PEPPOL_REQUIRE_TRUST_ANCHOR gesetzt, werden selbstsignierte oder nicht verankerte Quittungen mit HTTP 422 abgewiesen.', 'wm-peppol-as4-gateway' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Deinstallation', 'wm-peppol-as4-gateway' ); ?></h3>
				<div class="wm-field-card">
					<label class="wm-field-title" for="wm-peppol-delete-data-on-uninstall">
						<input type="checkbox" id="wm-peppol-delete-data-on-uninstall" name="delete_data_on_uninstall" value="1"<?php echo Settings::delete_data_on_uninstall() ? ' checked="checked"' : ''; ?> />
						<?php esc_html_e( 'Beim Deinstallieren des Plugins alle Optionen und die drei Datenbanktabellen unwiderruflich löschen', 'wm-peppol-as4-gateway' ); ?>
					</label>
					<p class="wm-field-desc"><?php esc_html_e( 'Standardmäßig bleiben alle gespeicherten Rechnungen, Übertragungen, das Quittungsregister und die Einstellungen erhalten, wenn das Plugin entfernt wird.', 'wm-peppol-as4-gateway' ); ?></p>
				</div>

				<button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Einstellungen speichern', 'wm-peppol-as4-gateway' ); ?></button>
			</form>
		</section>
		<?php
	}

	private function render_about(): void {
		$does = [
			__( 'Erzeugt Rechnungen in UBL 2.1 mit der Spezifikationskennung von Peppol BIS Billing 3.0, mit einer Umsatzsteueraufschlüsselung je Steuerkategorie und Steuersatz.', 'wm-peppol-as4-gateway' ),
			__( 'Erzeugt keine Rechnung, wenn Pflichtangaben fehlen, statt sie zu ergänzen.', 'wm-peppol-as4-gateway' ),
			__( 'Prüft Dokumente auf Pflichtfelder, Codes und Summenregeln. Das ist keine Schema- und keine Schematron-Prüfung.', 'wm-peppol-as4-gateway' ),
			__( 'Übergibt Rechnungen abgeschlossener WooCommerce-Bestellungen an einen KoSIT-Validator und einen Phase4-Dienst, wenn beide konfiguriert sind, und nimmt signierte Empfangsquittungen an.', 'wm-peppol-as4-gateway' ),
			__( 'Zeigt Käufern im Statusportal den gespeicherten Stand einer Rechnung.', 'wm-peppol-as4-gateway' ),
		];
		$does_not = [
			__( 'Keine eigene AS4-Übertragung, Signatur oder Verschlüsselung: Das übernimmt der Phase4-Dienst.', 'wm-peppol-as4-gateway' ),
			__( 'Keine Gutschriften in der UBL-Syntax CreditNote und keine Steuerbefreiungen.', 'wm-peppol-as4-gateway' ),
			__( 'Kein Export-Archiv: Gespeicherte Rechnungen und das Quittungsregister bleiben beim Deinstallieren standardmäßig erhalten und werden nur gelöscht, wenn dies in den Einstellungen ausdrücklich aktiviert wurde.', 'wm-peppol-as4-gateway' ),
		];
		?>
		<section class="wm-card" aria-labelledby="wm-peppol-about-title">
			<h2 id="wm-peppol-about-title"><?php esc_html_e( 'Was das Plugin tut', 'wm-peppol-as4-gateway' ); ?></h2>
			<ul>
				<?php foreach ( $does as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
			<h3><?php esc_html_e( 'Was es nicht tut', 'wm-peppol-as4-gateway' ); ?></h3>
			<ul>
				<?php foreach ( $does_not as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}
}
