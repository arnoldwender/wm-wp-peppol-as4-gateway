<?php
/**
 * Peppol receiver discovery: SML (DNS) to SMP (HTTP) to the receiving access point.
 *
 * Until 2026-09-15 lookup_receiver_endpoint() returned a fixed access point with `cert_valid => true` for
 * any participant id, including "0000:NOBODY-AT-ALL", and the REST route, the CLI and the admin page
 * presented that as a result; no DNS or SMP request was ever made. From then until 2026-09-16 it answered
 * an honest 501. This is the lookup itself.
 *
 * Every constant below was measured against the production SML and a live SMP on 2026-09-16, not taken
 * from memory:
 *
 *   php -r 'echo rtrim(base32(hash("sha256","0007:5567321707",true)),"=");'
 *   dig +short NAPTR <hash>.iso6523-actorid-upis.participant.sml.prod.tech.peppol.org
 *   -> 100 10 "U" "Meta:SMP" "!.*!https://smp.qvalia.com/!" .
 *   curl https://smp.qvalia.com/iso6523-actorid-upis::0007:5567321707
 *   -> ServiceGroup with ServiceMetadataReference hrefs
 *   curl <href of the BIS Billing invoice>
 *   -> <Endpoint transportProfile="peppol-transport-as4-v2_0"> with Address, Certificate,
 *      ServiceActivationDate, ServiceExpirationDate
 *
 * The older scheme this file carried — md5 of "scheme::value" behind a "b-" prefix under
 * edelivery.tech.ec.europa.eu — is not what the SML answers to. The hash is SHA-256, Base32 without
 * padding, over the lower-cased identifier alone, and there is no prefix.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SmpClient {

	/** DNS zone of the production SML, measured 2026-09-16. */
	public const SML_ZONE_PRODUCTION = 'participant.sml.prod.tech.peppol.org';

	/** DNS zone of the test SML (SMK). */
	public const SML_ZONE_TEST = 'participant.sml.test.tech.peppol.org';

	/** Option holding which zone this site asks. */
	public const ZONE_OPTION = 'wm_peppol_sml_zone';

	/** Identifier scheme that forms the middle DNS label. */
	public const DEFAULT_SCHEME = 'iso6523-actorid-upis';

	/** Service field of the NAPTR record that names an SMP. */
	public const NAPTR_SERVICE = 'Meta:SMP';

	/** Document type scheme of the SMP path. */
	public const DOCTYPE_SCHEME = 'busdox-docid-qns';

	/**
	 * Peppol BIS Billing 3.0 invoice, the document type this plugin builds. Measured on a live SMP,
	 * percent-decoded from the ServiceMetadataReference of participant 0007:5567321707.
	 */
	public const DOCTYPE_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2::Invoice##urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0::2.1';

	/** AS4 transport profile of a Peppol endpoint. */
	public const TRANSPORT_PROFILE_AS4 = 'peppol-transport-as4-v2_0';

	/** How long a successful lookup is kept. A registration changes rarely; a failure is not cached. */
	public const CACHE_TTL = 21600;

	/** Seconds an SMP request may take. */
	public const HTTP_TIMEOUT = 10;

	/** Error codes a caller can tell apart. */
	public const ERROR_IDENTIFIER   = 'wm_peppol_smp_bad_identifier';
	public const ERROR_DNS          = 'wm_peppol_smp_dns_failed';
	public const ERROR_NO_META      = 'wm_peppol_smp_no_meta_record';
	public const ERROR_HTTP         = 'wm_peppol_smp_http_failed';
	public const ERROR_NOT_OFFERED  = 'wm_peppol_smp_document_not_offered';
	public const ERROR_PARSE        = 'wm_peppol_smp_parse_failed';
	public const ERROR_NO_ENDPOINT  = 'wm_peppol_smp_no_as4_endpoint';

	/**
	 * Resolver of NAPTR records. Injectable so the suite can exercise the whole path without DNS.
	 *
	 * @var callable(string):array
	 */
	private $resolver;

	/**
	 * @param callable(string):array|null $resolver Returns the NAPTR records of a name, as dns_get_record does.
	 */
	public function __construct( ?callable $resolver = null ) {
		$this->resolver = $resolver ?? static function ( string $name ): array {
			// DNS_NAPTR is what the SML answers with since the CNAME migration; a resolver that
			// cannot ask for it returns false, which reads as "nothing found" below.
			$records = @dns_get_record( $name, DNS_NAPTR ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a name that does not exist is an answer, not a warning.

			return is_array( $records ) ? $records : [];
		};
	}

	/** Discovery makes real requests now. */
	public function is_implemented(): bool {
		return true;
	}

	/**
	 * The SML zone this site asks: production unless the option says test.
	 */
	public static function zone(): string {
		return 'test' === (string) get_option( self::ZONE_OPTION, 'production' )
			? self::SML_ZONE_TEST
			: self::SML_ZONE_PRODUCTION;
	}

	/**
	 * Split an identifier into its scheme label and its value.
	 *
	 * Accepts "0007:5567321707" (the value alone, with the four-digit EAS scheme in it) and
	 * "iso6523-actorid-upis::0007:5567321707" (with the identifier scheme in front).
	 *
	 * @return array{scheme:string, value:string}|\WP_Error
	 */
	public function split_identifier( string $participant_id ) {
		$participant_id = trim( $participant_id );

		if ( '' === $participant_id ) {
			return new \WP_Error( self::ERROR_IDENTIFIER, __( 'Kein Peppol-Teilnehmerbezeichner angegeben.', 'wm-peppol-as4-gateway' ), [ 'status' => 400 ] );
		}

		$scheme = self::DEFAULT_SCHEME;
		$value  = $participant_id;

		if ( str_contains( $participant_id, '::' ) ) {
			[ $scheme, $value ] = explode( '::', $participant_id, 2 );
		}

		$scheme = strtolower( trim( $scheme ) );
		$value  = trim( $value );

		// The value carries its own EAS scheme, four digits and a colon: 0007:5567321707.
		if ( '' === $value || ! preg_match( '/^[0-9]{4}:.+$/', $value ) ) {
			return new \WP_Error(
				self::ERROR_IDENTIFIER,
				sprintf(
					/* translators: %s: the identifier as given */
					__( 'Der Bezeichner "%s" hat nicht die Form <vierstelliges EAS-Schema>:<Kennung>, zum Beispiel 9930:DE123456789.', 'wm-peppol-as4-gateway' ),
					$participant_id
				),
				[ 'status' => 400 ]
			);
		}

		if ( ! preg_match( '/^[a-z0-9.\-]+$/', $scheme ) ) {
			return new \WP_Error( self::ERROR_IDENTIFIER, __( 'Das Bezeichner-Schema enthält Zeichen, die in einem DNS-Namen nicht vorkommen dürfen.', 'wm-peppol-as4-gateway' ), [ 'status' => 400 ] );
		}

		return [ 'scheme' => $scheme, 'value' => $value ];
	}

	/**
	 * The DNS name the SML answers for a participant.
	 *
	 * Base32 of the SHA-256 of the lower-cased identifier value, without the padding "=", then the
	 * identifier scheme, then the zone. No prefix.
	 *
	 * @return string|\WP_Error
	 */
	public function dns_name( string $participant_id, ?string $zone = null ) {
		$parts = $this->split_identifier( $participant_id );
		if ( $parts instanceof \WP_Error ) {
			return $parts;
		}

		$hash = rtrim( self::base32( hash( 'sha256', strtolower( $parts['value'] ), true ) ), '=' );

		return strtolower( $hash ) . '.' . $parts['scheme'] . '.' . ( $zone ?? self::zone() );
	}

	/**
	 * Base32 (RFC 4648) of raw bytes. PHP has no base32_encode.
	 */
	public static function base32( string $bytes ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bits     = '';

		foreach ( str_split( $bytes ) as $byte ) {
			$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
		}

		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= $alphabet[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
		}

		return str_pad( $out, (int) ( ceil( strlen( $out ) / 8 ) * 8 ), '=' );
	}

	/**
	 * The SMP that serves a participant, from the NAPTR record of its SML name.
	 *
	 * @return string|\WP_Error SMP base URL without a trailing slash.
	 */
	public function resolve_smp_url( string $participant_id, ?string $zone = null ) {
		$name = $this->dns_name( $participant_id, $zone );
		if ( $name instanceof \WP_Error ) {
			return $name;
		}

		$records = ( $this->resolver )( $name );

		if ( [] === $records ) {
			return new \WP_Error(
				self::ERROR_DNS,
				sprintf(
					/* translators: %s: DNS name that was asked */
					__( 'Für %s gibt es keinen NAPTR-Eintrag: der Teilnehmer ist in dieser SML-Zone nicht registriert, oder der Resolver beantwortet keine NAPTR-Abfragen.', 'wm-peppol-as4-gateway' ),
					$name
				),
				[ 'status' => 404, 'dns_name' => $name ]
			);
		}

		// Lowest order wins, then lowest preference — the NAPTR rule (RFC 3403).
		usort(
			$records,
			static fn( array $a, array $b ): int => [ (int) ( $a['order'] ?? 0 ), (int) ( $a['pref'] ?? 0 ) ] <=> [ (int) ( $b['order'] ?? 0 ), (int) ( $b['pref'] ?? 0 ) ]
		);

		foreach ( $records as $record ) {
			if ( 0 !== strcasecmp( (string) ( $record['services'] ?? '' ), self::NAPTR_SERVICE ) ) {
				continue;
			}

			$url = self::url_from_naptr_regexp( (string) ( $record['regex'] ?? '' ) );
			if ( '' !== $url ) {
				return untrailingslashit( $url );
			}
		}

		return new \WP_Error(
			self::ERROR_NO_META,
			sprintf(
				/* translators: %s: DNS name that was asked */
				__( 'Der NAPTR-Eintrag von %s nennt keinen SMP (Service "Meta:SMP" mit einer URL).', 'wm-peppol-as4-gateway' ),
				$name
			),
			[ 'status' => 502, 'dns_name' => $name ]
		);
	}

	/**
	 * The URL inside a NAPTR regexp field, which the SML writes as "!.*!https://smp.example/!".
	 */
	public static function url_from_naptr_regexp( string $regexp ): string {
		$regexp = trim( $regexp );
		if ( '' === $regexp ) {
			return '';
		}

		$delimiter = $regexp[0];
		$parts     = explode( $delimiter, $regexp );

		// !<match>!<replacement>! leaves ['', match, replacement, ''].
		$url = isset( $parts[2] ) ? trim( $parts[2] ) : '';

		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	/**
	 * The SMP URL of one document type of one participant, as the SMP publishes it.
	 */
	public function service_metadata_url( string $smp_url, string $participant_id, string $document_type ) {
		$parts = $this->split_identifier( $participant_id );
		if ( $parts instanceof \WP_Error ) {
			return $parts;
		}

		return sprintf(
			'%s/%s/services/%s',
			untrailingslashit( $smp_url ),
			rawurlencode( $parts['scheme'] . '::' . $parts['value'] ),
			rawurlencode( self::DOCTYPE_SCHEME . '::' . $document_type )
		);
	}

	/**
	 * Look a receiver up: SML for the SMP, SMP for the access point of a document type.
	 *
	 * Returns what the SMP said, and what this plugin measured about it — never a verdict it did not
	 * compute. `certificate_expired` comes from the dates in the certificate itself.
	 *
	 * @return array{participant_id:string, dns_name:string, smp_url:string, access_point_url:string, transport_profile:string, document_type:string, process_ids:array, certificate_subject:string, certificate_valid_from:string, certificate_valid_to:string, certificate_expired:bool, service_activation_date:string, service_expiration_date:string, looked_up_at:string, cached:bool}|\WP_Error
	 */
	public function lookup_receiver_endpoint( string $participant_id, string $document_type = self::DOCTYPE_INVOICE ) {
		$cache_key = 'wm_peppol_smp_' . md5( self::zone() . '|' . strtolower( $participant_id ) . '|' . $document_type );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$cached['cached'] = true;
			return $cached;
		}

		$smp_url = $this->resolve_smp_url( $participant_id );
		if ( $smp_url instanceof \WP_Error ) {
			return $smp_url;
		}

		$url = $this->service_metadata_url( $smp_url, $participant_id, $document_type );
		if ( $url instanceof \WP_Error ) {
			return $url;
		}

		$response = wp_remote_get(
			$url,
			[
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => [ 'Accept' => 'application/xml' ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( self::ERROR_HTTP, $response->get_error_message(), [ 'status' => 502, 'smp_url' => $smp_url ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			return new \WP_Error(
				self::ERROR_NOT_OFFERED,
				sprintf(
					/* translators: 1: participant id, 2: document type */
					__( 'Der SMP kennt den Teilnehmer %1$s, aber nicht für den Dokumenttyp %2$s: dieser Empfänger nimmt dieses Dokument nicht entgegen.', 'wm-peppol-as4-gateway' ),
					$participant_id,
					$document_type
				),
				[ 'status' => 404, 'smp_url' => $smp_url ]
			);
		}

		if ( 200 !== $code ) {
			return new \WP_Error(
				self::ERROR_HTTP,
				sprintf(
					/* translators: 1: HTTP status, 2: SMP URL */
					__( 'Der SMP antwortete mit HTTP %1$d auf %2$s.', 'wm-peppol-as4-gateway' ),
					$code,
					$smp_url
				),
				[ 'status' => 502, 'http_code' => $code, 'smp_url' => $smp_url ]
			);
		}

		$endpoint = self::parse_service_metadata( (string) wp_remote_retrieve_body( $response ) );
		if ( $endpoint instanceof \WP_Error ) {
			return $endpoint;
		}

		$dns_name = $this->dns_name( $participant_id );

		$result = array_merge(
			$endpoint,
			[
				'participant_id' => $participant_id,
				'dns_name'       => $dns_name instanceof \WP_Error ? '' : $dns_name,
				'smp_url'        => $smp_url,
				'document_type'  => $document_type,
				'looked_up_at'   => gmdate( 'c' ),
				'cached'         => false,
			]
		);

		set_transient( $cache_key, $result, self::CACHE_TTL );

		return $result;
	}

	/**
	 * The AS4 endpoint of a SignedServiceMetadata / ServiceMetadata document.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function parse_service_metadata( string $xml ) {
		if ( '' === trim( $xml ) ) {
			return new \WP_Error( self::ERROR_PARSE, __( 'Der SMP lieferte einen leeren Körper.', 'wm-peppol-as4-gateway' ), [ 'status' => 502 ] );
		}

		$previous = libxml_use_internal_errors( true );
		$document = new \DOMDocument();
		$loaded   = $document->loadXML( $xml );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return new \WP_Error( self::ERROR_PARSE, __( 'Die Antwort des SMP ist kein wohlgeformtes XML.', 'wm-peppol-as4-gateway' ), [ 'status' => 502 ] );
		}

		$endpoints = $document->getElementsByTagNameNS( '*', 'Endpoint' );

		foreach ( $endpoints as $endpoint ) {
			$profile = (string) $endpoint->getAttribute( 'transportProfile' );
			if ( self::TRANSPORT_PROFILE_AS4 !== $profile ) {
				continue;
			}

			$address     = self::first_text( $endpoint, 'Address' );
			$certificate = preg_replace( '/\s+/', '', self::first_text( $endpoint, 'Certificate' ) );

			if ( '' === $address ) {
				continue;
			}

			$parsed = self::describe_certificate( (string) $certificate );

			// The endpoint sits in <Process><ServiceEndpointList><Endpoint>, and the process identifier is
			// the sibling of that list. Measured on a live SMP; an earlier guess looked for a
			// "ProcessType" element, which the document does not have, and reported no process at all.
			$processes = [];
			$parent    = $endpoint->parentNode;
			while ( $parent instanceof \DOMElement && 'Process' !== $parent->localName ) {
				$parent = $parent->parentNode;
			}
			if ( $parent instanceof \DOMElement ) {
				foreach ( $parent->getElementsByTagNameNS( '*', 'ProcessIdentifier' ) as $process ) {
					$processes[] = trim( (string) $process->textContent );
				}
			}

			return [
				'access_point_url'        => $address,
				'transport_profile'       => $profile,
				'process_ids'             => $processes,
				'certificate_subject'     => $parsed['subject'],
				'certificate_valid_from'  => $parsed['valid_from'],
				'certificate_valid_to'    => $parsed['valid_to'],
				'certificate_expired'     => $parsed['expired'],
				'service_activation_date' => self::first_text( $endpoint, 'ServiceActivationDate' ),
				'service_expiration_date' => self::first_text( $endpoint, 'ServiceExpirationDate' ),
			];
		}

		return new \WP_Error(
			self::ERROR_NO_ENDPOINT,
			sprintf(
				/* translators: %s: the AS4 transport profile that was looked for */
				__( 'Die SMP-Antwort enthält keinen Endpunkt mit dem Transportprofil %s.', 'wm-peppol-as4-gateway' ),
				self::TRANSPORT_PROFILE_AS4
			),
			[ 'status' => 502 ]
		);
	}

	/**
	 * Text of the first child with this local name, whatever namespace it carries.
	 */
	private static function first_text( \DOMElement $element, string $local_name ): string {
		$nodes = $element->getElementsByTagNameNS( '*', $local_name );

		return $nodes->length > 0 ? trim( (string) $nodes->item( 0 )->textContent ) : '';
	}

	/**
	 * Subject and validity of the access point certificate, read from the certificate.
	 *
	 * Never a "valid" flag the plugin did not compute: `expired` compares the dates in the certificate
	 * with now. Whether the certificate chains to the Peppol PKI is not checked here, and is not claimed.
	 *
	 * @return array{subject:string, valid_from:string, valid_to:string, expired:bool}
	 */
	public static function describe_certificate( string $base64_der ): array {
		$empty = [ 'subject' => '', 'valid_from' => '', 'valid_to' => '', 'expired' => false ];

		if ( '' === $base64_der || ! function_exists( 'openssl_x509_parse' ) ) {
			return $empty;
		}

		$pem    = "-----BEGIN CERTIFICATE-----\n" . chunk_split( $base64_der, 64, "\n" ) . "-----END CERTIFICATE-----\n";
		$parsed = @openssl_x509_parse( $pem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a certificate that does not parse is reported as unknown, not as an error.

		if ( ! is_array( $parsed ) ) {
			return $empty;
		}

		$from = isset( $parsed['validFrom_time_t'] ) ? (int) $parsed['validFrom_time_t'] : 0;
		$to   = isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : 0;

		return [
			'subject'    => isset( $parsed['subject']['CN'] ) ? (string) $parsed['subject']['CN'] : '',
			'valid_from' => $from > 0 ? gmdate( 'c', $from ) : '',
			'valid_to'   => $to > 0 ? gmdate( 'c', $to ) : '',
			'expired'    => self::is_expired( $to ),
		];
	}

	/**
	 * Whether a notAfter timestamp lies in the past. Its own method so it can be checked without a
	 * certificate: generating one inside the suite depends on the OpenSSL build of the machine.
	 *
	 * An unknown date (0) is not "expired" and not "valid" — it is unknown, and the caller sees the
	 * empty date beside it.
	 */
	public static function is_expired( int $valid_to ): bool {
		return $valid_to > 0 && $valid_to < time();
	}
}
