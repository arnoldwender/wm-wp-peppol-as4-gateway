<?php
/**
 * Reads and verifies the signed AS4 receipt that acknowledges a transmission.
 *
 * What the standard requires, quoted from the OASIS AS4 Profile of ebMS 3.0 v1.0 CS02 (checked
 * 2026-09-16):
 *
 *   "the content of the eb:Receipt element MUST be a valid ebbpsig:NonRepudiationInformation element"
 *   "the sender of the Receipt ... MUST use ds:Reference elements containing digests of the original
 *    message parts for which NRR is required"
 *   "An AS4 message that has been digitally signed MUST be acknowledged with a message containing an
 *    eb:Receipt signal that itself is digitally signed."
 *
 * So a receipt is evidence of delivery only when two things hold, and this class measures both
 * separately instead of reporting one verdict:
 *
 *   1. the receipt's own XML signature verifies against the certificate it carries, which says the
 *      receipt is authentic and untampered;
 *   2. the digests inside NonRepudiationInformation match the document this site actually sent,
 *      which says the receipt is about THAT invoice and not another one.
 *
 * What it does NOT do, and does not claim: it does not check that the signing certificate chains to
 * the Peppol PKI, it does not check revocation, and a verified signature is not a legal statement.
 * `signature_verified` means openssl accepted the signature over the canonicalised SignedInfo — no
 * more than that.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\AS4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ReceiptEvidence {

	public const NS_EBMS   = 'http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/';
	public const NS_EBBPSIG = 'http://docs.oasis-open.org/ebxml-bp/ebbp-signals-2.0';
	public const NS_DSIG   = 'http://www.w3.org/2000/09/xmldsig#';

	public const C14N_EXCLUSIVE           = 'http://www.w3.org/2001/10/xml-exc-c14n#';
	public const C14N_EXCLUSIVE_COMMENTS  = 'http://www.w3.org/2001/10/xml-exc-c14n#WithComments';
	public const C14N_INCLUSIVE           = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
	public const C14N_INCLUSIVE_COMMENTS  = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315#WithComments';
	public const TRANSFORM_ENVELOPED      = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

	/** The biggest receipt this will parse. A receipt is a signal, not a payload. */
	public const MAX_BYTES = 1048576;

	public const ERROR_XML          = 'wm_peppol_receipt_not_xml';
	public const ERROR_TOO_LARGE    = 'wm_peppol_receipt_too_large';
	public const ERROR_NO_RECEIPT   = 'wm_peppol_receipt_no_signal';

	/** Digest algorithms, by their XMLDSig identifier. */
	private const DIGESTS = [
		'http://www.w3.org/2001/04/xmlenc#sha256'       => 'sha256',
		'http://www.w3.org/2001/04/xmldsig-more#sha384' => 'sha384',
		'http://www.w3.org/2001/04/xmlenc#sha512'       => 'sha512',
		'http://www.w3.org/2000/09/xmldsig#sha1'        => 'sha1',
	];

	/** Signature algorithms this build of OpenSSL can check. */
	private const SIGNATURES = [
		'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256'   => OPENSSL_ALGO_SHA256,
		'http://www.w3.org/2001/04/xmldsig-more#rsa-sha384'   => OPENSSL_ALGO_SHA384,
		'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512'   => OPENSSL_ALGO_SHA512,
		'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256' => OPENSSL_ALGO_SHA256,
		'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha384' => OPENSSL_ALGO_SHA384,
		'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha512' => OPENSSL_ALGO_SHA512,
	];

	/**
	 * Parse and verify a receipt.
	 *
	 * @param string      $xml  The receipt as the access point delivered it.
	 * @param string|null $sent The document this site handed over, when it is known.
	 * @param int|null    $now  Unix timestamp to evaluate certificate validity against (defaults to current time).
	 * @return array{message_id:string, ref_to_message_id:string, timestamp:string, references:array<int, array{uri:string, algorithm:string, digest:string}>, signature_verified:bool, signature_error:string, signature_algorithm:string, digest_algorithm:string, signer:array<string,string>, trust_verified:bool, trust_status:string, trust_error:string, references_verified:int, references_total:int, payload_match:string, receipt_sha256:string}|\WP_Error
	 */
	public static function verify( string $xml, ?string $sent = null, ?int $now = null ) {
		if ( strlen( $xml ) > self::MAX_BYTES ) {
			return new \WP_Error(
				self::ERROR_TOO_LARGE,
				sprintf(
					/* translators: %d: size limit in bytes */
					__( 'Die Quittung ist größer als %d Bytes; eine AS4-Quittung ist ein Signal, keine Nutzlast.', 'wm-peppol-as4-gateway' ),
					self::MAX_BYTES
				),
				[ 'status' => 413 ]
			);
		}

		$document = self::load( $xml );
		if ( $document instanceof \WP_Error ) {
			return $document;
		}

		$xpath = new \DOMXPath( $document );
		$xpath->registerNamespace( 'eb', self::NS_EBMS );
		$xpath->registerNamespace( 'ebbp', self::NS_EBBPSIG );
		$xpath->registerNamespace( 'ds', self::NS_DSIG );

		$receipt = $xpath->query( '//eb:SignalMessage/eb:Receipt' )->item( 0 );
		if ( ! $receipt instanceof \DOMElement ) {
			return new \WP_Error(
				self::ERROR_NO_RECEIPT,
				__( 'Das Dokument enthält kein eb:Receipt in einer eb:SignalMessage.', 'wm-peppol-as4-gateway' ),
				[ 'status' => 422 ]
			);
		}

		$references = [];
		foreach ( $xpath->query( './/ebbp:NonRepudiationInformation/ebbp:MessagePartNRInformation/ds:Reference', $receipt ) as $reference ) {
			$algorithm = '';
			$method    = $xpath->query( 'ds:DigestMethod', $reference )->item( 0 );
			if ( $method instanceof \DOMElement ) {
				$algorithm = (string) $method->getAttribute( 'Algorithm' );
			}
			$value = $xpath->query( 'ds:DigestValue', $reference )->item( 0 );

			$references[] = [
				'uri'       => (string) $reference->getAttribute( 'URI' ),
				'algorithm' => $algorithm,
				'digest'    => $value instanceof \DOMNode ? trim( (string) $value->textContent ) : '',
			];
		}

		[ $verified, $error, $signer, $signature_algorithm, $digest_algorithm, $refs_ok, $refs_total, $leaf_pem, $chain_pems ] = self::check_signature( $xpath, $now );
		$trust = '' !== $leaf_pem
			? self::verify_signer_trust( $leaf_pem, $chain_pems )
			: [
				'trust_verified' => false,
				'trust_status'   => 'no_certificate',
				'trust_error'    => __( 'Die Quittung enthält kein prüfbares Signaturzertifikat.', 'wm-peppol-as4-gateway' ),
			];

		return [
			'message_id'          => self::text( $xpath, '//eb:SignalMessage/eb:MessageInfo/eb:MessageId' ),
			'ref_to_message_id'   => self::text( $xpath, '//eb:SignalMessage/eb:MessageInfo/eb:RefToMessageId' ),
			'timestamp'           => self::text( $xpath, '//eb:SignalMessage/eb:MessageInfo/eb:Timestamp' ),
			'references'          => $references,
			'signature_verified'  => $verified,
			'signature_error'     => $error,
			'signature_algorithm' => $signature_algorithm,
			'digest_algorithm'    => $digest_algorithm,
			'signer'              => $signer,
			'trust_verified'      => $trust['trust_verified'],
			'trust_status'        => $trust['trust_status'],
			'trust_error'         => $trust['trust_error'],
			'references_verified' => $refs_ok,
			'references_total'    => $refs_total,
			'payload_match'       => self::payload_match( $references, $sent ),
			'receipt_sha256'      => hash( 'sha256', $xml ),
		];
	}

	/**
	 * Whether the receipt attests to the document this site sent.
	 *
	 * Three answers, never two: "yes" when a digest of the sent document is among the references,
	 * "no" when references are present and none of them is it, and "unknown" when there is nothing to
	 * compare — no sent document, no references, or only algorithms this build cannot compute. A
	 * receipt whose digests we cannot reproduce is not proof and is not a forgery.
	 */
	public static function payload_match( array $references, ?string $sent ): string {
		if ( null === $sent || '' === $sent || [] === $references ) {
			return 'unknown';
		}

		$comparable = false;

		foreach ( $references as $reference ) {
			$algorithm = self::DIGESTS[ $reference['algorithm'] ] ?? '';
			if ( '' === $algorithm || '' === $reference['digest'] ) {
				continue;
			}
			$comparable = true;

			if ( hash_equals( base64_encode( hash( $algorithm, $sent, true ) ), $reference['digest'] ) ) {
				return 'yes';
			}
		}

		return $comparable ? 'no' : 'unknown';
	}

	/**
	 * Verify the receipt's own XML signature and certificate validity window.
	 *
	 * @return array{0:bool, 1:string, 2:array<string,string>, 3:string, 4:string, 5:int, 6:int, 7:string, 8:string[]}
	 */
	private static function check_signature( \DOMXPath $xpath, ?int $now = null ): array {
		$none = [ false, '', [], '', '', 0, 0, '', [] ];

		$signature = $xpath->query( '//ds:Signature' )->item( 0 );
		if ( ! $signature instanceof \DOMElement ) {
			return [ false, __( 'Die Quittung trägt keine XML-Signatur. Ein signierter AS4-Nachricht MUSS mit einer signierten Quittung beantwortet werden.', 'wm-peppol-as4-gateway' ) ] + $none;
		}

		$signed_info  = $xpath->query( 'ds:SignedInfo', $signature )->item( 0 );
		$value_node   = $xpath->query( 'ds:SignatureValue', $signature )->item( 0 );
		$cert_nodes   = $xpath->query( 'ds:KeyInfo//ds:X509Certificate', $signature );
		$certificate  = $cert_nodes->item( 0 );

		if ( ! $signed_info instanceof \DOMElement || ! $value_node instanceof \DOMNode ) {
			return [ false, __( 'Die Signatur ist unvollständig: ds:SignedInfo oder ds:SignatureValue fehlt.', 'wm-peppol-as4-gateway' ) ] + $none;
		}
		if ( ! $certificate instanceof \DOMNode ) {
			return [ false, __( 'Die Signatur nennt kein X.509-Zertifikat; ohne den öffentlichen Schlüssel ist sie nicht prüfbar.', 'wm-peppol-as4-gateway' ) ] + $none;
		}

		$method    = $xpath->query( 'ds:SignatureMethod', $signed_info )->item( 0 );
		$algorithm = $method instanceof \DOMElement ? (string) $method->getAttribute( 'Algorithm' ) : '';
		if ( ! isset( self::SIGNATURES[ $algorithm ] ) ) {
			return [
				false,
				sprintf(
					/* translators: %s: signature algorithm identifier */
					__( 'Signaturverfahren %s wird von dieser Installation nicht geprüft.', 'wm-peppol-as4-gateway' ),
					'' !== $algorithm ? $algorithm : '(keines genannt)'
				),
				[],
				$algorithm,
				'',
				0,
				0,
				'',
				[],
			];
		}

		$canonical  = self::canonicalise( $signed_info, $xpath );
		$pem        = "-----BEGIN CERTIFICATE-----\n" . chunk_split( preg_replace( '/\s+/', '', (string) $certificate->textContent ) ?? '', 64, "\n" ) . "-----END CERTIFICATE-----\n";
		$chain_pems = [];
		for ( $idx = 1; $idx < $cert_nodes->length; $idx++ ) {
			$extra_node = $cert_nodes->item( $idx );
			if ( $extra_node instanceof \DOMNode ) {
				$extra_b64 = preg_replace( '/\s+/', '', (string) $extra_node->textContent ) ?? '';
				if ( '' !== $extra_b64 ) {
					$chain_pems[] = "-----BEGIN CERTIFICATE-----\n" . chunk_split( $extra_b64, 64, "\n" ) . "-----END CERTIFICATE-----\n";
				}
			}
		}

		$public = @openssl_pkey_get_public( $pem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a certificate that does not parse is reported below.

		if ( false === $public ) {
			return [ false, __( 'Das X.509-Zertifikat der Signatur ist nicht lesbar.', 'wm-peppol-as4-gateway' ) ] + $none;
		}

		$ok = openssl_verify(
			$canonical,
			(string) base64_decode( preg_replace( '/\s+/', '', (string) $value_node->textContent ) ?? '', true ),
			$public,
			self::SIGNATURES[ $algorithm ]
		);

		$signer           = self::describe_signer( $pem, $now );
		$digest_method    = $xpath->query( 'ds:Reference/ds:DigestMethod', $signed_info )->item( 0 );
		$digest_algorithm = $digest_method instanceof \DOMElement ? (string) $digest_method->getAttribute( 'Algorithm' ) : '';

		if ( 1 !== $ok ) {
			return [
				false,
				-1 === $ok
					? __( 'OpenSSL konnte die Signatur nicht prüfen.', 'wm-peppol-as4-gateway' )
					: __( 'Die Signatur passt nicht zum Inhalt der Quittung: sie wurde nach dem Signieren verändert oder mit einem anderen Schlüssel erzeugt.', 'wm-peppol-as4-gateway' ),
				$signer,
				$algorithm,
				$digest_algorithm,
				0,
				(int) $xpath->query( 'ds:Reference', $signed_info )->length,
				$pem,
				$chain_pems,
			];
		}

		// The signature covers SignedInfo, and SignedInfo covers the message through its digests. A
		// valid signature over a reference list whose digests no longer match the document proves
		// nothing about the document, so the references are checked too.
		[ $refs_ok, $refs_total ] = self::check_references( $signed_info, $xpath );

		if ( $refs_ok !== $refs_total ) {
			return [
				false,
				sprintf(
					/* translators: 1: references that matched, 2: references in total */
					__( 'Die Signatur selbst ist gültig, aber nur %1$d von %2$d Prüfsummen passen zum Inhalt: die Quittung wurde nach dem Signieren verändert.', 'wm-peppol-as4-gateway' ),
					$refs_ok,
					$refs_total
				),
				$signer,
				$algorithm,
				$digest_algorithm,
				$refs_ok,
				$refs_total,
				$pem,
				$chain_pems,
			];
		}

		if ( '1' === ( $signer['expired'] ?? '0' ) ) {
			return [
				false,
				__( 'Das X.509-Zertifikat der Quittung ist abgelaufen.', 'wm-peppol-as4-gateway' ),
				$signer,
				$algorithm,
				$digest_algorithm,
				$refs_ok,
				$refs_total,
				$pem,
				$chain_pems,
			];
		}

		if ( '1' === ( $signer['not_yet_valid'] ?? '0' ) ) {
			return [
				false,
				__( 'Das X.509-Zertifikat der Quittung ist noch nicht gültig.', 'wm-peppol-as4-gateway' ),
				$signer,
				$algorithm,
				$digest_algorithm,
				$refs_ok,
				$refs_total,
				$pem,
				$chain_pems,
			];
		}

		return [ true, '', $signer, $algorithm, $digest_algorithm, $refs_ok, $refs_total, $pem, $chain_pems ];
	}

	/**
	 * Each ds:Reference of SignedInfo against the node it points at.
	 *
	 * @return array{0:int, 1:int}
	 */
	private static function check_references( \DOMElement $signed_info, \DOMXPath $xpath ): array {
		$total = 0;
		$ok    = 0;

		foreach ( $xpath->query( 'ds:Reference', $signed_info ) as $reference ) {
			$total++;

			$method    = $xpath->query( 'ds:DigestMethod', $reference )->item( 0 );
			$algorithm = self::DIGESTS[ $method instanceof \DOMElement ? (string) $method->getAttribute( 'Algorithm' ) : '' ] ?? '';
			$expected  = $xpath->query( 'ds:DigestValue', $reference )->item( 0 );
			$uri       = (string) $reference->getAttribute( 'URI' );

			if ( '' === $algorithm || ! $expected instanceof \DOMNode ) {
				continue;
			}

			$target = self::resolve( $uri, $xpath );
			if ( ! $target instanceof \DOMElement ) {
				continue;
			}

			$enveloped = false;
			foreach ( $xpath->query( 'ds:Transforms/ds:Transform', $reference ) as $transform ) {
				if ( self::TRANSFORM_ENVELOPED === (string) $transform->getAttribute( 'Algorithm' ) ) {
					$enveloped = true;
				}
			}

			$content = self::canonicalise( $target, $xpath, $reference, $enveloped );

			if ( hash_equals( base64_encode( hash( $algorithm, $content, true ) ), trim( (string) $expected->textContent ) ) ) {
				$ok++;
			}
		}

		return [ $ok, $total ];
	}

	/**
	 * The node a reference URI points at. "" is the whole document; "#id" is the element carrying
	 * that id under any of the attribute names WS-Security and XMLDSig use.
	 */
	private static function resolve( string $uri, \DOMXPath $xpath ): ?\DOMElement {
		if ( '' === $uri ) {
			$root = $xpath->document->documentElement;

			return $root instanceof \DOMElement ? $root : null;
		}

		if ( ! str_starts_with( $uri, '#' ) ) {
			// An external reference is not resolved: this class never fetches anything.
			return null;
		}

		$id = substr( $uri, 1 );
		foreach ( [ 'Id', 'ID', 'id' ] as $attribute ) {
			$found = $xpath->query( sprintf( '//*[@*[local-name() = "%s"] = "%s"]', $attribute, $id ) )->item( 0 );
			if ( $found instanceof \DOMElement ) {
				return $found;
			}
		}

		return null;
	}

	/**
	 * Canonicalise a node the way its own method says, which is what the digest was computed over.
	 *
	 * The method of a ds:Reference lives in its last ds:Transform; the method of ds:SignedInfo lives
	 * in the ds:CanonicalizationMethod beside it. Exclusive canonicalisation may carry an
	 * InclusiveNamespaces PrefixList, and dropping it changes the bytes and therefore the digest.
	 */
	private static function canonicalise( \DOMElement $node, \DOMXPath $xpath, ?\DOMNode $reference = null, bool $enveloped = false ): string {
		$algorithm = '';
		$prefixes  = null;

		$source = null;
		if ( null === $reference ) {
			// ds:CanonicalizationMethod is a child of ds:SignedInfo, which is the node being
			// canonicalised here.
			$source = $xpath->query( 'ds:CanonicalizationMethod', $node )->item( 0 );
		} else {
			foreach ( $xpath->query( 'ds:Transforms/ds:Transform', $reference ) as $transform ) {
				if ( self::TRANSFORM_ENVELOPED !== (string) $transform->getAttribute( 'Algorithm' ) ) {
					$source = $transform;
				}
			}
		}

		if ( $source instanceof \DOMElement ) {
			$algorithm = (string) $source->getAttribute( 'Algorithm' );
			$list      = $xpath->query( '*[local-name() = "InclusiveNamespaces"]', $source )->item( 0 );
			if ( $list instanceof \DOMElement && '' !== (string) $list->getAttribute( 'PrefixList' ) ) {
				$prefixes = preg_split( '/\s+/', trim( (string) $list->getAttribute( 'PrefixList' ) ) ) ?: null;
			}
		}

		$exclusive = self::C14N_INCLUSIVE !== $algorithm && self::C14N_INCLUSIVE_COMMENTS !== $algorithm;
		$comments  = self::C14N_EXCLUSIVE_COMMENTS === $algorithm || self::C14N_INCLUSIVE_COMMENTS === $algorithm;

		if ( ! $enveloped ) {
			return (string) $node->C14N( $exclusive, $comments, null, $prefixes );
		}

		// The enveloped-signature transform removes the signature from what is digested. The node is
		// copied so the document the caller holds is not modified.
		$copy     = $node->cloneNode( true );
		$carrier  = new \DOMDocument();
		$imported = $carrier->importNode( $copy, true );
		$carrier->appendChild( $imported );

		$inner = new \DOMXPath( $carrier );
		$inner->registerNamespace( 'ds', self::NS_DSIG );
		foreach ( iterator_to_array( $inner->query( '//ds:Signature' ) ) as $found ) {
			$found->parentNode?->removeChild( $found );
		}

		return (string) $carrier->documentElement?->C14N( $exclusive, $comments, null, $prefixes );
	}

	/**
	 * Verify signer trust against configured SHA-256 fingerprint pins or local PKI CA bundle (0 network calls).
	 *
	 * @param string   $leaf_pem   PEM of the signing leaf certificate.
	 * @param string[] $chain_pems Additional untrusted intermediate certificates carried in ds:X509Data.
	 * @return array{trust_verified:bool, trust_status:string, trust_error:string}
	 */
	public static function verify_signer_trust( string $leaf_pem, array $chain_pems = [] ): array {
		$pins       = [];
		$bundle_pem = '';
		$enforced   = false;

		if ( class_exists( \WMCyber\Peppol\Core\Settings::class ) ) {
			$pins       = \WMCyber\Peppol\Core\Settings::trusted_signer_fingerprints();
			$bundle_pem = \WMCyber\Peppol\Core\Settings::trust_bundle_pem();
			$enforced   = \WMCyber\Peppol\Core\Settings::trust_anchor_enforced();
		} else {
			if ( defined( 'WM_PEPPOL_TRUSTED_SIGNER_SHA256' ) ) {
				foreach ( preg_split( '/[\s,;]+/', trim( (string) constant( 'WM_PEPPOL_TRUSTED_SIGNER_SHA256' ) ) ) ?: [] as $tok ) {
					$hex = strtolower( str_replace( ':', '', trim( $tok ) ) );
					if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
						$pins[] = $hex;
					}
				}
			}
			if ( defined( 'WM_PEPPOL_TRUST_BUNDLE_PEM' ) ) {
				$bundle_pem = trim( (string) constant( 'WM_PEPPOL_TRUST_BUNDLE_PEM' ) );
			}
			if ( defined( 'WM_PEPPOL_REQUIRE_TRUST_ANCHOR' ) ) {
				$enforced = '1' === (string) constant( 'WM_PEPPOL_REQUIRE_TRUST_ANCHOR' ) || true === constant( 'WM_PEPPOL_REQUIRE_TRUST_ANCHOR' );
			}
		}

		$fingerprint = strtolower( (string) @openssl_x509_fingerprint( $leaf_pem, 'sha256' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( '' !== $fingerprint && [] !== $pins ) {
			foreach ( $pins as $pin ) {
				if ( hash_equals( $pin, $fingerprint ) ) {
					return [
						'trust_verified' => true,
						'trust_status'   => 'pinned',
						'trust_error'    => '',
					];
				}
			}
		}

		if ( '' !== $bundle_pem && self::chains_to_bundle( $leaf_pem, $bundle_pem, $chain_pems ) ) {
			return [
				'trust_verified' => true,
				'trust_status'   => 'pki_chained',
				'trust_error'    => '',
			];
		}

		if ( [] === $pins && '' === $bundle_pem ) {
			if ( $enforced ) {
				return [
					'trust_verified' => false,
					'trust_status'   => 'unconfigured_rejected',
					'trust_error'    => __( 'Es ist weder ein Zertifikats-Fingerabdruck noch ein CA-Zertifikatsbündel hinterlegt, obwohl die Vertrauensanker-Prüfung erzwungen wird.', 'wm-peppol-as4-gateway' ),
				];
			}
			return [
				'trust_verified' => true,
				'trust_status'   => 'unconfigured',
				'trust_error'    => '',
			];
		}

		return [
			'trust_verified' => false,
			'trust_status'   => 'untrusted',
			'trust_error'    => __( 'Das Signaturzertifikat der Quittung passt weder zu einem hinterlegten SHA-256-Fingerabdruck noch zu einem vertrauenswürdigen CA-Zertifikatsbündel.', 'wm-peppol-as4-gateway' ),
		];
	}

	/**
	 * Verify X.509 chain strictly against the local CA bundle (never falling back to system CAs, zero network calls).
	 *
	 * @param string   $leaf_pem   Leaf certificate PEM.
	 * @param string   $bundle_pem Admin-configured trusted CA PEM bundle.
	 * @param string[] $chain_pems Untrusted intermediate certificates from the incoming receipt.
	 */
	private static function chains_to_bundle( string $leaf_pem, string $bundle_pem, array $chain_pems ): bool {
		if ( ! function_exists( 'openssl_x509_checkpurpose' ) ) {
			return false;
		}
		$ca_file = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'wm-peppol-ca' ) : tempnam( sys_get_temp_dir(), 'wm-peppol-ca-' );
		$un_file = function_exists( 'wp_tempnam' ) ? wp_tempnam( 'wm-peppol-un' ) : tempnam( sys_get_temp_dir(), 'wm-peppol-un-' );
		if ( ! is_string( $ca_file ) || '' === $ca_file || ! is_string( $un_file ) || '' === $un_file ) {
			return false;
		}

		try {
			file_put_contents( $ca_file, $bundle_pem );
			file_put_contents( $un_file, $bundle_pem . "\n" . implode( "\n", $chain_pems ) );
			$ok = @openssl_x509_checkpurpose( $leaf_pem, X509_PURPOSE_ANY, [ $ca_file ], $un_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return true === $ok;
		} finally {
			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $ca_file );
				wp_delete_file( $un_file );
			} else {
				@unlink( $ca_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $un_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}

	/**
	 * Subject, issuer, serial and validity of the signing certificate, read from the certificate.
	 *
	 * @param string   $pem Certificate PEM.
	 * @param int|null $now Unix timestamp to evaluate validity against (defaults to current time).
	 * @return array<string, string>
	 */
	public static function describe_signer( string $pem, ?int $now = null ): array {
		if ( ! function_exists( 'openssl_x509_parse' ) ) {
			return [];
		}

		$parsed = @openssl_x509_parse( $pem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an unreadable certificate is reported as no signer.
		if ( ! is_array( $parsed ) ) {
			return [];
		}

		$clock = $now ?? time();
		$from  = isset( $parsed['validFrom_time_t'] ) ? (int) $parsed['validFrom_time_t'] : 0;
		$to    = isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : 0;

		return [
			'subject'       => isset( $parsed['subject']['CN'] ) ? (string) $parsed['subject']['CN'] : '',
			'issuer'        => isset( $parsed['issuer']['CN'] ) ? (string) $parsed['issuer']['CN'] : '',
			'serial'        => isset( $parsed['serialNumberHex'] ) ? (string) $parsed['serialNumberHex'] : '',
			'valid_from'    => $from > 0 ? gmdate( 'c', $from ) : '',
			'valid_to'      => $to > 0 ? gmdate( 'c', $to ) : '',
			'expired'       => $to > 0 && $to < $clock ? '1' : '0',
			'not_yet_valid' => $from > 0 && $from > $clock ? '1' : '0',
			'fingerprint'   => strtolower( (string) openssl_x509_fingerprint( $pem, 'sha256' ) ),
		];
	}

	/** @return \DOMDocument|\WP_Error */
	private static function load( string $xml ) {
		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		// No LIBXML_NOENT: a receipt arrives from outside and entity substitution is the XXE door.
		$loaded = $document->loadXML( $xml, LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new \WP_Error( self::ERROR_XML, __( 'Die Quittung ist kein wohlgeformtes XML.', 'wm-peppol-as4-gateway' ), [ 'status' => 400 ] );
		}

		return $document;
	}

	private static function text( \DOMXPath $xpath, string $expression ): string {
		$node = $xpath->query( $expression )->item( 0 );

		return $node instanceof \DOMNode ? trim( (string) $node->textContent ) : '';
	}
}
