<?php
declare(strict_types=1);

namespace WMCyber\Peppol\AS4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class As4MessagePackager {
	/**
	 * Package UBL XML invoice into an OASIS ebMS3 AS4 UserMessage envelope.
	 *
	 * @param string $ubl_xml
	 * @param string $c2_sender_ap
	 * @param string $c3_receiver_ap
	 * @param string $message_id
	 * @return array{soap_envelope: string, message_id: string, payload_hash: string}
	 */
	public function package_user_message( string $ubl_xml, string $c2_sender_ap, string $c3_receiver_ap, ?string $message_id = null ): array {
		$mid = $message_id ?? sprintf( '%s@%s', bin2hex( random_bytes( 16 ) ), wp_parse_url( $c2_sender_ap, PHP_URL_HOST ) ?: 'localhost' );
		$payload_hash = hash( 'sha256', $ubl_xml );
		$timestamp    = gmdate( 'Y-m-d\TH:i:s\Z' );

		// The three caller-supplied values are XML text nodes; a "<" or "&" in a participant id must
		// not become markup. The envelope is assembled line by line (Plugin Check refuses heredocs).
		$x = static fn( string $v ): string => htmlspecialchars( $v, ENT_XML1 | ENT_QUOTES, 'UTF-8' );

		$soap = implode( "\n", [
			'<?xml version="1.0" encoding="UTF-8"?>',
			'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"',
			'               xmlns:eb="http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/"',
			'               xmlns:wsu="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">',
			'  <soap:Header>',
			'    <eb:Messaging soap:mustUnderstand="true">',
			'      <eb:UserMessage>',
			'        <eb:MessageInfo>',
			'          <eb:Timestamp>' . $timestamp . '</eb:Timestamp>',
			'          <eb:MessageId>' . $x( $mid ) . '</eb:MessageId>',
			'        </eb:MessageInfo>',
			'        <eb:PartyInfo>',
			'          <eb:From>',
			'            <eb:PartyId type="urn:fdc:peppol.eu:2017:identifiers:ap">' . $x( $c2_sender_ap ) . '</eb:PartyId>',
			'            <eb:Role>http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/initiator</eb:Role>',
			'          </eb:From>',
			'          <eb:To>',
			'            <eb:PartyId type="urn:fdc:peppol.eu:2017:identifiers:ap">' . $x( $c3_receiver_ap ) . '</eb:PartyId>',
			'            <eb:Role>http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/responder</eb:Role>',
			'          </eb:To>',
			'        </eb:PartyInfo>',
			'        <eb:CollaborationInfo>',
			'          <eb:AgreementRef>urn:fdc:peppol.eu:2017:agreements:tia:peppol</eb:AgreementRef>',
			'          <eb:Service type="cenbii-procid-ubl">urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</eb:Service>',
			'          <eb:Action>busdox-docid-qns::urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1</eb:Action>',
			'        </eb:CollaborationInfo>',
			'        <eb:PayloadInfo>',
			'          <eb:PartInfo href="cid:invoice-payload-001">',
			'            <eb:PartProperties>',
			'              <eb:Property name="MimeType">application/xml</eb:Property>',
			'              <eb:Property name="CharacterSet">UTF-8</eb:Property>',
			'              <eb:Property name="Digest">' . $payload_hash . '</eb:Property>',
			'            </eb:PartProperties>',
			'          </eb:PartInfo>',
			'        </eb:PayloadInfo>',
			'      </eb:UserMessage>',
			'    </eb:Messaging>',
			'  </soap:Header>',
			'  <soap:Body>',
			'    <!-- Encrypted / Compressed Payload MIME Attachment Reference -->',
			'  </soap:Body>',
			'</soap:Envelope>',
		] );

		return [
			'soap_envelope' => $soap,
			'message_id'    => $mid,
			'payload_hash'  => $payload_hash,
		];
	}
}
