<?php
/**
 * The WooCommerce order flow: one document per order (D6), built from the order (D15, D10, D11), receiver id
 * (D7), rounding (D12), idempotency (D36), dispatch only when configured and scheduled (D23), messageId required
 * (D8), transmission row, order meta API (D22) and the order-edit fields.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\UBL\UblInvoiceGenerator;
use WMCyber\Peppol\WooCommerce\WooCommerceInvoiceHook;

/**
 * An order of 227.20: 100.00 at 19 %, 90.00 at 7 %, a refunded zero-quantity line and 10.00 shipping at 19 %.
 */
function pp_wc_order( int $id, string $total = '227.20' ): Test_WC_Order {
	WC_Tax::$rates = [ '1' => '19.0000', '2' => '7.0000' ];
	$order         = new Test_WC_Order( $id, [ 'company' => 'Beispiel Käufer AG', 'address_1' => 'Platz 2', 'city' => 'Leipzig', 'postcode' => '04109', 'country' => 'DE' ], $total );
	$order->meta   = [ '_peppol_endpoint_id' => '0088:4000000000001', '_peppol_leitweg_id' => '04011000-1234512345-06' ];

	$order->items['line_item'] = [
		new Test_WC_Item( 'Beratung', '1', '100.00', [ 'total' => [ '1' => '19.00' ], 'subtotal' => [ '1' => '19.00' ] ] ),
		new Test_WC_Item( 'Buch', '1', '90.00', [ 'total' => [ '2' => '6.30' ], 'subtotal' => [ '2' => '6.30' ] ] ),
		new Test_WC_Item( 'Erstattet', '0', '0.00', [ 'total' => [], 'subtotal' => [] ] ),
	];
	$order->items['shipping']  = [ new Test_WC_Item( 'Versand DHL', '1', '10.00', [ 'total' => [ '1' => '1.90' ] ] ) ];

	$GLOBALS['wp_test']['orders'][ $id ] = $order;
	return $order;
}

pp_group( 'WOOCOMMERCE 1 · one flow, one document per order, built from the order', static function (): void {
	pp_reset();
	global $wpdb;

	$main = pp_code( 'wm-peppol-as4-gateway.php' );
	pp_assert( ! str_contains( $main, 'woocommerce_order_status_completed' ) && ! str_contains( $main, 'process_order_for_peppol' ) && ! file_exists( dirname( __DIR__, 2 ) . '/includes/class-peppol-ubl-builder.php' ), 'the entry file runs no second order flow with its own document (was: an XRechnung with placeholder IBAN next to the stored Peppol document)' );
	pp_assert( ! preg_match( '/\b(update|get|delete|add)_post_meta\s*\(/', $main ), 'order data goes through the order meta API (HPOS), not post meta' );

	$hook = new WooCommerceInvoiceHook( new UblInvoiceGenerator() );
	$hook->init();
	pp_assert( 1 === count( $GLOBALS['wp_test']['actions']['woocommerce_order_status_completed'] ?? [] ), 'one callback on woocommerce_order_status_completed' );

	$unconfigured = pp_wc_order( 41 );
	$hook->on_order_completed( 41 );
	pp_assert( [] === $wpdb->rows( 'wp_wm_peppol_invoices' ) && str_contains( implode( ' ', $unconfigured->notes ), 'Straße des Verkäufers' ), 'without seller data no invoice is stored and the note names what is missing (was: "Musterstrasse 1")' );

	pp_seller_options();
	$order = pp_wc_order( 42 );
	$hook->on_order_completed( 42 );
	$rows = $wpdb->rows( 'wp_wm_peppol_invoices' );
	pp_assert( 1 === count( $rows ) && 'INV-WC-42' === ( $rows[0]['invoice_number'] ?? '' ) && 'generated' === ( $rows[0]['status'] ?? '' ), 'a complete order stores one invoice INV-WC-42 with status generated' );

	$x = pp_xpath( (string) ( $rows[0]['ubl_xml'] ?? '<x/>' ) );
	pp_assert( 2 === pp_count( $x, '/ubl:Invoice/cac:InvoiceLine' ), 'two invoice lines: the zero-quantity item is left out (was: DivisionByZeroError inside the status change)' );
	pp_assert( 2 === pp_count( $x, '//cac:TaxTotal/cac:TaxSubtotal' ) && '27.20' === pp_text( $x, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' ), 'the order tax rates give two breakdowns and 27.20 tax (was: one line from the gross total at 19 %)' );
	pp_assert( '227.20' === pp_text( $x, '//cac:LegalMonetaryTotal/cbc:PayableAmount' ) && '227.20' === number_format( (float) ( $rows[0]['total_payable_amount'] ?? 0 ), 2, '.', '' ), 'payable amount of the document and of the row equal the order total' );
	pp_assert( 'Beispiel Käufer AG' === pp_text( $x, '//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName' ) && '04109' === pp_text( $x, '//cac:AccountingCustomerParty//cbc:PostalZone' ) && '04011000-1234512345-06' === pp_text( $x, '/ubl:Invoice/cbc:BuyerReference' ), 'buyer and buyer reference come from the order (was: "Kunde", "Unbekannt", the invoice number)' );
	pp_assert( 'Versand DHL' === pp_text( $x, '//cac:AllowanceCharge/cbc:AllowanceChargeReason' ), 'shipping is a charge named after the shipping line' );
	pp_assert( hash( 'sha256', (string) ( $rows[0]['ubl_xml'] ?? '' ) ) === ( $rows[0]['xml_hash_sha256'] ?? '' ), 'the stored hash is the hash of the stored XML' );
	pp_assert( WM_Peppol_Validator::validate( (string) ( $rows[0]['ubl_xml'] ?? '' ) )['valid'], 'the stored document passes the checks' );
	pp_assert( (int) ( $rows[0]['id'] ?? -1 ) === (int) ( $order->meta['_peppol_invoice_id'] ?? 0 ) && $order->saves > 0, 'the order records its invoice through update_meta_data() and save()' );
	pp_assert( [] === $GLOBALS['wp_test']['scheduled'] && str_contains( implode( ' ', $order->notes ), 'nicht versendet' ), 'without service URLs nothing is scheduled and the note says so' );

	$notes = count( $order->notes );
	$hook->on_order_completed( 42 );
	pp_assert( 1 === count( $wpdb->rows( 'wp_wm_peppol_invoices' ) ) && count( $order->notes ) === $notes, 'completing the order again stores nothing new and adds no note' );
} );

pp_group( 'WOOCOMMERCE 2 · what the order cannot say is refused, a cent of rounding is carried', static function (): void {
	pp_reset();
	pp_seller_options();
	global $wpdb;
	$hook = new WooCommerceInvoiceHook( new UblInvoiceGenerator() );

	$no_receiver = pp_wc_order( 50 );
	unset( $no_receiver->meta['_peppol_endpoint_id'] );
	$hook->on_order_completed( 50 );
	pp_assert( [] === $wpdb->rows( 'wp_wm_peppol_invoices' ) && str_contains( implode( ' ', $no_receiver->notes ), 'keine Peppol-Empfänger-ID' ), 'a Leitweg-ID without receiver id stores nothing and says why (was: sent to receiver "0204:")' );

	$untaxed                             = pp_wc_order( 51 );
	$untaxed->items['line_item'][0]      = new Test_WC_Item( 'Steuerfrei', '1', '100.00', [ 'total' => [], 'subtotal' => [] ] );
	$hook->on_order_completed( 51 );
	pp_assert( str_contains( implode( ' ', $untaxed->notes ), 'ohne Umsatzsteuer' ), 'an item without tax is refused, not invoiced at 19 %' );

	$two_rates                        = pp_wc_order( 52 );
	$two_rates->items['line_item'][0] = new Test_WC_Item( 'Doppelt', '1', '100.00', [ 'total' => [ '1' => '19.00', '2' => '7.00' ] ] );
	$hook->on_order_completed( 52 );
	pp_assert( str_contains( implode( ' ', $two_rates->notes ), 'mehrere Steuersätze' ), 'an item with two rates is refused' );

	pp_wc_order( 53, '227.21' );
	$hook->on_order_completed( 53 );
	$x = pp_xpath( (string) ( $wpdb->get_var( "SELECT ubl_xml FROM wp_wm_peppol_invoices WHERE invoice_number = 'INV-WC-53'" ) ?? '<x/>' ) );
	pp_assert( '0.01' === pp_text( $x, '//cbc:PayableRoundingAmount' ) && '227.21' === pp_text( $x, '//cbc:PayableAmount' ), 'a cent between order total and document is carried as BT-114; the payable amount is the order total' );

	$far = pp_wc_order( 54, '228.20' );
	$hook->on_order_completed( 54 );
	pp_assert( null === $wpdb->get_var( "SELECT id FROM wp_wm_peppol_invoices WHERE invoice_number = 'INV-WC-54'" ) && str_contains( implode( ' ', $far->notes ), 'mehr als die Rundung' ), 'a difference beyond rounding is refused' );
} );

pp_group( 'WOOCOMMERCE 3 · dispatch: only when configured, the stored XML is sent, a messageId is required', static function (): void {
	pp_reset();
	pp_seller_options();
	update_option( 'wm_peppol_kosit_url', 'https://kosit.example.test/validate' );
	update_option( 'wm_peppol_phase4_url', 'https://phase4.example.test/api/sendas4/' );
	global $wpdb;
	$hook = new WooCommerceInvoiceHook( new UblInvoiceGenerator() );

	$order = pp_wc_order( 60 );
	$hook->on_order_completed( 60 );
	$row = $wpdb->get_row( "SELECT * FROM wp_wm_peppol_invoices WHERE invoice_number = 'INV-WC-60'", 'ARRAY_A' ) ?? [ 'id' => 0, 'ubl_xml' => '' ];
	pp_assert( [ [ WooCommerceInvoiceHook::DISPATCH_HOOK, [ (int) $row['id'] ] ] ] === $GLOBALS['wp_test']['scheduled'] && [] === $GLOBALS['wp_test']['http_calls'], 'with both URLs the dispatch is scheduled for the stored invoice; no HTTP request inside the status change' );

	$GLOBALS['wp_test']['http_queue'] = [ wm_test_http( 200, 'ok' ), wm_test_http( 202, '{"messageId":"msg-60"}' ) ];
	$hook->dispatch( (int) $row['id'] );
	$calls = $GLOBALS['wp_test']['http_calls'];
	pp_assert( 2 === count( $calls ) && 'https://kosit.example.test/validate' === ( $calls[0]['url'] ?? '' ), 'KoSIT first, at the configured URL (was: http://localhost:8080)' );
	pp_assert( ( $calls[1]['args']['body'] ?? null ) === $row['ubl_xml'], 'the XML sent to Phase4 is the stored XML' );
	pp_assert( str_starts_with( (string) ( $calls[1]['url'] ?? '' ), 'https://phase4.example.test/api/sendas4/' . rawurlencode( '9930:DE111222333' ) . '/' . rawurlencode( '0088:4000000000001' ) . '/' ), 'sender and receiver are the stored participant ids, without an extra scheme (was: 9930:DE999999999 and "0204:" plus the id)' );

	$transmissions = $wpdb->rows( 'wp_wm_peppol_transmissions' );
	pp_assert( 1 === count( $transmissions ) && 'msg-60' === ( $transmissions[0]['message_id'] ?? '' ) && 'sent' === ( $transmissions[0]['transport_status'] ?? '' ) && (int) $row['id'] === (int) ( $transmissions[0]['invoice_id'] ?? 0 ), 'the transmission row carries the messageId (was: nothing wrote this table)' );
	pp_assert( 'sent' === $wpdb->get_var( 'SELECT status FROM wp_wm_peppol_invoices WHERE id = ' . (int) $row['id'] ) && 'msg-60' === ( $order->meta['_peppol_message_id'] ?? '' ), 'invoice status sent, messageId on the order' );

	$order61 = pp_wc_order( 61 );
	$hook->on_order_completed( 61 );
	$id61                             = (int) $wpdb->get_var( "SELECT id FROM wp_wm_peppol_invoices WHERE invoice_number = 'INV-WC-61'" );
	$GLOBALS['wp_test']['http_queue'] = [ wm_test_http( 200 ), wm_test_http( 200, '{}' ) ];
	$hook->dispatch( $id61 );
	pp_assert( 'dispatch_failed' === $wpdb->get_var( "SELECT status FROM wp_wm_peppol_invoices WHERE id = {$id61}" ) && 1 === count( $wpdb->rows( 'wp_wm_peppol_transmissions' ) ) && str_contains( implode( ' ', $order61->notes ), 'messageId' ), 'an answer without messageId is a failed dispatch (was: _peppol_status sent, no receipt could ever match)' );

	pp_wc_order( 62 );
	$hook->on_order_completed( 62 );
	$id62                             = (int) $wpdb->get_var( "SELECT id FROM wp_wm_peppol_invoices WHERE invoice_number = 'INV-WC-62'" );
	$before                           = count( $GLOBALS['wp_test']['http_calls'] );
	$GLOBALS['wp_test']['http_queue'] = [ wm_test_http( 406 ) ];
	$hook->dispatch( $id62 );
	pp_assert( 'dispatch_failed' === $wpdb->get_var( "SELECT status FROM wp_wm_peppol_invoices WHERE id = {$id62}" ) && count( $GLOBALS['wp_test']['http_calls'] ) === $before + 1, 'a KoSIT refusal stops the dispatch before Phase4' );
	$hook->dispatch( (int) $row['id'] );
	pp_assert( count( $GLOBALS['wp_test']['http_calls'] ) === $before + 1, 'an invoice already sent is not sent again' );

	$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => 'INV-WC-63', 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-15', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"/>', 'xml_hash_sha256' => str_repeat( 'b', 64 ), 'status' => 'generated', 'created_at' => '2026-09-15 10:00:00', 'updated_at' => '2026-09-15 10:00:00' ] );
	$id63  = (int) $wpdb->insert_id;
	$calls = count( $GLOBALS['wp_test']['http_calls'] );
	$hook->dispatch( $id63 );
	pp_assert( 'dispatch_failed' === $wpdb->get_var( "SELECT status FROM wp_wm_peppol_invoices WHERE id = {$id63}" ) && count( $GLOBALS['wp_test']['http_calls'] ) === $calls, 'a stored document the plugin\'s own checks refuse is not handed to KoSIT or Phase4' );

	$client = new WM_Peppol_Phase4_Client( '', '' );
	$sent   = $client->send_as4( '<x/>', '9930:DE1', '0088:1' );
	pp_assert( ! $client->is_configured() && $sent instanceof WP_Error && 'wm_peppol_dispatch_unconfigured' === $sent->get_error_code(), 'an unconfigured client sends nothing' );
	$source = pp_code( 'includes/class-peppol-phase4-client.php' );
	pp_assert( ! str_contains( $source, 'localhost' ) && ! str_contains( $source, 'DE999999999' ), 'no localhost URL and no placeholder sender in the client' );
} );

pp_group( 'WOOCOMMERCE 4 · order-edit fields check the Leitweg-ID and the receiver id', static function (): void {
	pp_reset();
	$gateway     = WM_Peppol_Gateway::get_instance();
	$order       = pp_wc_order( 70 );
	$order->meta = [];

	$_POST = [ '_peppol_leitweg_id' => '04011000-12345-67', '_peppol_endpoint_id' => 'DE111222333', '_peppol_buyer_reference' => 'PO 77' ];
	$gateway->save_admin_order_peppol_fields( 70 );
	pp_assert( ! isset( $order->meta['_peppol_leitweg_id'] ) && ! isset( $order->meta['_peppol_endpoint_id'] ) && 2 === count( WC_Admin_Meta_Boxes::$errors ), 'an invalid Leitweg-ID and a receiver id without scheme are refused with a message' );
	pp_assert( 'PO 77' === ( $order->meta['_peppol_buyer_reference'] ?? '' ) && $order->saves > 0, 'the buyer reference is saved through the order meta API' );

	$_POST = [ '_peppol_leitweg_id' => '04011000-1234512345-06', '_peppol_endpoint_id' => '0204:04011000-1234512345-06' ];
	$gateway->save_admin_order_peppol_fields( 70 );
	pp_assert( '04011000-1234512345-06' === ( $order->meta['_peppol_leitweg_id'] ?? '' ) && '0204:04011000-1234512345-06' === ( $order->meta['_peppol_endpoint_id'] ?? '' ), 'valid values are saved' );
	$_POST = [];
} );
