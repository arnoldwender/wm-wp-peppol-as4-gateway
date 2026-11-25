<?php
/**
 * Leitweg-ID against the KoSIT specification (D34, U17) and the SMP client (D2).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\Discovery\SmpClient;

pp_group( 'LEITWEG-ID · KoSIT Format-Spezifikation 2.0.2 (28.07.2021), chapters 2.1 to 2.4', static function (): void {
	pp_assert( WM_Peppol_Leitweg_Validator::is_valid( '04011000-1234512345-06' ), 'the worked example of the specification, 04011000-1234512345-06, is valid' );
	pp_assert( '06' === WM_Peppol_Leitweg_Validator::check_digits( '04011000-1234512345' ), 'its check digits compute as 06' );
	pp_assert( ! WM_Peppol_Leitweg_Validator::is_valid( '04011000-1234512345-07' ), 'a wrong check digit is refused' );
	pp_assert( ! WM_Peppol_Leitweg_Validator::is_valid( '04011000-12345-67' ), 'the value the settings screen used to prefill is refused' );

	$digits = WM_Peppol_Leitweg_Validator::check_digits( '991-TestAmt1' );
	pp_assert( null !== $digits && WM_Peppol_Leitweg_Validator::is_valid( "991-testamt1-{$digits}" ) && WM_Peppol_Leitweg_Validator::is_valid( "991-TESTAMT1-{$digits}" ), 'letters count A=10 to Z=35 and are not case-sensitive' );
	$grob = WM_Peppol_Leitweg_Validator::check_digits( '99' );
	pp_assert( null !== $grob && WM_Peppol_Leitweg_Validator::is_valid( "99-{$grob}" ), 'a Grobadressierung without Feinadressierung verifies with its check digits' );
	$long = str_repeat( '9', 12 ) . '-' . str_repeat( 'Z', 30 );
	pp_assert( WM_Peppol_Leitweg_Validator::is_valid( $long . '-' . WM_Peppol_Leitweg_Validator::check_digits( $long ) ), 'the longest ID (12 + 30 characters) verifies without integer overflow' );

	// Check digits computed here, independently of the validator, so a structure error is not masked by a wrong digit.
	$digits_for = static function ( string $body ): string {
		$digits = '';
		foreach ( str_split( strtoupper( str_replace( '-', '', $body ) ) ) as $char ) {
			$digits .= ctype_digit( $char ) ? $char : (string) ( ord( $char ) - 55 );
		}
		$remainder = 0;
		foreach ( str_split( $digits . '00' ) as $digit ) {
			$remainder = ( $remainder * 10 + (int) $digit ) % 97;
		}
		return str_pad( (string) ( 98 - $remainder ), 2, '0', STR_PAD_LEFT );
	};
	foreach ( [ '9', str_repeat( '1', 13 ), '04011000-' . str_repeat( 'A', 31 ), '04011000-12345_6' ] as $body ) {
		$candidate = $body . '-' . $digits_for( $body );
		pp_assert( ! WM_Peppol_Leitweg_Validator::is_valid( $candidate ), "structure refused although its check digits fit: \"{$candidate}\"" );
	}
	foreach ( [ '04011000', '' ] as $bad ) {
		pp_assert( ! WM_Peppol_Leitweg_Validator::is_valid( $bad ), "an ID without check digits is refused: \"{$bad}\"" );
	}
	pp_assert( ! WM_Peppol_Leitweg_Validator::is_valid( null ), 'a non-string is refused' );
	pp_assert( ! str_contains( pp_code( 'includes/class-peppol-leitweg-validator.php' ), 'bcmod' ), 'no bcmath call (was: every ID a checksum error on a host without bcmath)' );

	if ( function_exists( 'bcmod' ) ) {
		mt_srand( 20260915 );
		$differs = 0;
		for ( $i = 0; $i < 300; $i++ ) {
			$base     = (string) mt_rand( 10, 999999999999 );
			$fine     = (string) mt_rand( 0, PHP_INT_MAX ) . (string) mt_rand( 0, 99999 );
			$expected = str_pad( (string) ( 98 - (int) bcmod( $base . $fine . '00', '97' ) ), 2, '0', STR_PAD_LEFT );
			$differs += $expected === WM_Peppol_Leitweg_Validator::check_digits( "{$base}-{$fine}" ) ? 0 : 1;
		}
		pp_assert( 0 === $differs, "300 random IDs: the check digits equal a bcmod() computation ({$differs} differ)" );
	}
} );

pp_group( 'SMP · receiver discovery through SML and SMP', static function (): void {
	pp_reset();

	// The DNS names below were computed here and verified against the production SML with
	// `dig +short NAPTR` on 2026-09-16: 0007:5567321707 resolves, the invented id does not.
	// The whole name as a literal, not built from the constant: a test that composes the expectation
	// out of the value under test cannot notice the value changing.
	$expected_name = '2hye2p3sorevayvcwhqmlegeofbpbta3snxm52wxih4a7elbvdhq.iso6523-actorid-upis.participant.sml.prod.tech.peppol.org';
	pp_assert( 'participant.sml.prod.tech.peppol.org' === SmpClient::SML_ZONE_PRODUCTION && 'participant.sml.test.tech.peppol.org' === SmpClient::SML_ZONE_TEST, 'the SML zones are the ones the network answers on (measured 2026-09-16), not the retired edelivery host' );
	$client        = new SmpClient( static fn( string $name ): array => [] );

	pp_assert( $expected_name === $client->dns_name( '0007:5567321707' ), 'the SML name is base32(sha256(lowercase(value))) without padding, the scheme and the zone — no "b-" prefix and no md5 (measured against the live SML)' );
	pp_assert( $client->dns_name( 'iso6523-actorid-upis::0007:5567321707' ) === $expected_name, 'an identifier written with its scheme in front gives the same name' );
	pp_assert( str_ends_with( (string) $client->dns_name( '0007:5567321707', SmpClient::SML_ZONE_TEST ), SmpClient::SML_ZONE_TEST ), 'the test zone can be asked instead' );
	pp_assert( $client->dns_name( '9930:DE123456789' ) === $client->dns_name( '9930:de123456789' ), 'the identifier is lower-cased before it is hashed, so the same participant written either way finds the same SMP' );

	foreach ( [ '', 'DE123456789', 'iso6523-actorid-upis::', '99:short' ] as $bad ) {
		$outcome = $client->dns_name( $bad );
		pp_assert( $outcome instanceof WP_Error && SmpClient::ERROR_IDENTIFIER === $outcome->get_error_code(), "an identifier without a four-digit scheme is refused: \"{$bad}\"" );
	}

	pp_assert( 'https://smp.qvalia.com/' === SmpClient::url_from_naptr_regexp( '!.*!https://smp.qvalia.com/!' ), 'the SMP URL is read out of the NAPTR regexp field, in the form the SML writes it' );
	pp_assert( '' === SmpClient::url_from_naptr_regexp( '!.*!not-a-url!' ) && '' === SmpClient::url_from_naptr_regexp( '' ), 'a regexp without an http URL yields nothing, so no request is made to it' );

	// A participant with no NAPTR record: the answer that used to be an access point with cert_valid true.
	$missing = ( new SmpClient( static fn(): array => [] ) )->lookup_receiver_endpoint( '0000:NOBODY-AT-ALL' );
	pp_assert( $missing instanceof WP_Error && SmpClient::ERROR_DNS === $missing->get_error_code() && 404 === ( $missing->get_error_data()['status'] ?? null ), 'an unregistered participant answers "no NAPTR record", not an access point (was: a fixed endpoint for any id)' );

	// Records of the wrong service, and the order rule of RFC 3403.
	$two_smps = static fn(): array => [
		[ 'order' => 100, 'pref' => 20, 'services' => 'Meta:SMP', 'regex' => '!.*!https://second.example/!' ],
		[ 'order' => 100, 'pref' => 10, 'services' => 'Meta:SMP', 'regex' => '!.*!https://first.example/!' ],
		// A record of another service, with an http URL and the lowest order: only the service field
		// keeps it out, so this is what an unfiltered lookup would pick.
		[ 'order' => 1, 'pref' => 1, 'services' => 'Meta:OTHER', 'regex' => '!.*!https://wrong-service.example/!' ],
		[ 'order' => 50, 'pref' => 99, 'services' => 'E2U+sip', 'regex' => '!.*!sip:nobody@example!' ],
	];
	pp_assert( 'https://first.example' === ( new SmpClient( $two_smps ) )->resolve_smp_url( '0007:5567321707' ), 'of several Meta:SMP records the lowest order and preference wins, and a record of another service is skipped' );

	$only_sip = ( new SmpClient( static fn(): array => [ [ 'order' => 10, 'pref' => 10, 'services' => 'E2U+sip', 'regex' => '!.*!sip:x@example!' ] ] ) )->resolve_smp_url( '0007:5567321707' );
	pp_assert( $only_sip instanceof WP_Error && SmpClient::ERROR_NO_META === $only_sip->get_error_code(), 'a NAPTR record that names no SMP is reported as such' );

	// The whole path with the SMP answering: the document below is the shape a live SMP returned.
	$smp = static fn(): array => [ [ 'order' => 100, 'pref' => 10, 'services' => 'Meta:SMP', 'regex' => '!.*!https://smp.example/!' ] ];
	$metadata = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<SignedServiceMetadata xmlns="http://busdox.org/serviceMetadata/publishing/1.0/" xmlns:ids="http://busdox.org/transport/identifiers/1.0/" xmlns:wsa="http://www.w3.org/2005/08/addressing">
 <ServiceMetadata><ServiceInformation>
  <ids:ParticipantIdentifier scheme="iso6523-actorid-upis">0007:5567321707</ids:ParticipantIdentifier>
  <ids:DocumentIdentifier scheme="busdox-docid-qns">DOCTYPE</ids:DocumentIdentifier>
  <ProcessList><Process>
   <ids:ProcessIdentifier scheme="cenbii-procid-ubl">urn:fdc:peppol.eu:2017:poacc:billing:01:1.0</ids:ProcessIdentifier>
   <ServiceEndpointList>
    <Endpoint transportProfile="peppol-transport-as1-ver1p0"><wsa:EndpointReference><wsa:Address>https://old.example/as2</wsa:Address></wsa:EndpointReference></Endpoint>
    <Endpoint transportProfile="peppol-transport-as4-v2_0">
     <wsa:EndpointReference><wsa:Address>https://peppol-ap.example/peppol/as4</wsa:Address></wsa:EndpointReference>
     <ServiceActivationDate>2020-01-28T00:00:00Z</ServiceActivationDate>
     <ServiceExpirationDate>2030-01-28T23:59:59Z</ServiceExpirationDate>
     <Certificate>CERTIFICATE</Certificate>
    </Endpoint>
   </ServiceEndpointList>
  </Process></ProcessList>
 </ServiceInformation></ServiceMetadata>
</SignedServiceMetadata>
XML;

	$GLOBALS['wp_test']['http_queue'][] = wm_test_http( 200, $metadata );
	$found = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( is_array( $found ) && 'https://peppol-ap.example/peppol/as4' === $found['access_point_url'], 'the lookup returns the AS4 address of the SMP document' );
	pp_assert( is_array( $found ) && SmpClient::TRANSPORT_PROFILE_AS4 === $found['transport_profile'], 'the AS4 endpoint is taken, not the AS2 endpoint that stands before it' );
	pp_assert( is_array( $found ) && [ 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0' ] === $found['process_ids'], 'the process identifier of the surrounding Process element is reported' );
	pp_assert( is_array( $found ) && '2030-01-28T23:59:59Z' === $found['service_expiration_date'], 'the service dates are reported as the SMP wrote them' );
	pp_assert( is_array( $found ) && str_contains( (string) ( $GLOBALS['wp_test']['http_calls'][0]['url'] ?? '' ), '/services/' ) && str_contains( (string) ( $GLOBALS['wp_test']['http_calls'][0]['url'] ?? '' ), 'busdox-docid-qns' ), 'the SMP is asked for the document type under /services/, percent-encoded' );
	pp_assert( is_array( $found ) && str_contains( (string) ( $GLOBALS['wp_test']['http_calls'][0]['url'] ?? '' ), '%3A%3A' ), 'the two colons of the identifier are percent-encoded in the path, as the SMP publishes them' );

	// A second lookup must not ask again.
	$GLOBALS['wp_test']['http_calls'] = [];
	$again = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( is_array( $again ) && true === $again['cached'] && [] === $GLOBALS['wp_test']['http_calls'], 'a repeated lookup comes from the cache and makes no request' );

	// The failures, each with its own code.
	pp_reset();
	$GLOBALS['wp_test']['http_queue'][] = wm_test_http( 404, '' );
	$not_offered = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( $not_offered instanceof WP_Error && SmpClient::ERROR_NOT_OFFERED === $not_offered->get_error_code(), 'a 404 from the SMP means this receiver does not accept this document type, and says so' );

	pp_reset();
	$GLOBALS['wp_test']['http_queue'][] = wm_test_http( 500, '' );
	$broken = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( $broken instanceof WP_Error && SmpClient::ERROR_HTTP === $broken->get_error_code(), 'an SMP that answers 500 is an SMP error, not an unregistered participant' );

	pp_reset();
	$GLOBALS['wp_test']['http_queue'][] = wm_test_http( 200, '<not-xml' );
	$garbage = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( $garbage instanceof WP_Error && SmpClient::ERROR_PARSE === $garbage->get_error_code(), 'a body that is not XML is reported as such' );

	pp_reset();
	$GLOBALS['wp_test']['http_queue'][] = wm_test_http( 200, str_replace( 'peppol-transport-as4-v2_0', 'peppol-transport-as1-ver1p0', $metadata ) );
	$no_as4 = ( new SmpClient( $smp ) )->lookup_receiver_endpoint( '0007:5567321707' );
	pp_assert( $no_as4 instanceof WP_Error && SmpClient::ERROR_NO_ENDPOINT === $no_as4->get_error_code(), 'a receiver that publishes no AS4 endpoint is reported, not silently accepted' );

	// The certificate is described from the certificate, never asserted.
	$described = SmpClient::describe_certificate( '' );
	pp_assert( '' === $described['subject'] && false === $described['expired'], 'an unreadable certificate is reported as unknown, not as valid' );
	pp_assert( true === SmpClient::is_expired( time() - 86400 ) && false === SmpClient::is_expired( time() + 86400 ) && false === SmpClient::is_expired( 0 ), 'a notAfter in the past is expired, one in the future is not, and an unknown date is neither' );
	pp_assert( ! str_contains( pp_code( 'includes/Discovery/SmpClient.php' ), 'cert_valid' ), 'no "cert_valid" flag is invented anywhere' );

	pp_assert( true === ( new SmpClient() )->is_implemented(), 'discovery reports itself as implemented, because it makes real requests' );
} );
