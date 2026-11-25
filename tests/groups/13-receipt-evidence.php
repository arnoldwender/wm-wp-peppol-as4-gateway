<?php
/**
 * Signed AS4 receipts as delivery evidence, and the chained register that keeps them.
 *
 * The fixtures in tests/fixtures/as4 are signed with a real RSA key by tools/make-as4-receipt.php, so
 * these cases exercise the cryptography rather than a parser. Every tampering below is applied to
 * that real signature and must be refused.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\AS4\DeliveryRegister;
use WMCyber\Peppol\AS4\ReceiptEvidence;

pp_group( 'RECEIPT 1 · a genuine signed receipt verifies, and says which document it is about', static function (): void {
	$fixtures = pp_receipt_fixtures();
	$result   = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );

	pp_assert( is_array( $result ), 'a signed AS4 receipt parses' );
	pp_assert( true === $result['signature_verified'] && '' === $result['signature_error'], 'its XML signature verifies against the certificate it carries' );
	pp_assert( 1 === $result['references_verified'] && 1 === $result['references_total'], 'and every ds:Reference of SignedInfo matches the content it points at, so the receipt was not edited after signing' );
	pp_assert( 'yes' === $result['payload_match'], 'the NonRepudiationInformation digests match the document this site sent' );
	pp_assert( 'as4-message-fixture-1@example.test' === $result['ref_to_message_id'] && '' !== $result['message_id'] && '' !== $result['timestamp'], 'the receipt names the message it answers, its own id and its timestamp' );
	pp_assert( 'AS4 Test Access Point' === ( $result['signer']['subject'] ?? '' ) && 64 === strlen( (string) ( $result['signer']['fingerprint'] ?? '' ) ) && '0' === ( $result['signer']['expired'] ?? '' ), 'the signer is reported from the certificate, with its SHA-256 fingerprint, and it is not expired' );
	pp_assert( hash( 'sha256', $fixtures['receipt'] ) === $result['receipt_sha256'], 'and the receipt is fingerprinted as received, before anything is stored' );
} );

pp_group( 'RECEIPT 2 · a valid signature over the wrong document is not evidence of this delivery', static function (): void {
	$fixtures = pp_receipt_fixtures();
	$result   = ReceiptEvidence::verify( $fixtures['other'], $fixtures['payload'] );

	// This is the case that matters and the one a single verdict would hide: the receipt is
	// genuinely signed by the access point and genuinely untampered, and it acknowledges a DIFFERENT
	// document. Reporting one "valid" would turn it into proof of a delivery that did not happen.
	pp_assert( true === $result['signature_verified'], 'the receipt is genuinely signed and genuinely untampered' );
	pp_assert( 'no' === $result['payload_match'], 'and it still is not evidence for this invoice, because its digests are of another document' );

	pp_assert( 'unknown' === ReceiptEvidence::payload_match( $result['references'], null ), 'with no sent document to compare, the answer is unknown — not yes and not no' );
	pp_assert( 'unknown' === ReceiptEvidence::payload_match( [], $fixtures['payload'] ), 'and a receipt carrying no digests at all is unknown too' );
	pp_assert(
		'unknown' === ReceiptEvidence::payload_match( [ [ 'uri' => 'cid:x', 'algorithm' => 'http://example.invalid/whirlpool', 'digest' => 'AAAA' ] ], $fixtures['payload'] ),
		'a digest algorithm this build cannot compute is unknown, never a mismatch'
	);
} );

pp_group( 'RECEIPT 3 · every tampering of a real signature is refused', static function (): void {
	$fixtures = pp_receipt_fixtures();

	$cases = [
		'the NonRepudiationInformation digest'  => (string) preg_replace( '/(<ds:DigestValue>)[A-Za-z0-9+\/=]{10}/', '$1AAAAAAAAAA', $fixtures['receipt'], 1 ),
		'the message this receipt answers'      => str_replace( 'as4-message-fixture-1', 'as4-message-another', $fixtures['receipt'] ),
		'the timestamp'                         => (string) preg_replace( '/<eb:Timestamp>[^<]+/', '<eb:Timestamp>2000-01-01T00:00:00Z', $fixtures['receipt'], 1 ),
	];

	foreach ( $cases as $what => $tampered ) {
		$result = ReceiptEvidence::verify( $tampered, $fixtures['payload'] );
		pp_assert( is_array( $result ) && false === $result['signature_verified'] && 0 === $result['references_verified'], "changing {$what} breaks the reference digest and the receipt is refused" );
	}

	// The signature value itself: the reference digests still match, and openssl refuses the signature.
	$forged = (string) preg_replace( '/(<ds:SignatureValue>)[A-Za-z0-9+\/=]{10}/', '$1AAAAAAAAAA', $fixtures['receipt'], 1 );
	$result = ReceiptEvidence::verify( $forged, $fixtures['payload'] );
	pp_assert( false === $result['signature_verified'] && str_contains( $result['signature_error'], 'passt nicht' ), 'a forged SignatureValue is refused by openssl, not by a digest comparison' );

	$unsigned = (string) preg_replace( '#<ds:Signature>.*</ds:Signature>#s', '', $fixtures['receipt'] );
	$result   = ReceiptEvidence::verify( $unsigned, $fixtures['payload'] );
	pp_assert( false === $result['signature_verified'] && str_contains( $result['signature_error'], 'XML-Signatur' ), 'a receipt with the signature removed is refused: a signed AS4 message must be acknowledged with a signed receipt' );

	$swapped = str_replace( 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256', 'http://example.invalid/unknown-algorithm', $fixtures['receipt'] );
	$result  = ReceiptEvidence::verify( $swapped, $fixtures['payload'] );
	pp_assert( false === $result['signature_verified'] && str_contains( $result['signature_error'], 'Signaturverfahren' ), 'a signature algorithm this build does not check is refused, never assumed valid' );
} );

pp_group( 'RECEIPT 4 · an enveloped signature over the whole envelope', static function (): void {
	// Access points sign either the eb:Messaging header by its Id or the whole envelope with the
	// enveloped-signature transform, which removes the signature itself from what is digested.
	// Without this fixture that branch of the verifier would be unreachable and its mutation would
	// survive; with it, both shapes are measured.
	$fixtures = pp_receipt_fixtures();
	$result   = ReceiptEvidence::verify( $fixtures['enveloped'], $fixtures['payload'] );

	pp_assert( true === $result['signature_verified'] && 1 === $result['references_verified'] && 'yes' === $result['payload_match'], 'a receipt signed over the whole envelope with the enveloped-signature transform verifies, and the reference with an empty URI resolves to the document element' );

	$tampered = (string) preg_replace( '/<eb:Timestamp>[^<]+/', '<eb:Timestamp>2000-01-01T00:00:00Z', $fixtures['enveloped'], 1 );
	pp_assert( false === ReceiptEvidence::verify( $tampered, $fixtures['payload'] )['signature_verified'], 'and editing it afterwards still breaks the digest' );
} );

pp_group( 'RECEIPT 5 · what is not a receipt at all', static function (): void {
	$not_xml = ReceiptEvidence::verify( '<S12:Envelope><unclosed>' );
	pp_assert( $not_xml instanceof WP_Error && ReceiptEvidence::ERROR_XML === $not_xml->get_error_code(), 'a body that is not well-formed XML is refused with its own code' );

	$no_signal = ReceiptEvidence::verify( '<?xml version="1.0"?><hello/>' );
	pp_assert( $no_signal instanceof WP_Error && ReceiptEvidence::ERROR_NO_RECEIPT === $no_signal->get_error_code(), 'well-formed XML without an eb:Receipt is refused with a different code' );

	$too_large = ReceiptEvidence::verify( str_repeat( 'x', ReceiptEvidence::MAX_BYTES + 1 ) );
	pp_assert( $too_large instanceof WP_Error && ReceiptEvidence::ERROR_TOO_LARGE === $too_large->get_error_code(), 'a body larger than the limit is refused before it is parsed: a receipt is a signal, not a payload' );

	// XXE: the parser must not resolve an external entity, whatever the receipt asks for.
	$probe = tempnam( sys_get_temp_dir(), 'pp-xxe' );
	file_put_contents( (string) $probe, 'SECRET-CONTENT-THAT-MUST-NOT-APPEAR' );
	$xxe = '<?xml version="1.0"?><!DOCTYPE r [<!ENTITY x SYSTEM "file://' . $probe . '">]>'
		. '<S12:Envelope xmlns:S12="http://www.w3.org/2003/05/soap-envelope" xmlns:eb="' . ReceiptEvidence::NS_EBMS . '">'
		. '<eb:SignalMessage><eb:MessageInfo><eb:MessageId>&x;</eb:MessageId></eb:MessageInfo><eb:Receipt/></eb:SignalMessage></S12:Envelope>';
	$result = ReceiptEvidence::verify( $xxe );
	$seen   = is_array( $result ) ? $result['message_id'] : '';
	unlink( (string) $probe );
	pp_assert( ! str_contains( $seen, 'SECRET-CONTENT' ), 'an external entity in a receipt is not resolved: the file it names never reaches the result' );
} );

pp_group( 'RECEIPT 6 · the register is append-only and any later edit is visible', static function (): void {
	pp_reset();
	global $wpdb;

	$first  = DeliveryRegister::record( 'INV-1', DeliveryRegister::ACTION_RECEIPT, 'msg-1', [ 'payload_match' => 'yes' ] );
	$second = DeliveryRegister::record( 'INV-2', DeliveryRegister::ACTION_RECEIPT, 'msg-2', [ 'payload_match' => 'yes' ] );
	$third  = DeliveryRegister::record( 'INV-3', DeliveryRegister::ACTION_REJECTED, 'msg-3', [ 'payload_match' => 'no' ] );

	pp_assert( $first && $second && $third, 'three entries are written' );

	$rows = DeliveryRegister::recent( 10 );
	pp_assert( 3 === count( $rows ) && 'INV-3' === $rows[0]['invoice_number'], 'and come back newest first, with the detail decoded' );
	pp_assert( DeliveryRegister::GENESIS !== DeliveryRegister::tip() && DeliveryRegister::tip() === $rows[0]['current_hash'], 'the tip is the fingerprint of the newest row' );

	$chain = DeliveryRegister::verify_chain();
	pp_assert( true === $chain['valid'] && 3 === $chain['checked'], 'the chain verifies over all three' );

	// Edit a row the way someone would who wanted a rejected receipt to read as accepted.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( DeliveryRegister::table(), [ 'action' => DeliveryRegister::ACTION_RECEIPT ], [ 'id' => (int) $third ] );
	$chain = DeliveryRegister::verify_chain();
	pp_assert( false === $chain['valid'] && (int) $third === $chain['broken_at'] && 2 === $chain['checked'], 'changing a row after the fact breaks its fingerprint and the row is named' );

	pp_reset();
	DeliveryRegister::record( 'INV-A', DeliveryRegister::ACTION_RECEIPT, 'msg-a', [] );
	$middle = DeliveryRegister::record( 'INV-B', DeliveryRegister::ACTION_RECEIPT, 'msg-b', [] );
	DeliveryRegister::record( 'INV-C', DeliveryRegister::ACTION_RECEIPT, 'msg-c', [] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE id = %d', DeliveryRegister::table(), (int) $middle ) );
	$chain = DeliveryRegister::verify_chain();
	pp_assert( false === $chain['valid'] && str_contains( $chain['reason'], 'entfernt' ), 'removing a row from the middle breaks the link of the one after it' );

	// Editing only the detail is the interesting attack: the row still names the same invoice and the
	// same message, and its verdict now says the receipt was accepted.
	pp_reset();
	$only = DeliveryRegister::record( 'INV-D', DeliveryRegister::ACTION_RECEIPT, 'msg-d', [ 'payload_match' => 'no', 'signature_verified' => false ] );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update( DeliveryRegister::table(), [ 'detail_json' => (string) wp_json_encode( [ 'payload_match' => 'yes', 'signature_verified' => true ] ) ], [ 'id' => (int) $only ] );
	$chain = DeliveryRegister::verify_chain();
	pp_assert( false === $chain['valid'] && (int) $only === $chain['broken_at'], 'rewriting only the measurement of a row breaks its fingerprint: the detail is folded into the chain, not merely stored beside it' );

	pp_reset();
	pp_assert( DeliveryRegister::GENESIS === DeliveryRegister::tip() && true === DeliveryRegister::verify_chain()['valid'], 'an empty register is valid and starts from the genesis hash' );
} );

pp_group( 'RECEIPT 7 · the tables are brought forward when the plugin is updated in place', static function (): void {
	pp_reset();
	// A plugin updated through the dashboard or WP-CLI is never re-activated. Without this the table
	// would keep the layout it had, message_id and detail_json would not exist, and every register
	// insert would fail silently — which reads exactly like "no receipts arrived".
	delete_option( WMCyber\Peppol\Database\Schema::VERSION_OPTION );
	WMCyber\Peppol\Database\Schema::maybe_upgrade();
	pp_assert( WMCyber\Peppol\Database\Schema::VERSION === get_option( WMCyber\Peppol\Database\Schema::VERSION_OPTION ), 'a version that does not match runs the schema and records the new one' );

	$entry = pp_code( 'wm-peppol-as4-gateway.php' );
	pp_assert( str_contains( $entry, 'Schema::maybe_upgrade()' ) && strpos( $entry, 'Schema::maybe_upgrade()' ) < strpos( $entry, 'Plugin::instance()->boot()' ), 'and the entry file runs it on plugins_loaded, before the plugin boots' );
} );

pp_group( 'RECEIPT 8 · the register never keeps the receipt body or an address', static function (): void {
	pp_reset();
	$fixtures = pp_receipt_fixtures();
	$result   = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );

	DeliveryRegister::record(
		'INV-9',
		DeliveryRegister::ACTION_RECEIPT,
		'msg-9',
		[
			'receipt_sha256'     => $result['receipt_sha256'],
			'signature_verified' => $result['signature_verified'],
			'payload_match'      => $result['payload_match'],
			'signer'             => $result['signer'],
		]
	);

	$stored = wp_json_encode( DeliveryRegister::recent( 1 ) );
	pp_assert( ! str_contains( (string) $stored, '<ds:SignatureValue>' ) && ! str_contains( (string) $stored, 'BEGIN CERTIFICATE' ), 'the stored detail holds the measurements, not the receipt body' );
	pp_assert( str_contains( (string) $stored, $result['receipt_sha256'] ), 'the receipt is identified by its SHA-256, which is what lets an auditor match a produced receipt to the row' );
} );

pp_group( 'RECEIPT 9 · certificate validity window, SHA-256 pinning and local PKI CA chain verification', static function (): void {
	pp_reset();
	$fixtures = pp_receipt_fixtures();

	// 1. Temporal validity: expired and not-yet-valid certificates must be refused even when RSA signature holds.
	$expired = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'], (int) strtotime( '2050-01-01T00:00:00Z' ) );
	pp_assert( is_array( $expired ) && false === $expired['signature_verified'] && '1' === ( $expired['signer']['expired'] ?? '' ) && str_contains( $expired['signature_error'], 'abgelaufen' ), 'an expired signing certificate is refused' );

	$not_yet = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'], (int) strtotime( '2020-01-01T00:00:00Z' ) );
	pp_assert( is_array( $not_yet ) && false === $not_yet['signature_verified'] && '1' === ( $not_yet['signer']['not_yet_valid'] ?? '' ) && str_contains( $not_yet['signature_error'], 'noch nicht gültig' ), 'a signing certificate whose notBefore lies in the future is refused' );

	// 2. Default unconfigured mode vs require_trust_anchor enforcement.
	$unconfigured = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );
	pp_assert( true === $unconfigured['trust_verified'] && 'unconfigured' === $unconfigured['trust_status'], 'with no pin or CA bundle configured, trust_status reports unconfigured and stays backward-compatible' );

	update_option( \WMCyber\Peppol\Core\Settings::REQUIRE_TRUST_ANCHOR_OPTION, '1' );
	$enforced = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );
	pp_assert( false === $enforced['trust_verified'] && 'unconfigured_rejected' === $enforced['trust_status'], 'when require_trust_anchor is enabled, unanchored self-signed receipts are refused' );
	delete_option( \WMCyber\Peppol\Core\Settings::REQUIRE_TRUST_ANCHOR_OPTION );

	// 3. SHA-256 fingerprint pinning: matching pin passes as pinned; mismatched pin refuses as untrusted.
	$fp = (string) ( $unconfigured['signer']['fingerprint'] ?? '' );
	update_option( \WMCyber\Peppol\Core\Settings::TRUSTED_SIGNER_SHA256_OPTION, strtoupper( implode( ':', str_split( $fp, 2 ) ) ) );
	$pinned = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );
	pp_assert( true === $pinned['trust_verified'] && 'pinned' === $pinned['trust_status'], 'a receipt matching a configured SHA-256 pin (even formatted with colons/uppercase) verifies as pinned' );

	update_option( \WMCyber\Peppol\Core\Settings::TRUSTED_SIGNER_SHA256_OPTION, str_repeat( 'a', 64 ) );
	$wrong_pin = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );
	pp_assert( false === $wrong_pin['trust_verified'] && 'untrusted' === $wrong_pin['trust_status'] && '' !== $wrong_pin['trust_error'], 'a receipt signed by an unpinned key is marked untrusted when a pin is active' );
	delete_option( \WMCyber\Peppol\Core\Settings::TRUSTED_SIGNER_SHA256_OPTION );

	// 4. Local 3-tier PKI CA chain (Root CA -> Intermediate AP CA -> Leaf AP Signer) with 0 network calls.
	$cnf = wp_tempnam( 'wm-peppol-cnf' );
	file_put_contents(
		(string) $cnf,
		"[req]\ndistinguished_name = dn\n[dn]\n[v3_ca]\nbasicConstraints = critical,CA:TRUE\nkeyUsage = critical,keyCertSign,cRLSign\nsubjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid:always,issuer\n[v3_leaf]\nbasicConstraints = critical,CA:FALSE\nkeyUsage = critical,digitalSignature\nsubjectKeyIdentifier = hash\nauthorityKeyIdentifier = keyid,issuer\n"
	);
	$base_cfg = [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'config' => (string) $cnf ];
	$ca_cfg   = $base_cfg + [ 'x509_extensions' => 'v3_ca' ];
	$leaf_cfg = $base_cfg + [ 'x509_extensions' => 'v3_leaf' ];

	$root_key  = openssl_pkey_new( $ca_cfg );
	$root_cert = openssl_csr_sign( openssl_csr_new( [ 'commonName' => 'OpenPeppol Test Root CA' ], $root_key, $ca_cfg ), null, $root_key, 3650, $ca_cfg, 10 );
	openssl_x509_export( $root_cert, $root_pem );

	$int_key  = openssl_pkey_new( $ca_cfg );
	$int_cert = openssl_csr_sign( openssl_csr_new( [ 'commonName' => 'OpenPeppol Test AP CA G3' ], $int_key, $ca_cfg ), $root_cert, $root_key, 1825, $ca_cfg, 11 );
	openssl_x509_export( $int_cert, $int_pem );

	$leaf_key  = openssl_pkey_new( $leaf_cfg );
	$leaf_cert = openssl_csr_sign( openssl_csr_new( [ 'commonName' => 'Test Peppol C3 AP' ], $leaf_key, $leaf_cfg ), $int_cert, $int_key, 365, $leaf_cfg, 12 );
	openssl_x509_export( $leaf_cert, $leaf_pem );
	wp_delete_file( (string) $cnf );

	// Build a real AS4 receipt signed by $leaf_key and carrying [$leaf_pem, $int_pem] in ds:X509Data.
	$dom = new DOMDocument();
	$dom->loadXML( $fixtures['receipt'] );
	$xp = new DOMXPath( $dom );
	$xp->registerNamespace( 'ds', ReceiptEvidence::NS_DSIG );
	$signed_info = $xp->query( '//ds:SignedInfo' )->item( 0 );
	$sig_val     = $xp->query( '//ds:SignatureValue' )->item( 0 );
	$x509_data   = $xp->query( '//ds:X509Data' )->item( 0 );
	if ( $signed_info instanceof DOMElement && $sig_val instanceof DOMElement && $x509_data instanceof DOMElement ) {
		openssl_sign( (string) $signed_info->C14N( true, false ), $raw_sig, $leaf_key, OPENSSL_ALGO_SHA256 );
		$sig_val->textContent = base64_encode( (string) $raw_sig );
		while ( $x509_data->firstChild ) {
			$x509_data->removeChild( $x509_data->firstChild );
		}
		foreach ( [ $leaf_pem, $int_pem ] as $cert_block ) {
			$b64 = trim( str_replace( [ '-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r" ], '', (string) $cert_block ) );
			$x509_data->appendChild( $dom->createElementNS( ReceiptEvidence::NS_DSIG, 'ds:X509Certificate', $b64 ) );
		}
	}
	$chained_xml = (string) $dom->saveXML();

	update_option( \WMCyber\Peppol\Core\Settings::TRUST_BUNDLE_PEM_OPTION, (string) $root_pem );
	$chained_res = ReceiptEvidence::verify( $chained_xml, $fixtures['payload'] );
	pp_assert( true === $chained_res['signature_verified'] && true === $chained_res['trust_verified'] && 'pki_chained' === $chained_res['trust_status'], 'a receipt chained through an intermediate AP CA to the configured local Root CA verifies as pki_chained' );

	$rogue_res = ReceiptEvidence::verify( $fixtures['receipt'], $fixtures['payload'] );
	pp_assert( true === $rogue_res['signature_verified'] && false === $rogue_res['trust_verified'] && 'untrusted' === $rogue_res['trust_status'], 'a self-signed receipt not chaining to the configured local Root CA is rejected as untrusted' );
	delete_option( \WMCyber\Peppol\Core\Settings::TRUST_BUNDLE_PEM_OPTION );
} );
