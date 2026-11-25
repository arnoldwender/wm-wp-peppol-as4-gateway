<?php
/**
 * Public invoice status portal: shortcodes [wm_peppol_invoice_portal] and [wm_e_invoice_lookup], and the
 * server-rendered block wm/peppol-invoice-portal (no editor script; usable as block markup).
 *
 * Until 2026-09-15 the markup carried 44 inline hex colours, fixed element ids (a second portal on the page
 * duplicated six of them and got no handler), an emoji in the button, the heading "B2B E-Rechnungs-Verifikation
 * & UBL-Download" and the text "laden Sie die UBL-2.1-XML-Struktur herunter" although nothing is downloadable,
 * and "liegt vor (kryptografisch signiert)" for any stored receipt. Its script strings went through
 * esc_html__() and then textContent, so an "&" in a translation would have shown as "&amp;". Colours now live
 * in assets/css/wm-peppol-portal.css, the script in assets/js/wm-peppol-portal.js, strings reach the script
 * unescaped in a JSON data attribute, and each instance has its own ids.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InvoicePortal {

	public const HANDLE        = 'wm-peppol-portal';
	public const EDITOR_HANDLE = 'wm-peppol-blocks-editor';

	/** Portals rendered in this request; gives each one its own ids. */
	private static int $instances = 0;

	public function init(): void {
		add_shortcode( 'wm_peppol_invoice_portal', [ $this, 'render_portal' ] );
		add_shortcode( 'wm_e_invoice_lookup', [ $this, 'render_portal' ] );
		add_action( 'init', [ $this, 'register_block' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
	}

	public function register_block(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$asset_file = WM_PEPPOL_PLUGIN_DIR . 'build/index.asset.php';
		$asset      = is_file( $asset_file ) ? require $asset_file : [
			'dependencies' => [ 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render' ],
			'version'      => WM_PEPPOL_VERSION,
		];

		wp_register_script(
			self::EDITOR_HANDLE,
			WM_PEPPOL_URL . 'build/index.js',
			$asset['dependencies'],
			(string) $asset['version'],
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations(
				self::EDITOR_HANDLE,
				'wm-peppol-as4-gateway',
				WM_PEPPOL_PLUGIN_DIR . 'languages'
			);
		}

		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			$this->register_assets();
		}

		register_block_type(
			WM_PEPPOL_PLUGIN_DIR . 'blocks/invoice-portal',
			[
				'editor_script'   => self::EDITOR_HANDLE,
				'style'           => self::HANDLE,
				'render_callback' => [ $this, 'render_portal' ],
			]
		);
	}

	public function register_assets(): void {
		$root = dirname( __DIR__, 2 ) . '/assets/';
		wp_register_style( self::HANDLE, WM_PEPPOL_URL . 'assets/css/wm-peppol-portal.css', [], (string) ( @filemtime( $root . 'css/wm-peppol-portal.css' ) ?: WM_PEPPOL_VERSION ) );
		wp_register_script( self::HANDLE, WM_PEPPOL_URL . 'assets/js/wm-peppol-portal.js', [], (string) ( @filemtime( $root . 'js/wm-peppol-portal.js' ) ?: WM_PEPPOL_VERSION ), true );
	}

	/**
	 * Render the status lookup portal (shortcode and server-rendered Gutenberg block).
	 *
	 * @param mixed $attributes Block or shortcode attributes.
	 * @return string
	 */
	public function render_portal( $attributes = [] ): string {
		if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
			$this->register_assets();
		}
		wp_enqueue_style( self::HANDLE );
		wp_enqueue_script( self::HANDLE );

		$attrs        = is_array( $attributes ) ? $attributes : [];
		$show_heading = ! array_key_exists( 'showHeading', $attrs ) || (bool) $attrs['showHeading'];
		$show_lead    = ! array_key_exists( 'showLead', $attrs ) || (bool) $attrs['showLead'];

		$id     = 'wm-peppol-portal-' . ( ++self::$instances );
		$config = [
			'endpoint' => esc_url_raw( rest_url( 'wm-peppol/v1/invoice-status' ) ),
			// Plain strings: the script writes them with textContent, which escapes by itself.
			'i18n'     => [
				'loading'   => __( 'Status wird abgefragt …', 'wm-peppol-as4-gateway' ),
				'notFound'  => __( 'Zu diesen Angaben liegt keine Rechnung vor.', 'wm-peppol-as4-gateway' ),
				'found'     => __( 'Rechnung gefunden', 'wm-peppol-as4-gateway' ),
				'failed'    => __( 'Die Abfrage ist fehlgeschlagen.', 'wm-peppol-as4-gateway' ),
				'status'    => __( 'Gespeicherter Status', 'wm-peppol-as4-gateway' ),
				'transport' => __( 'Übergabe an Phase4', 'wm-peppol-as4-gateway' ),
				'none'      => __( 'keine Übergabe erfasst', 'wm-peppol-as4-gateway' ),
				'receipt'   => __( 'Empfangsquittung', 'wm-peppol-as4-gateway' ),
				'yes'       => __( 'liegt vor', 'wm-peppol-as4-gateway' ),
				'no'        => __( 'liegt nicht vor', 'wm-peppol-as4-gateway' ),
				'error'     => __( 'Fehlercode der Übertragung', 'wm-peppol-as4-gateway' ),
				'amount'    => __( 'Zahlbetrag', 'wm-peppol-as4-gateway' ),
				'issued'    => __( 'Rechnungsdatum', 'wm-peppol-as4-gateway' ),
				'xml'       => __( 'SHA-256 des gespeicherten XML', 'wm-peppol-as4-gateway' ),
			],
		];

		ob_start();
		?>
		<section class="wm-peppol-portal" id="<?php echo esc_attr( $id ); ?>" <?php if ( $show_heading ) : ?>aria-labelledby="<?php echo esc_attr( $id . '-title' ); ?>"<?php else : ?>aria-label="<?php esc_attr_e( 'Rechnungsstatus abfragen', 'wm-peppol-as4-gateway' ); ?>"<?php endif; ?> data-wm-peppol-portal="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>">
			<?php if ( $show_heading ) : ?>
				<h2 class="wm-peppol-portal__title" id="<?php echo esc_attr( $id . '-title' ); ?>"><?php esc_html_e( 'Rechnungsstatus abfragen', 'wm-peppol-as4-gateway' ); ?></h2>
			<?php endif; ?>
			<?php if ( $show_lead ) : ?>
				<p class="wm-peppol-portal__lead"><?php esc_html_e( 'Geben Sie die Rechnungsnummer und Ihre Peppol-ID an. Angezeigt wird der in diesem System gespeicherte Stand. Das Dokument wird hier weder gegen EN 16931 geprüft noch zum Herunterladen angeboten.', 'wm-peppol-as4-gateway' ); ?></p>
			<?php endif; ?>

			<form class="wm-peppol-portal__form">
				<div class="wm-peppol-portal__fields">
					<p class="wm-peppol-portal__field">
						<label for="<?php echo esc_attr( $id . '-number' ); ?>"><?php esc_html_e( 'Rechnungsnummer', 'wm-peppol-as4-gateway' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id . '-number' ); ?>" name="invoice_number" required maxlength="64" autocomplete="off" />
					</p>
					<p class="wm-peppol-portal__field">
						<label for="<?php echo esc_attr( $id . '-buyer' ); ?>"><?php esc_html_e( 'Ihre Peppol-ID', 'wm-peppol-as4-gateway' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $id . '-buyer' ); ?>" name="buyer_participant_id" required maxlength="64" autocomplete="off" placeholder="<?php esc_attr_e( 'Schema:Kennung', 'wm-peppol-as4-gateway' ); ?>" />
					</p>
				</div>
				<button type="submit" class="wm-peppol-portal__submit"><?php esc_html_e( 'Status abfragen', 'wm-peppol-as4-gateway' ); ?></button>
			</form>

			<div class="wm-peppol-portal__result" role="status" aria-live="polite" hidden>
				<h3 class="wm-peppol-portal__result-title"></h3>
				<dl class="wm-peppol-portal__result-body"></dl>
			</div>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
