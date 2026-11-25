<?php
/**
 * Builds a genuinely signed AS4 receipt, so the verifier is measured against real cryptography.
 *
 * A hand-written fixture with a made-up SignatureValue would prove only that the parser reads XML. This
 * generates an RSA key and a self-signed certificate, assembles the SOAP envelope with the
 * eb:Receipt and its ebbpsig:NonRepudiationInformation, canonicalises ds:SignedInfo exclusively and
 * signs it with the private key. tests/groups/13-receipt-evidence.php then tampers with the result
 * and requires the verifier to refuse it.
 *
 * The certificate is valid for twenty years on purpose: a fixture that expires turns a gate red on a
 * date nobody chose. Expiry itself is reported from the certificate and measured separately.
 *
 * Usage:
 *   php tools/make-as4-receipt.php --out=tests/fixtures/as4 [--payload=<file>]
 *
 * Exit: 0 when the fixtures were written and verify against themselves; 1 otherwise.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) && ! defined( 'WP_CLI' ) && 'cli' !== PHP_SAPI ) {
	exit;
}

const NS_SOAP    = 'http://www.w3.org/2003/05/soap-envelope';
const NS_EBMS    = 'http://docs.oasis-open.org/ebxml-msg/ebms/v3.0/ns/core/200704/';
const NS_EBBPSIG = 'http://docs.oasis-open.org/ebxml-bp/ebbp-signals-2.0';
const NS_DSIG    = 'http://www.w3.org/2000/09/xmldsig#';

const ALG_C14N   = 'http://www.w3.org/2001/10/xml-exc-c14n#';
const ALG_SHA256 = 'http://www.w3.org/2001/04/xmlenc#sha256';
const ALG_RSA    = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';
const ALG_ENVELOPED = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

/**
 * The document canonicalised with every ds:Signature removed, which is what the enveloped-signature
 * transform digests.
 */
function self_without_signature( DOMDocument $document ): string {
	$copy = new DOMDocument();
	$copy->loadXML( (string) $document->saveXML() );
	$xpath = new DOMXPath( $copy );
	$xpath->registerNamespace( 'ds', NS_DSIG );
	foreach ( iterator_to_array( $xpath->query( '//ds:Signature' ) ) as $signature ) {
		$signature->parentNode?->removeChild( $signature );
	}

	return (string) $copy->documentElement?->C14N( true, false );
}

/**
 * A receipt signed with a fresh key, and the payload its NRR digests attest to.
 *
 * @return array{xml:string, payload:string, certificate:string}
 */
function build_receipt( string $payload, string $ref_to_message_id, bool $matching_digest = true, bool $enveloped = false ): array {
	$config = [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256' ];
	$key    = openssl_pkey_new( $config );
	if ( false === $key ) {
		fwrite( STDERR, "openssl_pkey_new failed\n" );
		exit( 1 );
	}

	$csr = openssl_csr_new(
		[ 'countryName' => 'DE', 'organizationName' => 'WM Suite Test AP', 'commonName' => 'AS4 Test Access Point' ],
		$key,
		$config
	);
	if ( false === $csr ) {
		fwrite( STDERR, "openssl_csr_new failed\n" );
		exit( 1 );
	}

	// Twenty years: a fixture whose certificate expires fails the gate on a date nobody chose.
	$certificate = openssl_csr_sign( $csr, null, $key, 7300, $config );
	if ( false === $certificate ) {
		fwrite( STDERR, "openssl_csr_sign failed\n" );
		exit( 1 );
	}
	openssl_x509_export( $certificate, $pem );

	$document = new DOMDocument( '1.0', 'UTF-8' );
	$document->formatOutput = false;

	$envelope = $document->createElementNS( NS_SOAP, 'S12:Envelope' );
	$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:eb', NS_EBMS );
	$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ebbp', NS_EBBPSIG );
	$envelope->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:ds', NS_DSIG );
	$document->appendChild( $envelope );

	$header = $document->createElementNS( NS_SOAP, 'S12:Header' );
	$envelope->appendChild( $header );

	$messaging = $document->createElementNS( NS_EBMS, 'eb:Messaging' );
	$messaging->setAttribute( 'Id', 'ebms-header' );
	$header->appendChild( $messaging );

	$signal = $document->createElementNS( NS_EBMS, 'eb:SignalMessage' );
	$messaging->appendChild( $signal );

	$info = $document->createElementNS( NS_EBMS, 'eb:MessageInfo' );
	$signal->appendChild( $info );
	$info->appendChild( $document->createElementNS( NS_EBMS, 'eb:Timestamp', gmdate( 'Y-m-d\TH:i:s\Z' ) ) );
	$info->appendChild( $document->createElementNS( NS_EBMS, 'eb:MessageId', 'receipt-' . bin2hex( random_bytes( 8 ) ) . '@example.test' ) );
	$info->appendChild( $document->createElementNS( NS_EBMS, 'eb:RefToMessageId', $ref_to_message_id ) );

	$receipt = $document->createElementNS( NS_EBMS, 'eb:Receipt' );
	$signal->appendChild( $receipt );

	$nrr = $document->createElementNS( NS_EBBPSIG, 'ebbp:NonRepudiationInformation' );
	$receipt->appendChild( $nrr );

	$part = $document->createElementNS( NS_EBBPSIG, 'ebbp:MessagePartNRInformation' );
	$nrr->appendChild( $part );

	$digest = base64_encode( hash( 'sha256', $matching_digest ? $payload : $payload . ' another document', true ) );

	$reference = $document->createElementNS( NS_DSIG, 'ds:Reference' );
	$reference->setAttribute( 'URI', 'cid:payload@example.test' );
	$method = $document->createElementNS( NS_DSIG, 'ds:DigestMethod' );
	$method->setAttribute( 'Algorithm', ALG_SHA256 );
	$reference->appendChild( $method );
	$reference->appendChild( $document->createElementNS( NS_DSIG, 'ds:DigestValue', $digest ) );
	$part->appendChild( $reference );

	$body = $document->createElementNS( NS_SOAP, 'S12:Body' );
	$envelope->appendChild( $body );

	// The signature covers the eb:Messaging header, which is where the receipt lives.
	$signature = $document->createElementNS( NS_DSIG, 'ds:Signature' );
	$header->appendChild( $signature );

	$signed_info = $document->createElementNS( NS_DSIG, 'ds:SignedInfo' );
	$signature->appendChild( $signed_info );

	$c14n_method = $document->createElementNS( NS_DSIG, 'ds:CanonicalizationMethod' );
	$c14n_method->setAttribute( 'Algorithm', ALG_C14N );
	$signed_info->appendChild( $c14n_method );

	$signature_method = $document->createElementNS( NS_DSIG, 'ds:SignatureMethod' );
	$signature_method->setAttribute( 'Algorithm', ALG_RSA );
	$signed_info->appendChild( $signature_method );

	$signed_reference = $document->createElementNS( NS_DSIG, 'ds:Reference' );
	// An enveloped signature covers the whole document and removes itself from what is digested; a
	// detached one covers the eb:Messaging header by its Id. Access points do both, so both are built.
	$signed_reference->setAttribute( 'URI', $enveloped ? '' : '#ebms-header' );
	$signed_info->appendChild( $signed_reference );

	$transforms = $document->createElementNS( NS_DSIG, 'ds:Transforms' );
	if ( $enveloped ) {
		$envelope_transform = $document->createElementNS( NS_DSIG, 'ds:Transform' );
		$envelope_transform->setAttribute( 'Algorithm', ALG_ENVELOPED );
		$transforms->appendChild( $envelope_transform );
	}
	$transform = $document->createElementNS( NS_DSIG, 'ds:Transform' );
	$transform->setAttribute( 'Algorithm', ALG_C14N );
	$transforms->appendChild( $transform );
	$signed_reference->appendChild( $transforms );

	$reference_digest_method = $document->createElementNS( NS_DSIG, 'ds:DigestMethod' );
	$reference_digest_method->setAttribute( 'Algorithm', ALG_SHA256 );
	$signed_reference->appendChild( $reference_digest_method );

	// The digest is taken with the signature element present but empty of its value; the enveloped
	// transform removes the whole ds:Signature, so digesting a copy without it is the same bytes.
	$digested = $enveloped ? self_without_signature( $document ) : (string) $messaging->C14N( true, false );

	$signed_reference->appendChild(
		$document->createElementNS( NS_DSIG, 'ds:DigestValue', base64_encode( hash( 'sha256', $digested, true ) ) )
	);

	openssl_sign( (string) $signed_info->C14N( true, false ), $raw, $key, OPENSSL_ALGO_SHA256 );
	$signature->appendChild( $document->createElementNS( NS_DSIG, 'ds:SignatureValue', base64_encode( (string) $raw ) ) );

	$key_info = $document->createElementNS( NS_DSIG, 'ds:KeyInfo' );
	$x509     = $document->createElementNS( NS_DSIG, 'ds:X509Data' );
	$x509->appendChild(
		$document->createElementNS( NS_DSIG, 'ds:X509Certificate', trim( str_replace( [ '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r" ], '', (string) $pem ) ) )
	);
	$key_info->appendChild( $x509 );
	$signature->appendChild( $key_info );

	return [ 'xml' => (string) $document->saveXML(), 'payload' => $payload, 'certificate' => (string) $pem ];
}

$options = getopt( '', [ 'out:', 'payload:', 'self-check' ] );
$out     = $options['out'] ?? dirname( __DIR__ ) . '/tests/fixtures/as4';

$payload = isset( $options['payload'] ) && is_readable( (string) $options['payload'] )
	? (string) file_get_contents( (string) $options['payload'] )
	: '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"><ID xmlns="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">INV-RECEIPT-FIXTURE-1</ID></Invoice>';

if ( ! is_dir( $out ) && ! mkdir( $out, 0755, true ) ) {
	fwrite( STDERR, "cannot create {$out}\n" );
	exit( 1 );
}

$reference_message_id = 'as4-message-fixture-1@example.test';

$matching  = build_receipt( $payload, $reference_message_id, true );
$other     = build_receipt( $payload, $reference_message_id, false );
$enveloped = build_receipt( $payload, $reference_message_id, true, true );

file_put_contents( $out . '/receipt-signed.xml', $matching['xml'] );
file_put_contents( $out . '/receipt-other-document.xml', $other['xml'] );
file_put_contents( $out . '/receipt-enveloped.xml', $enveloped['xml'] );
file_put_contents( $out . '/payload.xml', $payload );

printf(
	"written to %s\n  receipt-signed.xml          %d bytes, NRR digest of payload.xml\n  receipt-other-document.xml  %d bytes, signed the same way, NRR digest of a DIFFERENT document\n  payload.xml                 %d bytes\n",
	$out,
	strlen( $matching['xml'] ),
	strlen( $other['xml'] ),
	strlen( $payload )
);
printf( "  receipt-enveloped.xml       %d bytes, signed over the whole envelope with the enveloped-signature transform\n", strlen( $enveloped['xml'] ) );

if ( ! isset( $options['self-check'] ) ) {
	exit( 0 );
}

// Generate, then verify with the plugin's own code. This is what CI runs: it proves the generator
// and the verifier agree on FRESH cryptography, so an OpenSSL or libxml upgrade that changes
// canonicalisation is caught instead of passing on the committed bytes.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}
if ( ! function_exists( '__' ) ) {
	/** The verifier translates its messages; outside WordPress they pass through. */
	function __( string $text, string $domain = '' ): string { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- the WordPress function, stubbed for a CLI tool.
		unset( $domain );

		return $text;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	/** Minimal stand-in for the CLI. */
	class WP_Error { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- the WordPress class, stubbed for a CLI tool.
		public function __construct( public string $code = '', public string $message = '', public array $data = [] ) {}
		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/AS4/ReceiptEvidence.php';

$failed = 0;
$checks = [
	'receipt-signed.xml'         => [ 'signature' => true, 'payload' => 'yes' ],
	'receipt-enveloped.xml'      => [ 'signature' => true, 'payload' => 'yes' ],
	'receipt-other-document.xml' => [ 'signature' => true, 'payload' => 'no' ],
];

foreach ( $checks as $file => $expected ) {
	$result = \WMCyber\Peppol\AS4\ReceiptEvidence::verify( (string) file_get_contents( $out . '/' . $file ), $payload );

	if ( ! is_array( $result ) ) {
		printf( "FAIL %-28s %s\n", $file, $result->get_error_message() );
		$failed = 1;
		continue;
	}

	$ok = $expected['signature'] === $result['signature_verified'] && $expected['payload'] === $result['payload_match'];
	printf(
		"%s %-28s signature=%s references=%d/%d payload=%s\n",
		$ok ? 'PASS' : 'FAIL',
		$file,
		$result['signature_verified'] ? 'valid' : 'invalid (' . $result['signature_error'] . ')',
		$result['references_verified'],
		$result['references_total'],
		$result['payload_match']
	);

	if ( ! $ok ) {
		$failed = 1;
	}
}

// And a tampered copy must be refused, or "verified" means nothing.
$tampered = (string) preg_replace( '/<eb:Timestamp>[^<]+/', '<eb:Timestamp>2000-01-01T00:00:00Z', (string) file_get_contents( $out . '/receipt-signed.xml' ), 1 );
$refused  = \WMCyber\Peppol\AS4\ReceiptEvidence::verify( $tampered, $payload );
$caught   = is_array( $refused ) && false === $refused['signature_verified'];
printf( "%s %-28s a receipt edited after signing is refused\n", $caught ? 'PASS' : 'FAIL', 'tampered' );
if ( ! $caught ) {
	$failed = 1;
}

exit( $failed );
