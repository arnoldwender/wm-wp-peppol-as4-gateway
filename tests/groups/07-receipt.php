<?php
/**
 * Receipt webhook: loads without WP_REST_Controller, fails closed, declares its arguments, and maps SUCCESS and
 * FAILURE onto transmission, invoice and order (D21).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

/**
 * The signed AS4 receipts of tests/fixtures/as4 and the document their digests are of.
 *
 * Defined here because this group is the first to use them; tests/groups/13-receipt-evidence.php
 * exercises the verifier itself against the same three files.
 *
 * @return array{receipt:string, other:string, enveloped:string, payload:string}
 */
function pp_receipt_fixtures(): array {
	$root = dirname( __DIR__ ) . '/fixtures/as4';

	return [
		'receipt'   => (string) file_get_contents( $root . '/receipt-signed.xml' ),
		'other'     => (string) file_get_contents( $root . '/receipt-other-document.xml' ),
		'enveloped' => (string) file_get_contents( $root . '/receipt-enveloped.xml' ),
		'payload'   => (string) file_get_contents( $root . '/payload.xml' ),
	];
}

pp_group( 'RECEIPT 1 · loads without WP_REST_Controller, fails closed, declares its arguments', static function (): void {
	pp_reset();
	pp_assert( ( new ReflectionMethod( 'WM_Peppol_Webhook_Receiver', 'register_routes' ) )->isStatic() && false === get_parent_class( 'WM_Peppol_Webhook_Receiver' ), 'register_routes() is static and the class extends nothing (was: fatal at activation)' );

	$body = '{"messageId":"m1","status":"SUCCESS"}';
	$none = WM_Peppol_Webhook_Receiver::verify_receipt_signature( $body, 'anything' );
	pp_assert( $none instanceof WP_Error && 'wm_peppol_receipt_unconfigured' === $none->get_error_code(), 'without a secret every receipt is refused' );

	update_option( 'wm_peppol_receipt_secret', 'test-receipt-secret' );
	pp_assert( true === WM_Peppol_Webhook_Receiver::verify_receipt_signature( $body, base64_encode( hash_hmac( 'sha256', $body, 'test-receipt-secret', true ) ) ), 'a receipt signed with the secret passes' );
	$other = WM_Peppol_Webhook_Receiver::verify_receipt_signature( $body, base64_encode( hash_hmac( 'sha256', $body, 'other', true ) ) );
	pp_assert( $other instanceof WP_Error && 'wm_peppol_receipt_bad_signature' === $other->get_error_code(), 'a receipt signed with another secret is refused' );
	$unsigned = WM_Peppol_Webhook_Receiver::verify_receipt_signature( $body, '' );
	pp_assert( $unsigned instanceof WP_Error && 'wm_peppol_receipt_unsigned' === $unsigned->get_error_code(), 'a receipt without signature is refused' );

	WM_Peppol_Webhook_Receiver::register_routes();
	$args = $GLOBALS['wp_test']['routes']['/peppol/v1/receipt']['args'] ?? [];
	pp_assert( 'string' === ( $args['messageId']['type'] ?? '' ) && true === ( $args['messageId']['required'] ?? false ) && [ 'SUCCESS', 'FAILURE' ] === ( $args['status']['enum'] ?? null ), 'messageId is a required string and status is SUCCESS or FAILURE (was: no arguments; "PENDING" failed the order)' );
} );

pp_group( 'RECEIPT 2 · a receipt updates transmission, invoice and order', static function (): void {
	pp_reset();
	global $wpdb;
	$now = '2026-09-15 10:00:00';
	foreach ( [ 1 => 'OK-1', 2 => 'BAD-1' ] as $n => $number ) {
		$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => $number, 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-15', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => '<Invoice/>', 'xml_hash_sha256' => hash( 'sha256', '<Invoice/>' ), 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now ] );
		$wpdb->insert( 'wp_wm_peppol_transmissions', [ 'invoice_id' => $n, 'message_id' => "msg-{$n}", 'c2_sender_ap' => '9930:DE111222333', 'c3_receiver_ap' => '0088:4000000000001', 'transport_status' => 'sent', 'created_at' => $now ] );
		pp_wc_order( 80 + $n )->meta['_peppol_message_id'] = "msg-{$n}";
	}

	$receiver = new WM_Peppol_Webhook_Receiver();
	$receipt  = static function ( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) json_encode( $payload ) );
		return $request;
	};

	$ok          = $receiver->handle_receipt( $receipt( [ 'messageId' => 'msg-1', 'status' => 'SUCCESS' ] ) );
	$transmission = $wpdb->get_row( "SELECT * FROM wp_wm_peppol_transmissions WHERE message_id = 'msg-1'", 'ARRAY_A' ) ?? [];
	pp_assert( $ok instanceof WP_REST_Response && 'delivered' === ( $transmission['transport_status'] ?? '' ) && str_contains( (string) ( $transmission['non_repudiation_receipt'] ?? '' ), 'msg-1' ), 'SUCCESS marks the transmission delivered and keeps the receipt (was: nothing wrote the table)' );
	pp_assert( 'delivered' === $wpdb->get_var( 'SELECT status FROM wp_wm_peppol_invoices WHERE id = 1' ) && [ 'completed' ] === $GLOBALS['wp_test']['orders'][81]->statuses && str_contains( implode( ' ', $GLOBALS['wp_test']['orders'][81]->notes ), 'Empfangsquittung' ), 'invoice delivered, order completed, German note (was: Spanish, "Registrado en Sede Electrónica")' );

	$receiver->handle_receipt( $receipt( [ 'messageId' => 'msg-2', 'status' => 'FAILURE', 'errorCode' => 'EBMS:0004', 'errorMessage' => 'rejected' ] ) );
	pp_assert( 'failed' === $wpdb->get_var( "SELECT transport_status FROM wp_wm_peppol_transmissions WHERE message_id = 'msg-2'" ) && 'EBMS:0004' === $wpdb->get_var( "SELECT as4_error_code FROM wp_wm_peppol_transmissions WHERE message_id = 'msg-2'" ) && 'failed' === $wpdb->get_var( 'SELECT status FROM wp_wm_peppol_invoices WHERE id = 2' ) && [ 'failed' ] === $GLOBALS['wp_test']['orders'][82]->statuses, 'FAILURE records the error code and fails transmission, invoice and order' );

	$unknown = $receiver->handle_receipt( $receipt( [ 'messageId' => 'msg-404', 'status' => 'SUCCESS' ] ) );
	pp_assert( $unknown instanceof WP_Error && 404 === ( $unknown->get_error_data()['status'] ?? null ), 'an unknown messageId answers 404' );
	pp_assert( ! preg_match( '/Sede|enviada|Fallo|desconocido/', pp_code( 'includes/class-peppol-webhook-receiver.php' ) ), 'no Spanish order notes are left' );
} );

pp_group( 'RECEIPT 3 · a signed AS4 receipt travels through the route into the register', static function (): void {
	pp_reset();
	global $wpdb;

	$fixtures = pp_receipt_fixtures();
	$now      = '2026-09-16 10:00:00';

	// The stored XML IS the document the receipt's digests are of, which is the whole point: the
	// route compares the receipt against what this site actually sent, not against what it was told.
	$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => 'INV-AS4-1', 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-16', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => $fixtures['payload'], 'xml_hash_sha256' => hash( 'sha256', $fixtures['payload'] ), 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now ] );
	$wpdb->insert( 'wp_wm_peppol_transmissions', [ 'invoice_id' => 1, 'message_id' => 'as4-1', 'c2_sender_ap' => '9930:DE111222333', 'c3_receiver_ap' => '0088:4000000000001', 'transport_status' => 'sent', 'created_at' => $now ] );

	$receiver = new WM_Peppol_Webhook_Receiver();
	$call     = static function ( array $payload ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) json_encode( $payload ) );
		return $request;
	};

	$response = $receiver->handle_receipt( $call( [ 'messageId' => 'as4-1', 'status' => 'SUCCESS', 'as4Receipt' => $fixtures['receipt'] ] ) );
	pp_assert( $response instanceof WP_REST_Response && 200 === $response->get_status(), 'a verified receipt is accepted' );

	$data = $response->get_data();
	pp_assert( true === ( $data['evidence']['signature_verified'] ?? null ) && 'yes' === ( $data['evidence']['payload_match'] ?? '' ), 'and the answer carries what was measured, not a verdict' );
	pp_assert( 'delivered' === $wpdb->get_var( "SELECT transport_status FROM wp_wm_peppol_transmissions WHERE message_id = 'as4-1'" ), 'the transmission is delivered' );

	$rows = WMCyber\Peppol\AS4\DeliveryRegister::recent( 5 );
	pp_assert( 1 === count( $rows ) && 'receipt' === $rows[0]['action'] && 'INV-AS4-1' === $rows[0]['invoice_number'] && 'yes' === ( $rows[0]['detail']['payload_match'] ?? '' ), 'one chained entry is written, naming the invoice and what was measured (the table existed since the first version and nothing ever wrote to it)' );

	// A receipt signed by the same access point, for a different document. The transmission must NOT
	// become delivered on it.
	$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => 'INV-AS4-2', 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-16', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => $fixtures['payload'], 'xml_hash_sha256' => hash( 'sha256', $fixtures['payload'] ), 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now ] );
	$wpdb->insert( 'wp_wm_peppol_transmissions', [ 'invoice_id' => 2, 'message_id' => 'as4-2', 'c2_sender_ap' => '9930:DE111222333', 'c3_receiver_ap' => '0088:4000000000001', 'transport_status' => 'sent', 'created_at' => $now ] );

	$refused = $receiver->handle_receipt( $call( [ 'messageId' => 'as4-2', 'status' => 'SUCCESS', 'as4Receipt' => $fixtures['other'] ] ) );
	pp_assert( $refused instanceof WP_Error && 'wm_peppol_receipt_other_document' === $refused->get_error_code(), 'a receipt for another document is refused with its own code' );
	pp_assert( 'sent' === $wpdb->get_var( "SELECT transport_status FROM wp_wm_peppol_transmissions WHERE message_id = 'as4-2'" ), 'and the transmission is NOT marked delivered on it' );

	$rows = WMCyber\Peppol\AS4\DeliveryRegister::recent( 5 );
	pp_assert( 2 === count( $rows ) && 'receipt_rejected' === $rows[0]['action'], 'the refusal is recorded too: a rejected receipt is evidence of what arrived, not something to forget' );
	pp_assert( true === WMCyber\Peppol\AS4\DeliveryRegister::verify_chain()['valid'], 'and the chain still verifies over both' );

	// Without an as4Receipt the route behaves exactly as it did: a site on the older Phase4 contract
	// keeps working and simply gets no evidence row.
	$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => 'INV-AS4-3', 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-16', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => '<Invoice/>', 'xml_hash_sha256' => hash( 'sha256', '<Invoice/>' ), 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now ] );
	$wpdb->insert( 'wp_wm_peppol_transmissions', [ 'invoice_id' => 3, 'message_id' => 'as4-3', 'c2_sender_ap' => '9930:DE111222333', 'c3_receiver_ap' => '0088:4000000000001', 'transport_status' => 'sent', 'created_at' => $now ] );
	$plain = $receiver->handle_receipt( $call( [ 'messageId' => 'as4-3', 'status' => 'SUCCESS' ] ) );
	pp_assert( $plain instanceof WP_REST_Response && ! isset( $plain->get_data()['evidence'] ) && 'delivered' === $wpdb->get_var( "SELECT transport_status FROM wp_wm_peppol_transmissions WHERE message_id = 'as4-3'" ), 'a call without an AS4 receipt works as before and writes no evidence row' );
	pp_assert( 2 === count( WMCyber\Peppol\AS4\DeliveryRegister::recent( 5 ) ), 'the register stays at two entries' );

	// When a signer SHA-256 pin is configured, an unpinned self-signed receipt MUST be refused with 422
	// wm_peppol_receipt_untrusted_signer and logged as rejected.
	$wpdb->insert( 'wp_wm_peppol_invoices', [ 'invoice_number' => 'INV-AS4-4', 'buyer_participant_id' => '0088:4000000000001', 'seller_participant_id' => '9930:DE111222333', 'issue_date' => '2026-09-16', 'currency_code' => 'EUR', 'total_payable_amount' => '1.00', 'ubl_xml' => $fixtures['payload'], 'xml_hash_sha256' => hash( 'sha256', $fixtures['payload'] ), 'status' => 'sent', 'created_at' => $now, 'updated_at' => $now ] );
	$wpdb->insert( 'wp_wm_peppol_transmissions', [ 'invoice_id' => 4, 'message_id' => 'as4-4', 'c2_sender_ap' => '9930:DE111222333', 'c3_receiver_ap' => '0088:4000000000001', 'transport_status' => 'sent', 'created_at' => $now ] );
	update_option( \WMCyber\Peppol\Core\Settings::TRUSTED_SIGNER_SHA256_OPTION, str_repeat( 'b', 64 ) );
	$untrusted = $receiver->handle_receipt( $call( [ 'messageId' => 'as4-4', 'status' => 'SUCCESS', 'as4Receipt' => $fixtures['receipt'] ] ) );
	pp_assert( $untrusted instanceof WP_Error && 'wm_peppol_receipt_untrusted_signer' === $untrusted->get_error_code() && 422 === ( $untrusted->get_error_data()['status'] ?? 0 ), 'an unpinned AS4 receipt is refused with 422 wm_peppol_receipt_untrusted_signer' );
	pp_assert( 'sent' === $wpdb->get_var( "SELECT transport_status FROM wp_wm_peppol_transmissions WHERE message_id = 'as4-4'" ), 'transmission stays sent when signer is untrusted' );
	$rows = WMCyber\Peppol\AS4\DeliveryRegister::recent( 5 );
	pp_assert( 3 === count( $rows ) && 'receipt_rejected' === $rows[0]['action'] && 'untrusted' === ( $rows[0]['detail']['trust_status'] ?? '' ), 'the untrusted-signer rejection is recorded in the chained register' );
	delete_option( \WMCyber\Peppol\Core\Settings::TRUSTED_SIGNER_SHA256_OPTION );
} );
