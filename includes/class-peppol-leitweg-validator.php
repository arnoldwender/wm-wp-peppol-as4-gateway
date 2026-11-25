<?php
/**
 * Leitweg-ID syntax and check digit.
 *
 * Source: KoSIT, "Leitweg-ID Format-Spezifikation Version 2.0.2", Fassung vom 28.07.2021, chapters 2.1-2.5
 * (leitweg-id.de, read 2026-09-15):
 *   - Grobadressierung: 2 to 12 digits, mandatory.
 *   - Feinadressierung: up to 30 characters A-Z (not case-sensitive) and 0-9, optional, introduced by "-".
 *   - Prüfziffer: 2 digits after "-", ISO/IEC 7064:2003 Modulo 97-10 over Grob- and Feinadressierung
 *     without hyphens, letters replaced by A=10 ... Z=35; a valid ID leaves remainder 1.
 *   - Worked example of the specification: 04011000-1234512345-06.
 *
 * Until 2026-09-15 the check used bcmod(): on a host without bcmath every ID was reported as a
 * checksum error. The remainder is now computed digit by digit, which needs no extension.
 *
 * @package WM_Peppol_AS4_Gateway
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WM_Peppol_Leitweg_Validator {

	/** Grobadressierung, optional Feinadressierung, check digits (chapter 2.1, table 1). */
	private const PATTERN = '/^(\d{2,12})(?:-([A-Za-z0-9]{1,30}))?-(\d{2})$/';

	/**
	 * True when the ID has the structure of chapter 2.1 and its check digits verify.
	 *
	 * @param mixed $leitweg_id Raw value.
	 * @return bool
	 */
	public static function is_valid( $leitweg_id ): bool {
		if ( ! is_string( $leitweg_id ) || ! preg_match( self::PATTERN, trim( $leitweg_id ), $parts ) ) {
			return false;
		}

		$numeric = self::to_digits( $parts[1] . ( $parts[2] ?? '' ) ) . $parts[3];

		return 1 === self::mod97( $numeric );
	}

	/**
	 * Check digits for a Leitweg-ID without them, e.g. "04011000-1234512345" gives "06".
	 *
	 * @param string $without_check Grobadressierung and optional Feinadressierung.
	 * @return string|null Null when the input does not have the structure of chapter 2.1.
	 */
	public static function check_digits( string $without_check ): ?string {
		if ( ! preg_match( '/^(\d{2,12})(?:-([A-Za-z0-9]{1,30}))?$/', trim( $without_check ), $parts ) ) {
			return null;
		}
		$remainder = self::mod97( self::to_digits( $parts[1] . ( $parts[2] ?? '' ) ) . '00' );
		return str_pad( (string) ( 98 - $remainder ), 2, '0', STR_PAD_LEFT );
	}

	/** Letters to their alphabet position (A=10 ... Z=35), digits unchanged. */
	private static function to_digits( string $value ): string {
		$out = '';
		foreach ( str_split( strtoupper( $value ) ) as $char ) {
			$out .= ctype_digit( $char ) ? $char : (string) ( ord( $char ) - 55 );
		}
		return $out;
	}

	/** Remainder of a decimal string of any length divided by 97. */
	private static function mod97( string $digits ): int {
		$remainder = 0;
		foreach ( str_split( $digits ) as $digit ) {
			$remainder = ( $remainder * 10 + (int) $digit ) % 97;
		}
		return $remainder;
	}
}
