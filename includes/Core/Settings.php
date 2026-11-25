<?php
/**
 * The configuration every writer of an invoice reads: seller data and the two service URLs.
 *
 * Until 2026-09-15 the settings screen saved a `wm_peppol_settings` array that nothing else read, while
 * the generator filled the seller with "Musterstrasse 1" and VAT id "DE123456789" from options no
 * screen wrote. The screen, the generator, the WooCommerce flow, the dispatch client and the hub
 * adapter now share these option names.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	/** Seller field => option name. Field names are the keys the generator reads in `seller`. */
	public const SELLER_OPTIONS = [
		'endpoint'    => 'wm_peppol_endpoint_id',
		'name'        => 'wm_peppol_seller_name',
		'legal_name'  => 'wm_peppol_seller_legal_name',
		'street'      => 'wm_peppol_seller_street',
		'city'        => 'wm_peppol_seller_city',
		'postal_code' => 'wm_peppol_seller_postal_code',
		'country'     => 'wm_peppol_seller_country',
		'vat_id'      => 'wm_peppol_seller_vat_id',
		'company_id'  => 'wm_peppol_seller_company_id',
		'iban'        => 'wm_peppol_seller_iban',
	];

	/**
	 * Seller fields an invoice of this plugin needs. The generator requires the first seven for every document
	 * and the IBAN for payment by credit transfer, the payment means it uses unless told otherwise (BR-61).
	 */
	public const SELLER_REQUIRED = [ 'endpoint', 'name', 'street', 'city', 'postal_code', 'country', 'vat_id', 'iban' ];

	/** KoSIT validator service; a constant of the same name in wp-config.php wins over the option. */
	public const KOSIT_URL_OPTION = 'wm_peppol_kosit_url';

	/** Phase4 sending service; a constant of the same name in wp-config.php wins over the option. */
	public const PHASE4_URL_OPTION = 'wm_peppol_phase4_url';

	/** Receipt webhook secret (read by WM_Peppol_Webhook_Receiver::secret()). */
	public const RECEIPT_SECRET_OPTION = 'wm_peppol_receipt_secret';

	/** Comma-separated SHA-256 hex fingerprints of trusted AS4 receipt signer certificates. */
	public const TRUSTED_SIGNER_SHA256_OPTION = 'wm_peppol_trusted_signer_sha256';

	/** PEM-encoded Root/Intermediate CA bundle for local AS4 receipt chain verification. */
	public const TRUST_BUNDLE_PEM_OPTION = 'wm_peppol_trust_bundle_pem';

	/** Opt-in flag ('1'/'0') requiring either a pinned SHA-256 fingerprint or a local CA bundle on AS4 receipts. */
	public const REQUIRE_TRUST_ANCHOR_OPTION = 'wm_peppol_require_trust_anchor';

	/** Opt-in flag controlling whether uninstall.php deletes stored data and drops tables. */
	public const DELETE_DATA_ON_UNINSTALL_OPTION = 'wm_peppol_delete_data_on_uninstall';

	/** True only when an administrator explicitly opted into deleting data on uninstall. */
	public static function delete_data_on_uninstall(): bool {
		$val = function_exists( 'get_option' ) ? get_option( self::DELETE_DATA_ON_UNINSTALL_OPTION, '0' ) : '0';
		return '1' === (string) $val || true === $val;
	}

	/**
	 * Normalised 64-char lowercase hex SHA-256 fingerprints of trusted AS4 receipt signers.
	 *
	 * @return string[]
	 */
	public static function trusted_signer_fingerprints(): array {
		$raw = '';
		if ( defined( 'WM_PEPPOL_TRUSTED_SIGNER_SHA256' ) && '' !== trim( (string) constant( 'WM_PEPPOL_TRUSTED_SIGNER_SHA256' ) ) ) {
			$raw = (string) constant( 'WM_PEPPOL_TRUSTED_SIGNER_SHA256' );
		} elseif ( function_exists( 'get_option' ) ) {
			$opt = get_option( self::TRUSTED_SIGNER_SHA256_OPTION, '' );
			$raw = is_string( $opt ) ? $opt : '';
		}
		return self::parse_fingerprints( $raw );
	}

	/**
	 * Parse comma- or line-separated SHA-256 fingerprints (accepting optional colons).
	 *
	 * @return string[]
	 */
	public static function parse_fingerprints( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', trim( $raw ) ) ?: [] as $token ) {
			$clean = strtolower( str_replace( ':', '', trim( $token ) ) );
			if ( 1 === preg_match( '/^[0-9a-f]{64}$/', $clean ) && ! in_array( $clean, $out, true ) ) {
				$out[] = $clean;
			}
		}
		return $out;
	}

	/**
	 * Sanitize PEM certificate blocks so only well-formed CERTIFICATE blocks are stored.
	 */
	public static function sanitise_pem_bundle( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}
		if ( ! preg_match_all( '/-----BEGIN CERTIFICATE-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END CERTIFICATE-----/', $raw, $matches ) ) {
			return '';
		}
		$blocks = [];
		foreach ( $matches[1] as $b64 ) {
			$clean = preg_replace( '/\s+/', '', (string) $b64 ) ?? '';
			if ( '' !== $clean && false !== base64_decode( $clean, true ) ) {
				$blocks[] = "-----BEGIN CERTIFICATE-----\n" . chunk_split( $clean, 64, "\n" ) . '-----END CERTIFICATE-----';
			}
		}
		return [] === $blocks ? '' : implode( "\n", $blocks ) . "\n";
	}

	/**
	 * Local CA trust bundle PEM (from WM_PEPPOL_TRUST_BUNDLE_PEM, local file WM_PEPPOL_TRUST_BUNDLE_FILE, or option).
	 */
	public static function trust_bundle_pem(): string {
		if ( defined( 'WM_PEPPOL_TRUST_BUNDLE_PEM' ) && '' !== trim( (string) constant( 'WM_PEPPOL_TRUST_BUNDLE_PEM' ) ) ) {
			return self::sanitise_pem_bundle( (string) constant( 'WM_PEPPOL_TRUST_BUNDLE_PEM' ) );
		}
		if ( defined( 'WM_PEPPOL_TRUST_BUNDLE_FILE' ) ) {
			$path = trim( (string) constant( 'WM_PEPPOL_TRUST_BUNDLE_FILE' ) );
			if ( '' !== $path && is_file( $path ) && is_readable( $path ) ) {
				return self::sanitise_pem_bundle( (string) file_get_contents( $path ) );
			}
		}
		$opt = function_exists( 'get_option' ) ? get_option( self::TRUST_BUNDLE_PEM_OPTION, '' ) : '';
		return is_string( $opt ) ? self::sanitise_pem_bundle( $opt ) : '';
	}

	/** True when at least one signer SHA-256 pin or a local CA bundle is configured. */
	public static function trust_anchor_configured(): bool {
		return [] !== self::trusted_signer_fingerprints() || '' !== self::trust_bundle_pem();
	}

	/** True when unanchored AS4 receipts must be rejected even if no pin/CA bundle is set yet. */
	public static function trust_anchor_enforced(): bool {
		if ( defined( 'WM_PEPPOL_REQUIRE_TRUST_ANCHOR' ) ) {
			$const = constant( 'WM_PEPPOL_REQUIRE_TRUST_ANCHOR' );
			return '1' === (string) $const || true === $const;
		}
		$val = function_exists( 'get_option' ) ? get_option( self::REQUIRE_TRUST_ANCHOR_OPTION, '0' ) : '0';
		return '1' === (string) $val || true === $val;
	}

	/**
	 * Seller data as stored, empty strings for fields never saved.
	 *
	 * @return array<string, string>
	 */
	public static function seller(): array {
		$seller = [];
		foreach ( self::SELLER_OPTIONS as $field => $option ) {
			$value            = function_exists( 'get_option' ) ? get_option( $option, '' ) : '';
			$seller[ $field ] = is_scalar( $value ) ? trim( (string) $value ) : '';
		}
		return $seller;
	}

	/**
	 * Required seller fields that are empty.
	 *
	 * @param array<string, mixed>|null $seller Seller data; the stored data when null.
	 * @return string[]
	 */
	public static function missing_seller_fields( ?array $seller = null ): array {
		$seller = $seller ?? self::seller();
		return array_values(
			array_filter(
				self::SELLER_REQUIRED,
				static fn( string $field ): bool => '' === trim( (string) ( $seller[ $field ] ?? '' ) )
			)
		);
	}

	/** KoSIT validator URL, '' when not configured. */
	public static function kosit_url(): string {
		return self::url( 'WM_PEPPOL_KOSIT_URL', self::KOSIT_URL_OPTION );
	}

	/** Phase4 sending service URL, '' when not configured. */
	public static function phase4_url(): string {
		return self::url( 'WM_PEPPOL_PHASE4_URL', self::PHASE4_URL_OPTION );
	}

	/** True when both services are configured: nothing is dispatched otherwise. */
	public static function dispatch_configured(): bool {
		return '' !== self::kosit_url() && '' !== self::phase4_url();
	}

	/**
	 * Constant first, option second, nothing otherwise. There is no default: the WooCommerce flow
	 * used to post every completed order to http://localhost:8080 and :8081.
	 *
	 * @param string $constant Constant name.
	 * @param string $option   Option name.
	 * @return string
	 */
	private static function url( string $constant, string $option ): string {
		if ( defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
			return trim( (string) constant( $constant ) );
		}
		$value = function_exists( 'get_option' ) ? get_option( $option, '' ) : '';
		return is_string( $value ) ? trim( $value ) : '';
	}
}
