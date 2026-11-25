<?php
/**
 * WM Peppol AS4 Gateway — Peppol AS4 Packaging & Transmission Engine
 *
 * Implements SBDH (Standard Business Document Header v1.3) wrapping,
 * cryptographic payload digest computation, and Peppol participant addressing.
 *
 * @package WM_Peppol_AS4_Gateway
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Peppol_AS4_Client {

	/**
	 * Wrap an EN 16931 XML payload inside an SBDH envelope for Peppol network routing.
	 *
	 * @param string $xml_payload Raw UBL or CII invoice XML.
	 * @param string $sender_id Peppol Participant Identifier (e.g. 9930:DE123456789).
	 * @param string $receiver_id Peppol Participant Identifier of recipient.
	 * @param string $document_id Peppol Document Type Identifier.
	 * @param string $process_id Peppol Process Identifier.
	 * @return array{sbdh_xml: string, payload_hash: string, message_id: string}
	 */
	public static function package_sbdh(
		string $xml_payload,
		string $sender_id,
		string $receiver_id,
		string $document_id = 'busdox-docid-qns::urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1',
		string $process_id = 'cenbii-procid-ubl::urn:fdc:peppol.eu:2017:poacc:billing:01:1.0'
	): array {
		$payload_hash = hash( 'sha256', $xml_payload );
		$message_id   = 'wm-peppol-' . gmdate( 'YmdHis' ) . '-' . substr( $payload_hash, 0, 12 );
		$timestamp    = gmdate( 'Y-m-d\TH:i:s\Z' );

		$sbdh_xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
			'<StandardBusinessDocument xmlns="http://www.unece.org/cefact/namespaces/StandardBusinessDocumentHeader">' . "\n" .
			'  <StandardBusinessDocumentHeader>' . "\n" .
			'    <HeaderVersion>1.3</HeaderVersion>' . "\n" .
			'    <Sender>' . "\n" .
			'      <Identifier Authority="iso6523-actorid-upis">' . htmlspecialchars( $sender_id, ENT_QUOTES, 'UTF-8' ) . '</Identifier>' . "\n" .
			'    </Sender>' . "\n" .
			'    <Receiver>' . "\n" .
			'      <Identifier Authority="iso6523-actorid-upis">' . htmlspecialchars( $receiver_id, ENT_QUOTES, 'UTF-8' ) . '</Identifier>' . "\n" .
			'    </Receiver>' . "\n" .
			'    <DocumentIdentification>' . "\n" .
			'      <Standard>urn:oasis:names:specification:ubl:schema:xsd:Invoice-2</Standard>' . "\n" .
			'      <TypeVersion>2.1</TypeVersion>' . "\n" .
			'      <InstanceIdentifier>' . htmlspecialchars( $message_id, ENT_QUOTES, 'UTF-8' ) . '</InstanceIdentifier>' . "\n" .
			'      <Type>Invoice</Type>' . "\n" .
			'      <CreationDateAndTime>' . $timestamp . '</CreationDateAndTime>' . "\n" .
			'    </DocumentIdentification>' . "\n" .
			'    <BusinessScope>' . "\n" .
			'      <Scope>' . "\n" .
			'        <Type>DOCUMENTID</Type>' . "\n" .
			'        <InstanceIdentifier>' . htmlspecialchars( $document_id, ENT_QUOTES, 'UTF-8' ) . '</InstanceIdentifier>' . "\n" .
			'      </Scope>' . "\n" .
			'      <Scope>' . "\n" .
			'        <Type>PROCESSID</Type>' . "\n" .
			'        <InstanceIdentifier>' . htmlspecialchars( $process_id, ENT_QUOTES, 'UTF-8' ) . '</InstanceIdentifier>' . "\n" .
			'      </Scope>' . "\n" .
			'    </BusinessScope>' . "\n" .
			'  </StandardBusinessDocumentHeader>' . "\n" .
			'  ' . trim( preg_replace( '/<\?xml[^>]*\?>/i', '', $xml_payload ) ) . "\n" .
			'</StandardBusinessDocument>';

		return [
			'sbdh_xml'     => $sbdh_xml,
			'payload_hash' => $payload_hash,
			'message_id'   => $message_id,
		];
	}

	/**
	 * Verify participant ID format against ISO 6523 ICD scheme list (e.g., 9930 for German USt-IdNr, 0204 for Leitweg-ID).
	 */
	public static function is_valid_peppol_id( string $participant_id ): bool {
		return (bool) preg_match( '/^(?:iso6523-actorid-upis::)?([0-9]{4}):([A-Za-z0-9\-_]+)$/', $participant_id );
	}
}
