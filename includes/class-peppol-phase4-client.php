<?php
/**
 * Client of the two services that validate and send an invoice: a KoSIT validator and a Phase4 sender.
 *
 * Both are separate programs (see docker-compose.yml, which is not part of the plugin ZIP). The plugin does not
 * sign, encrypt or transmit AS4 messages itself; the Phase4 service does, with its own certificates.
 *
 * Until 2026-09-15 this client posted every completed WooCommerce order to http://localhost:8080 and :8081,
 * with the sender "9930:DE999999999" and a receiver built as "0204:" plus the endpoint field, so an order with
 * only a Leitweg-ID went to "0204:" and an endpoint "9930:DE111222333" to "0204:9930:DE111222333". A response
 * without messageId counted as sent, so no receipt could ever be matched. Now nothing is sent unless both URLs
 * are configured, the ids come from the stored invoice, and a missing messageId is a failed dispatch.
 *
 * Not verified: the request and response contract of the two services (KoSIT answers 200 for an accepted
 * document; Phase4 takes /{sender}/{receiver}/{document type}/{process} and answers JSON with messageId).
 * The validation report body is not evaluated.
 *
 * @package WM_Peppol_AS4_Gateway
 */

declare(strict_types=1);

use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WM_Peppol_Phase4_Client {

	/** Process identifier of Peppol BIS Billing 3.0 (the generator's ProfileID). */
	public const PROCESS_ID = UblInvoiceGenerator::PROFILE_ID;

	/** A Peppol participant id: four-digit EAS scheme, colon, identifier. */
	private const PARTICIPANT_ID = '/^\d{4}:\S+$/';

	private string $kosit_url;
	private string $phase4_url;

	/**
	 * @param string|null $kosit_url  KoSIT validator URL; the configured one when null.
	 * @param string|null $phase4_url Phase4 sender base URL; the configured one when null.
	 */
	public function __construct( ?string $kosit_url = null, ?string $phase4_url = null ) {
		$this->kosit_url  = rtrim( $kosit_url ?? Settings::kosit_url(), '/' );
		$this->phase4_url = rtrim( $phase4_url ?? Settings::phase4_url(), '/' );
	}

	/** True when both services are configured. */
	public function is_configured(): bool {
		return '' !== $this->kosit_url && '' !== $this->phase4_url;
	}

	/** Document type identifier of the documents UblInvoiceGenerator builds. */
	public function document_type_id(): string {
		return 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##' . UblInvoiceGenerator::CUSTOMIZATION_ID . '::2.1';
	}

	/**
	 * Ask the KoSIT validator whether it accepts the document.
	 *
	 * @param string $xml UBL document.
	 * @return true|WP_Error
	 */
	public function validate_kosit( string $xml ) {
		if ( ! $this->is_configured() ) {
			return self::unconfigured();
		}

		$response = wp_remote_post(
			$this->kosit_url,
			[
				'body'    => $xml,
				'headers' => [ 'Content-Type' => 'application/xml' ],
				'timeout' => 15,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'kosit_validation_failed', sprintf( __( 'Der KoSIT-Validator hat das Dokument nicht angenommen (HTTP %d).', 'wm-peppol-as4-gateway' ), $status ) );
		}

		return true;
	}

	/**
	 * Hand the document to the Phase4 sender.
	 *
	 * @param string $xml         UBL document.
	 * @param string $sender_id   Seller participant id.
	 * @param string $receiver_id Buyer participant id.
	 * @return string|WP_Error The messageId the service reported.
	 */
	public function send_as4( string $xml, string $sender_id, string $receiver_id ) {
		if ( ! $this->is_configured() ) {
			return self::unconfigured();
		}
		if ( ! preg_match( self::PARTICIPANT_ID, $sender_id ) || ! preg_match( self::PARTICIPANT_ID, $receiver_id ) ) {
			return new WP_Error( 'wm_peppol_participant_id', __( 'Absender- oder Empfänger-ID hat nicht die Form Schema:Kennung.', 'wm-peppol-as4-gateway' ) );
		}

		$url = sprintf(
			'%s/%s/%s/%s/%s',
			$this->phase4_url,
			rawurlencode( $sender_id ),
			rawurlencode( $receiver_id ),
			rawurlencode( $this->document_type_id() ),
			rawurlencode( self::PROCESS_ID )
		);

		$response = wp_remote_post(
			$url,
			[
				'body'    => $xml,
				'headers' => [ 'Content-Type' => 'application/xml' ],
				'timeout' => 30,
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status && 202 !== $status ) {
			/* translators: %d: HTTP status code */
			return new WP_Error( 'phase4_send_failed', sprintf( __( 'Der Phase4-Dienst hat den Versand abgelehnt (HTTP %d).', 'wm-peppol-as4-gateway' ), $status ) );
		}

		$json       = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$message_id = is_array( $json ) && isset( $json['messageId'] ) && is_scalar( $json['messageId'] ) ? sanitize_text_field( (string) $json['messageId'] ) : '';
		if ( '' === $message_id ) {
			return new WP_Error( 'phase4_no_message_id', __( 'Der Phase4-Dienst hat keine messageId zurückgegeben; ohne sie lässt sich keine Empfangsquittung zuordnen.', 'wm-peppol-as4-gateway' ) );
		}

		return $message_id;
	}

	/** The error for a client without both URLs. */
	private static function unconfigured(): WP_Error {
		return new WP_Error( 'wm_peppol_dispatch_unconfigured', __( 'KoSIT-Validator und Phase4-Dienst sind nicht konfiguriert; es wird nichts versendet.', 'wm-peppol-as4-gateway' ) );
	}
}
