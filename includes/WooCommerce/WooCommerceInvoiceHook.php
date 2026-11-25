<?php
/**
 * The WooCommerce order flow: one e-invoice per completed order that carries a Peppol receiver id.
 *
 * Until 2026-09-15 two flows ran on the same `woocommerce_order_status_completed`: this hook stored a Peppol
 * BIS document built from the order total with "Kunde"/"Unbekannt" as buyer, and WM_Peppol_Gateway posted a
 * second, different XRechnung document to Phase4 with a placeholder IBAN, no TaxTotal and a fixed 19 %. The
 * stored hash was never the hash of what was sent, and nothing wrote a transmission row. Now:
 *   - one document, built by UblInvoiceGenerator from the order lines, their tax rates and the billing address;
 *     the stored XML is the XML that is sent;
 *   - the seller comes from the settings; an order that cannot be described without invented data gets a note
 *     saying why and no invoice;
 *   - an order is invoiced once (invoice number INV-WC-<order id>);
 *   - sending is a scheduled event, and only when the KoSIT validator and the Phase4 sender are configured; the
 *     messageId the sender returns goes into wm_peppol_transmissions, where the receipt webhook finds it.
 *
 * Payment state is not derived from the order: the document states the order total as the amount due with the
 * seller's IBAN, as the flow always did, also for orders paid online.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\Validation\SchematronValidator;
use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

final class WooCommerceInvoiceHook {

	/** Scheduled event that sends one stored invoice. */
	public const DISPATCH_HOOK = 'wm_peppol_dispatch_invoice';

	/** Invoice numbers of this flow: the prefix plus the order id. */
	public const INVOICE_PREFIX = 'INV-WC-';

	/** Largest difference per position between the order total and the document that is carried as BT-114. */
	private const ROUNDING_PER_POSITION = 0.01;

	/** Option that turns a fatal EN 16931 finding into a refusal to dispatch. Off by default. */
	public const SCHEMATRON_GATE_OPTION = 'wm_peppol_schematron_gate';

	public function __construct(
		private readonly UblInvoiceGenerator $generator,
		private readonly ?\WM_Peppol_Phase4_Client $client = null,
		private readonly ?SchematronValidator $schematron = null
	) {}

	/** Whether a fatal rule finding stops a dispatch on this site. */
	public static function schematron_gates(): bool {
		return '1' === (string) get_option( self::SCHEMATRON_GATE_OPTION, '0' );
	}

	public function init(): void {
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_order_completed' ], 10, 1 );
		add_action( self::DISPATCH_HOOK, [ $this, 'dispatch' ], 10, 1 );
	}

	/**
	 * Build and store the invoice of a completed order, and schedule sending when configured.
	 *
	 * @param int|string $order_id Order id as WooCommerce passes it.
	 */
	public function on_order_completed( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// _peppol_endpoint_id is written by the order-edit fields; _peppol_buyer_endpoint is the older key.
		$receiver = trim( (string) $order->get_meta( '_peppol_endpoint_id' ) );
		if ( '' === $receiver ) {
			$receiver = trim( (string) $order->get_meta( '_peppol_buyer_endpoint' ) );
		}
		if ( '' === $receiver ) {
			if ( '' !== trim( (string) $order->get_meta( '_peppol_leitweg_id' ) ) ) {
				$order->add_order_note( __( 'Peppol: Die Bestellung hat eine Leitweg-ID, aber keine Peppol-Empfänger-ID; es wurde keine E-Rechnung erzeugt.', 'wm-peppol-as4-gateway' ) );
			}
			return;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'wm_peppol_invoices';
		$number = self::INVOICE_PREFIX . $order->get_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE invoice_number = %s', $table, $number ) ) ) {
			return;
		}

		$data = $this->invoice_data( $order, $number, $receiver );
		if ( is_wp_error( $data ) ) {
			$order->add_order_note( $data->get_error_message() );
			return;
		}

		try {
			$document = $this->generator->build( $data );

			// WooCommerce rounds tax per line or per subtotal, the document per rate; a cent per position is carried.
			$difference = round( (float) $order->get_total() - (float) $document['totals']['gross'], 2 );
			if ( abs( $difference ) >= 0.005 ) {
				$positions = count( $data['lines'] ) + count( $data['adjustments'] );
				if ( abs( $difference ) > self::ROUNDING_PER_POSITION * $positions + 0.0001 ) {
					/* translators: 1: order total, 2: total of the document */
					$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung nicht erzeugt. Bestellsumme %1$s und Rechnungssumme %2$s weichen um mehr als die Rundung ab.', 'wm-peppol-as4-gateway' ), number_format( (float) $order->get_total(), 2, ',', '.' ), number_format( (float) $document['totals']['gross'], 2, ',', '.' ) ) );
					return;
				}
				$data['rounding_amount'] = $difference;
				$document                = $this->generator->build( $data );
			}
		} catch ( InvoiceDataException $e ) {
			/* translators: %s: list of missing or invalid data */
			$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung nicht erzeugt. %s', 'wm-peppol-as4-gateway' ), $e->describe() ) );
			return;
		}

		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$table,
			[
				'invoice_number'        => $number,
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
		if ( false === $inserted ) {
			$order->add_order_note( __( 'Peppol: Die erzeugte E-Rechnung konnte nicht gespeichert werden.', 'wm-peppol-as4-gateway' ) );
			return;
		}

		$invoice_id = (int) $wpdb->insert_id;
		$order->update_meta_data( '_peppol_invoice_id', $invoice_id );
		$order->update_meta_data( '_peppol_status', 'generated' );
		$order->save();
		do_action( 'wm_peppol_invoice_recorded', $invoice_id, $number, 'woocommerce' );

		if ( ( $this->client ?? new \WM_Peppol_Phase4_Client() )->is_configured() ) {
			wp_schedule_single_event( time(), self::DISPATCH_HOOK, [ $invoice_id ] );
			/* translators: %s: invoice number */
			$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung %s erzeugt; der Versand ist eingeplant.', 'wm-peppol-as4-gateway' ), $number ) );
		} else {
			/* translators: %s: invoice number */
			$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung %s erzeugt und gespeichert, nicht versendet: KoSIT-Validator und Phase4-Dienst sind nicht konfiguriert.', 'wm-peppol-as4-gateway' ), $number ) );
		}
	}

	/**
	 * Send one stored invoice: internal check, KoSIT, Phase4, transmission row.
	 *
	 * @param int|string $invoice_id Invoice row id.
	 */
	public function dispatch( $invoice_id ): void {
		global $wpdb;
		$invoices = $wpdb->prefix . 'wm_peppol_invoices';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, invoice_number, buyer_participant_id, seller_participant_id, ubl_xml, status FROM %i WHERE id = %d', $invoices, (int) $invoice_id ), ARRAY_A );
		if ( ! is_array( $row ) || ! in_array( $row['status'], [ 'generated', 'dispatch_failed' ], true ) ) {
			return;
		}

		$order  = $this->order_of( (string) $row['invoice_number'] );
		$client = $this->client ?? new \WM_Peppol_Phase4_Client();

		// A document this plugin's own checks refuse is not handed on.
		$check = \WM_Peppol_Validator::validate( (string) $row['ubl_xml'] );
		if ( ! $check['valid'] ) {
			$this->dispatch_failed( $row, $order, implode( ' ', $check['errors'] ) );
			return;
		}

		// The EN 16931 rule set runs on every dispatch and its result goes on the order, so a site sees
		// what the rules say about its own invoices. It only STOPS the dispatch when the site asked for
		// that: an existing site that dispatches today must not start failing because a rule the KoSIT
		// validator tolerates fired here. Off by default, option wm_peppol_schematron_gate.
		$rules = ( $this->schematron ?? new SchematronValidator() )->validate_xml( (string) $row['ubl_xml'] );
		if ( ! is_wp_error( $rules ) && $rules['fatal'] > 0 ) {
			$summary = implode( ', ', array_slice( array_unique( array_column( $rules['findings'], 'id' ) ), 0, 8 ) );

			if ( self::schematron_gates() ) {
				$this->dispatch_failed(
					$row,
					$order,
					sprintf(
						/* translators: 1: number of fatal findings, 2: rule identifiers */
						__( 'EN 16931: %1$d fatale Regelbefunde (%2$s).', 'wm-peppol-as4-gateway' ),
						$rules['fatal'],
						$summary
					)
				);
				return;
			}

			if ( $order ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: number of fatal findings, 2: rule identifiers */
						__( 'Peppol: Die EN-16931-Regelprüfung meldet %1$d fatale Befunde (%2$s). Versendet wird trotzdem; zum Blockieren "wm_peppol_schematron_gate" auf 1 setzen.', 'wm-peppol-as4-gateway' ),
						$rules['fatal'],
						$summary
					)
				);
			}
		}

		$accepted = $client->validate_kosit( (string) $row['ubl_xml'] );
		if ( is_wp_error( $accepted ) ) {
			$this->dispatch_failed( $row, $order, $accepted->get_error_message() );
			return;
		}

		$message_id = $client->send_as4( (string) $row['ubl_xml'], (string) $row['seller_participant_id'], (string) $row['buyer_participant_id'] );
		if ( is_wp_error( $message_id ) ) {
			$this->dispatch_failed( $row, $order, $message_id->get_error_message() );
			return;
		}

		$now = current_time( 'mysql', true );
		// c2_sender_ap / c3_receiver_ap hold the sender and receiver participant ids; the Phase4 service picks the access points.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'wm_peppol_transmissions',
			[
				'invoice_id'       => (int) $row['id'],
				'message_id'       => $message_id,
				'c2_sender_ap'     => (string) $row['seller_participant_id'],
				'c3_receiver_ap'   => (string) $row['buyer_participant_id'],
				'transport_status' => 'sent',
				'created_at'       => $now,
			],
			[ '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $invoices, [ 'status' => 'sent', 'updated_at' => $now ], [ 'id' => (int) $row['id'] ], [ '%s', '%s' ], [ '%d' ] );

		if ( $order ) {
			$order->update_meta_data( '_peppol_message_id', $message_id );
			$order->update_meta_data( '_peppol_status', 'sent' );
			$order->save();
			/* translators: 1: invoice number, 2: message id */
			$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung %1$s an den Phase4-Dienst übergeben (messageId %2$s). Zugestellt ist sie erst mit der Empfangsquittung.', 'wm-peppol-as4-gateway' ), $row['invoice_number'], $message_id ) );
		}
	}

	/**
	 * Generator input for an order, or the reason it cannot be described.
	 *
	 * @param \WC_Order $order    Order.
	 * @param string    $number   Invoice number.
	 * @param string    $receiver Buyer participant id.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function invoice_data( $order, string $number, string $receiver ) {
		$lines       = [];
		$adjustments = [];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$quantity = (float) $item->get_quantity();
			// A line refunded to zero, or an empty one, has nothing to invoice and cannot give a unit price.
			if ( $quantity <= 0 ) {
				continue;
			}
			$rate = $this->single_rate( $item->get_taxes(), (string) $item->get_name() );
			if ( is_wp_error( $rate ) ) {
				return $rate;
			}
			$lines[] = [
				'name'     => (string) $item->get_name(),
				'quantity' => $quantity,
				'net'      => (float) $item->get_total(),
				'vat_rate' => $rate,
			];
		}

		foreach ( [ 'shipping', 'fee' ] as $type ) {
			foreach ( $order->get_items( $type ) as $item ) {
				$net = round( (float) $item->get_total(), 2 );
				if ( 0.0 === $net ) {
					continue;
				}
				$rate = $this->single_rate( $item->get_taxes(), (string) $item->get_name() );
				if ( is_wp_error( $rate ) ) {
					return $rate;
				}
				$adjustments[] = [
					'is_charge' => $net > 0,
					'amount'    => abs( $net ),
					'reason'    => '' !== (string) $item->get_name() ? (string) $item->get_name() : ( 'shipping' === $type ? __( 'Versand', 'wm-peppol-as4-gateway' ) : __( 'Gebühr', 'wm-peppol-as4-gateway' ) ),
					'vat_rate'  => $rate,
				];
			}
		}

		$company = trim( (string) $order->get_billing_company() );
		$person  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );

		// BT-10: the Leitweg-ID for a public buyer, otherwise the buyer reference entered on the order.
		$reference = trim( (string) $order->get_meta( '_peppol_leitweg_id' ) );
		if ( '' === $reference ) {
			$reference = trim( (string) $order->get_meta( '_peppol_buyer_reference' ) );
		}

		return [
			'invoice_number'  => $number,
			'issue_date'      => gmdate( 'Y-m-d' ),
			'currency'        => (string) $order->get_currency(),
			'buyer_reference' => $reference,
			'buyer'           => [
				'endpoint'    => $receiver,
				'name'        => '' !== $company ? $company : $person,
				'street'      => (string) $order->get_billing_address_1(),
				'city'        => (string) $order->get_billing_city(),
				'postal_code' => (string) $order->get_billing_postcode(),
				'country'     => (string) $order->get_billing_country(),
			],
			'lines'           => $lines,
			'adjustments'     => $adjustments,
		];
	}

	/**
	 * The one tax rate of an order item.
	 *
	 * An item without tax needs an exemption category and reason the plugin does not represent; an item with several
	 * rates cannot be one invoice line. Both are refused rather than guessed.
	 *
	 * @param mixed  $taxes WC_Order_Item::get_taxes(): ['total' => [rate id => amount], ...].
	 * @param string $name  Item name for the message.
	 * @return float|\WP_Error
	 */
	private function single_rate( $taxes, string $name ) {
		$totals = is_array( $taxes ) && isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : [];
		$totals = array_filter( $totals, static fn( $amount ): bool => is_numeric( $amount ) && 0.0 !== (float) $amount );

		if ( 1 !== count( $totals ) ) {
			return new \WP_Error(
				'wm_peppol_tax_rate',
				0 === count( $totals )
					/* translators: %s: order item name */
					? sprintf( __( 'Peppol: E-Rechnung nicht erzeugt. „%s“ ist ohne Umsatzsteuer berechnet; Steuerbefreiungen bildet das Plugin nicht ab.', 'wm-peppol-as4-gateway' ), $name )
					/* translators: %s: order item name */
					: sprintf( __( 'Peppol: E-Rechnung nicht erzeugt. „%s“ trägt mehrere Steuersätze.', 'wm-peppol-as4-gateway' ), $name )
			);
		}

		return (float) \WC_Tax::get_rate_percent_value( (string) array_key_first( $totals ) );
	}

	/**
	 * The order an invoice of this flow belongs to.
	 *
	 * @param string $number Invoice number.
	 * @return \WC_Order|null
	 */
	private function order_of( string $number ) {
		if ( ! function_exists( 'wc_get_order' ) || ! preg_match( '/^' . preg_quote( self::INVOICE_PREFIX, '/' ) . '(\d+)$/', $number, $m ) ) {
			return null;
		}
		$order = wc_get_order( (int) $m[1] );
		return $order ? $order : null;
	}

	/**
	 * Record a failed dispatch on the invoice and the order.
	 *
	 * @param array<string, mixed> $row    Invoice row.
	 * @param \WC_Order|null       $order  Order.
	 * @param string               $reason Why.
	 */
	private function dispatch_failed( array $row, $order, string $reason ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $wpdb->prefix . 'wm_peppol_invoices', [ 'status' => 'dispatch_failed', 'updated_at' => current_time( 'mysql', true ) ], [ 'id' => (int) $row['id'] ], [ '%s', '%s' ], [ '%d' ] );
		if ( $order ) {
			$order->update_meta_data( '_peppol_status', 'dispatch_failed' );
			$order->save();
			/* translators: 1: invoice number, 2: reason */
			$order->add_order_note( sprintf( __( 'Peppol: E-Rechnung %1$s nicht versendet. %2$s', 'wm-peppol-as4-gateway' ), $row['invoice_number'], $reason ) );
		}
	}
}
