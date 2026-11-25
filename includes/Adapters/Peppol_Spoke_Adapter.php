<?php
/**
 * WM Suite Peppol AS4 E-Invoice Gateway — Suite Hub Spoke Adapter
 *
 * Implements the WM Suite Hub contract (WM_Plugin_Module_Interface).
 *
 * Until 2026-09-15 the health was HEALTHY on every install, one without tables included, the telemetry carried
 * the literals EN_16931_COMPLIANT and OASIS_EBMS3_READY, the self-test called any document containing the
 * CustomizationID string "EN 16931 konform", and the SBOM named a WS-Security stack and a "§ 147 AO Invariable
 * SHA-256 Audit Log" that nothing writes. Health, telemetry and self-test now report measured state.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Adapters;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! interface_exists( 'ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface' ) ) {
	return;
}

use ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface;
use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Core\Plugin;
use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\UBL\InvoiceDataException;

final class Peppol_Spoke_Adapter implements WM_Plugin_Module_Interface {

	public function get_module_slug(): string {
		return 'wm-peppol-as4-gateway';
	}

	public function get_name(): string {
		return 'WM Peppol AS4 E-Invoice Gateway';
	}

	public function get_version(): string {
		return defined( 'WM_PEPPOL_VERSION' ) ? WM_PEPPOL_VERSION : '1.2.2';
	}

	public function get_description(): string {
		return 'UBL 2.1 invoices with the Peppol BIS Billing 3.0 specification identifier, structural checks, hand-over to a configured KoSIT validator and Phase4 service.';
	}

	/**
	 * ERROR without tables; NEEDS_CONFIG without complete seller data (no invoice can be built); DEGRADED when
	 * dispatch is configured but no receipt secret is set (every receipt is refused); HEALTHY otherwise.
	 */
	public function get_health_status(): string {
		if ( ! InvoiceStore::tables_exist() ) {
			return 'ERROR';
		}
		if ( [] !== Settings::missing_seller_fields() ) {
			return 'NEEDS_CONFIG';
		}
		if ( Settings::dispatch_configured() && '' === \WM_Peppol_Webhook_Receiver::secret() ) {
			return 'DEGRADED';
		}
		return 'HEALTHY';
	}

	public function get_sbom_data(): array {
		return [
			'bom-ref'     => 'pkg:wordpress/wm-peppol-as4-gateway@' . $this->get_version(),
			'type'        => 'application',
			'name'        => $this->get_name(),
			'version'     => $this->get_version(),
			'description' => $this->get_description(),
			'scope'       => 'required',
			'hashes'      => [
				[
					'alg'     => 'SHA-256',
					'content' => hash_file( 'sha256', WM_PEPPOL_FILE ),
				],
			],
			'licenses'    => [
				[
					'license' => [
						'id' => 'GPL-2.0-or-later',
					],
				],
			],
			'properties'  => [
				[
					'name'  => 'wm:standards:Peppol',
					'value' => 'Peppol BIS Billing 3.0 specification identifier on UBL 2.1 invoices; structural checks and the official EN 16931 Schematron (artefact 1.3.16), no XSD schema validation',
				],
				[
					'name'  => 'wm:transport:AS4',
					'value' => 'not implemented in the plugin; sending is delegated to a configured Phase4 service',
				],
			],
		];
	}

	public function get_dependencies(): array {
		return [];
	}

	public function get_settings_url(): ?string {
		return admin_url( 'admin.php?page=wm-peppol-gateway' );
	}

	public function get_telemetry_metrics(): array {
		$counts = InvoiceStore::counts();

		return [
			'tables_present'            => $counts['tables'],
			'stored_invoices'           => $counts['invoices'],
			'demo_rows'                 => $counts['demo'],
			'transmissions'             => $counts['transmissions'],
			'receipts_delivered'        => $counts['delivered'],
			'seller_data_complete'      => [] === Settings::missing_seller_fields(),
			'dispatch_configured'             => Settings::dispatch_configured(),
			'receipt_secret_configured'       => '' !== \WM_Peppol_Webhook_Receiver::secret(),
			'receipt_trust_anchor_configured' => Settings::trust_anchor_configured(),
			'receipt_trust_anchor_enforced'   => Settings::trust_anchor_enforced(),
			'smp_discovery'                   => 'not_implemented',
		];
	}

	public function execute_action( string $action, array $params = [] ): array {
		switch ( $action ) {
			case 'self_test':
			case 'generate_test_ubl':
				return $this->self_test();

			default:
				return [
					'success' => false,
					/* translators: %s: action name */
					'message' => sprintf( __( 'Unbekannte Spoke-Aktion "%s".', 'wm-peppol-as4-gateway' ), esc_html( $action ) ),
				];
		}
	}

	/**
	 * Build a test document from the stored seller data and run the plugin's structural checks on it.
	 * Nothing is stored or sent.
	 *
	 * @return array{success: bool, message: string}
	 */
	private function self_test(): array {
		$missing = Settings::missing_seller_fields();
		if ( [] !== $missing ) {
			return [
				'success' => false,
				/* translators: %s: missing seller fields */
				'message' => sprintf( __( 'Selbsttest nicht möglich: Verkäuferdaten fehlen (%s).', 'wm-peppol-as4-gateway' ), implode( ', ', $missing ) ),
			];
		}

		$seller = Settings::seller();
		try {
			$document = Plugin::instance()->get_ubl_generator()->build( [
				'invoice_number'   => 'SELBSTTEST',
				'buyer_reference'  => 'SELBSTTEST',
				'buyer'            => [
					'endpoint' => $seller['endpoint'],
					'name'     => 'Selbsttest',
					'country'  => $seller['country'],
				],
				'line_description' => 'Selbsttest',
				'net_amount'       => 100.00,
			] );
		} catch ( InvoiceDataException $e ) {
			return [ 'success' => false, 'message' => $e->describe() ];
		}

		$check = \WM_Peppol_Validator::validate( $document['xml'] );
		if ( ! $check['valid'] ) {
			return [
				'success' => false,
				/* translators: %s: failed checks */
				'message' => sprintf( __( 'Testdokument von den Strukturprüfungen abgelehnt: %s', 'wm-peppol-as4-gateway' ), implode( ' ', $check['errors'] ) ),
			];
		}

		return [
			'success' => true,
			/* translators: %d: size of the test document in bytes */
			'message' => sprintf( __( 'Testdokument erzeugt (%d Bytes) und von den Strukturprüfungen des Plugins angenommen. Nicht gespeichert, nicht gesendet, keine Schema- oder Schematron-Prüfung.', 'wm-peppol-as4-gateway' ), strlen( $document['xml'] ) ),
		];
	}
}
