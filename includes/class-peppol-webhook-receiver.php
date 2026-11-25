<?php
/**
 * REST receiver for the receipts the Phase4 service posts back after a transmission.
 *
 * Until 2026-09-14 this class extended WP_REST_Controller and declared register_routes() static,
 * a fatal error at load time ("Cannot make non static method ... static"): the plugin could not be
 * activated at all. The route was also open to anyone; it now fails closed: without a shared secret
 * no receipt is accepted, and every receipt must carry an HMAC-SHA256 of its body.
 *
 * Secret: the constant WM_PEPPOL_RECEIPT_SECRET (wp-config.php) or the option
 * wm_peppol_receipt_secret. Signature header: X-WM-Peppol-Signature = base64( HMAC-SHA256( raw body ) ).
 * The Peppol access point's own signature scheme is still to be decided; this is the fail-closed
 * baseline until it is.
 *
 * Payload (this plugin's contract with the Phase4 service): messageId, status SUCCESS or FAILURE, optional
 * errorMessage and errorCode. Until 2026-09-15 every status other than SUCCESS, "PENDING" included, marked the
 * order failed, and a messageId sent as an array was sanitised to '' and still answered 200. The route now
 * declares its arguments, updates the transmission row the dispatch wrote, and leaves anything else untouched.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WM_Peppol_Webhook_Receiver {

    public const SIGNATURE_HEADER = 'x-wm-peppol-signature';

    public static function register_routes(): void {
        $controller = new self();
        $string     = static fn( int $max, bool $required ): array => [
            'type'              => 'string',
            'required'          => $required,
            'maxLength'         => $max,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'rest_sanitize_request_arg',
        ];
        register_rest_route( 'peppol/v1', '/receipt', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $controller, 'handle_receipt' ],
            'permission_callback' => [ __CLASS__, 'permission' ],
            'args'                => [
                'messageId'    => [ 'minLength' => 1 ] + $string( 128, true ),
                'status'       => [ 'enum' => [ 'SUCCESS', 'FAILURE' ] ] + $string( 16, true ),
                'errorMessage' => $string( 1000, false ),
                'errorCode'    => $string( 64, false ),
                // The signed AS4 receipt itself, when the sender forwards it. Optional: the Phase4
                // contract of earlier versions carries only messageId and status, and a site running
                // that must keep working.
                'as4Receipt'   => $string( \WMCyber\Peppol\AS4\ReceiptEvidence::MAX_BYTES, false ),
            ],
        ] );
    }

    /**
     * The shared secret: constant first, option second, nothing otherwise.
     */
    public static function secret(): string {
        if ( defined( 'WM_PEPPOL_RECEIPT_SECRET' ) && '' !== (string) WM_PEPPOL_RECEIPT_SECRET ) {
            return (string) WM_PEPPOL_RECEIPT_SECRET;
        }
        return (string) get_option( 'wm_peppol_receipt_secret', '' );
    }

    /**
     * Verify the receipt signature over the raw body.
     *
     * @return true|WP_Error
     */
    public static function verify_receipt_signature( string $body, string $signature ) {
        $secret = self::secret();
        if ( '' === $secret ) {
            return new WP_Error(
                'wm_peppol_receipt_unconfigured',
                'Receipt webhook secret not configured: receipts are refused until WM_PEPPOL_RECEIPT_SECRET or the wm_peppol_receipt_secret option is set.',
                [ 'status' => 401 ]
            );
        }
        if ( '' === $signature ) {
            return new WP_Error( 'wm_peppol_receipt_unsigned', 'Missing ' . self::SIGNATURE_HEADER . ' header.', [ 'status' => 401 ] );
        }
        $expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'wm_peppol_receipt_bad_signature', 'Receipt signature does not match.', [ 'status' => 403 ] );
        }
        return true;
    }

    /**
     * @return true|WP_Error
     */
    public static function permission( WP_REST_Request $request ) {
        return self::verify_receipt_signature( (string) $request->get_body(), (string) $request->get_header( self::SIGNATURE_HEADER ) );
    }

    /**
     * Apply a verified receipt to the transmission, its invoice and the order.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_receipt( WP_REST_Request $request ) {
        global $wpdb;

        $message_id = (string) $request->get_param( 'messageId' );
        $delivered  = 'SUCCESS' === (string) $request->get_param( 'status' );
        $error_text = sanitize_text_field( (string) ( $request->get_param( 'errorMessage' ) ?? '' ) );
        $error_code = sanitize_text_field( (string) ( $request->get_param( 'errorCode' ) ?? '' ) );

        $transmissions = $wpdb->prefix . 'wm_peppol_transmissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $transmission = $wpdb->get_row( $wpdb->prepare( 'SELECT id, invoice_id FROM %i WHERE message_id = %s', $transmissions, $message_id ), ARRAY_A );

        $orders = function_exists( 'wc_get_orders' )
            ? wc_get_orders( [
                'meta_key'   => '_peppol_message_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $message_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'limit'      => 1,
            ] )
            : [];

        if ( ! is_array( $transmission ) && empty( $orders ) ) {
            return new WP_Error( 'wm_peppol_receipt_unknown', 'No transmission or order found for this messageId.', [ 'status' => 404 ] );
        }

        // The AS4 receipt, when one came with the call. The HMAC over the request body says the call
        // came from the configured sender; only the receipt's own signature says the ACCESS POINT
        // acknowledged the document, and only its NRR digests say which document.
        $evidence = null;
        $as4      = (string) ( $request->get_param( 'as4Receipt' ) ?? '' );
        if ( '' !== $as4 ) {
            $sent      = is_array( $transmission ) ? self::sent_document( (int) $transmission['invoice_id'] ) : null;
            $evidence  = \WMCyber\Peppol\AS4\ReceiptEvidence::verify( $as4, $sent );

            if ( is_wp_error( $evidence ) ) {
                \WMCyber\Peppol\AS4\DeliveryRegister::record(
                    is_array( $transmission ) ? self::invoice_number( (int) $transmission['invoice_id'] ) : '',
                    \WMCyber\Peppol\AS4\DeliveryRegister::ACTION_REJECTED,
                    $message_id,
                    [ 'error' => $evidence->get_error_code(), 'message' => $evidence->get_error_message() ]
                );

                return $evidence;
            }

            // A receipt that does not verify is not delivery evidence. It is recorded as rejected and
            // refused, so a forged, untrusted or truncated receipt cannot mark an invoice delivered.
            if ( ! $evidence['signature_verified'] || ! $evidence['trust_verified'] || 'no' === $evidence['payload_match'] ) {
                \WMCyber\Peppol\AS4\DeliveryRegister::record(
                    is_array( $transmission ) ? self::invoice_number( (int) $transmission['invoice_id'] ) : '',
                    \WMCyber\Peppol\AS4\DeliveryRegister::ACTION_REJECTED,
                    $message_id,
                    self::evidence_detail( $evidence )
                );

                return new WP_Error(
                    ! $evidence['signature_verified']
                        ? 'wm_peppol_receipt_signature_invalid'
                        : ( ! $evidence['trust_verified'] ? 'wm_peppol_receipt_untrusted_signer' : 'wm_peppol_receipt_other_document' ),
                    ! $evidence['signature_verified']
                        ? $evidence['signature_error']
                        : ( ! $evidence['trust_verified']
                            ? $evidence['trust_error']
                            : __( 'Die Quittung ist gültig signiert, bezieht sich aber auf ein anderes Dokument als das versendete.', 'wm-peppol-as4-gateway' ) ),
                    [ 'status' => 422 ]
                );
            }
        }

        $now = current_time( 'mysql', true );
        if ( is_array( $transmission ) ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $transmissions,
                [
                    'transport_status'        => $delivered ? 'delivered' : 'failed',
                    'non_repudiation_receipt' => (string) $request->get_body(),
                    'as4_error_code'          => '' !== $error_code ? $error_code : null,
                ],
                [ 'id' => (int) $transmission['id'] ]
            );
            $wpdb->update(
                $wpdb->prefix . 'wm_peppol_invoices',
                [ 'status' => $delivered ? 'delivered' : 'failed', 'updated_at' => $now ],
                [ 'id' => (int) $transmission['invoice_id'] ]
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        }

        if ( ! empty( $orders ) ) {
            $order = $orders[0];
            $order->update_meta_data( '_peppol_status', $delivered ? 'delivered' : 'failed' );
            $order->save();
            if ( $delivered ) {
                /* translators: %s: message id */
                $order->add_order_note( sprintf( __( 'Peppol: Positive Empfangsquittung für messageId %s erhalten.', 'wm-peppol-as4-gateway' ), $message_id ) );
                $order->update_status( 'completed', __( 'Peppol-Empfangsquittung erhalten.', 'wm-peppol-as4-gateway' ) );
            } else {
                /* translators: 1: message id, 2: error text of the receipt */
                $order->add_order_note( sprintf( __( 'Peppol: Versand fehlgeschlagen (messageId %1$s): %2$s', 'wm-peppol-as4-gateway' ), $message_id, '' !== $error_text ? $error_text : __( 'ohne Fehlerbeschreibung', 'wm-peppol-as4-gateway' ) ) );
                $order->update_status( 'failed', __( 'Peppol-Versand fehlgeschlagen.', 'wm-peppol-as4-gateway' ) );
            }
        }

        if ( null !== $evidence ) {
            \WMCyber\Peppol\AS4\DeliveryRegister::record(
                is_array( $transmission ) ? self::invoice_number( (int) $transmission['invoice_id'] ) : '',
                \WMCyber\Peppol\AS4\DeliveryRegister::ACTION_RECEIPT,
                $message_id,
                self::evidence_detail( $evidence ) + [ 'transport_status' => $delivered ? 'delivered' : 'failed' ]
            );
        }

        return new WP_REST_Response(
            [
                'success'          => true,
                'transport_status' => $delivered ? 'delivered' : 'failed',
            ] + ( null !== $evidence ? [ 'evidence' => self::evidence_detail( $evidence ) ] : [] ),
            200
        );
    }

    /**
     * What goes into the register: the measurements, never the receipt body or an address.
     *
     * @param array<string, mixed> $evidence
     * @return array<string, mixed>
     */
    private static function evidence_detail( array $evidence ): array {
        return [
            'receipt_sha256'      => $evidence['receipt_sha256'],
            'receipt_message_id'  => $evidence['message_id'],
            'ref_to_message_id'   => $evidence['ref_to_message_id'],
            'receipt_timestamp'   => $evidence['timestamp'],
            'signature_verified'  => $evidence['signature_verified'],
            'signature_error'     => $evidence['signature_error'],
            'signature_algorithm' => $evidence['signature_algorithm'],
            'references_verified' => $evidence['references_verified'],
            'references_total'    => $evidence['references_total'],
            'payload_match'       => $evidence['payload_match'],
            'trust_verified'      => $evidence['trust_verified'],
            'trust_status'        => $evidence['trust_status'],
            'trust_error'         => $evidence['trust_error'],
            'signer'              => $evidence['signer'],
        ];
    }

    /** The XML this site handed over for an invoice, so the receipt's digests can be checked against it. */
    private static function sent_document( int $invoice_id ): ?string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $xml = $wpdb->get_var( $wpdb->prepare( 'SELECT ubl_xml FROM %i WHERE id = %d', $wpdb->prefix . 'wm_peppol_invoices', $invoice_id ) );

        return is_string( $xml ) && '' !== $xml ? $xml : null;
    }

    /** The invoice number of a row, for the register. */
    private static function invoice_number( int $invoice_id ): string {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $number = $wpdb->get_var( $wpdb->prepare( 'SELECT invoice_number FROM %i WHERE id = %d', $wpdb->prefix . 'wm_peppol_invoices', $invoice_id ) );

        return is_string( $number ) ? $number : '';
    }
}
