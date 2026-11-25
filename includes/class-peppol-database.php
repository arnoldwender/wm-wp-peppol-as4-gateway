<?php
/**
 * WM Peppol AS4 Gateway — Revisionssicheres GoBD Ledger & Database Layer
 *
 * Implements HMAC-SHA256 linear hash chaining for all inbound and outbound
 * electronic invoices, ensuring mathematical non-repudiation and auditability.
 *
 * @package WM_Peppol_AS4_Gateway
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Peppol_Database {

	public const SCHEMA_VERSION = '1.0.0';
	public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';
	public const TIP_OPTION = 'wm_peppol_ledger_tip';

	public static function invoices_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_peppol_invoices';
	}

	public static function create_tables(): void {
		global $wpdb;
		$table = self::invoices_table();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_number VARCHAR(100) NOT NULL,
			direction VARCHAR(10) NOT NULL DEFAULT 'INBOUND',
			format VARCHAR(50) NOT NULL,
			profile VARCHAR(255) NOT NULL,
			sender_id VARCHAR(100) NOT NULL,
			receiver_id VARCHAR(100) NOT NULL,
			net_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			gross_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
			currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
			payload_hash VARCHAR(64) NOT NULL,
			prev_hash VARCHAR(64) NOT NULL,
			signature_hash VARCHAR(64) NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_invoice_num (invoice_number),
			KEY idx_direction (direction),
			KEY idx_signature (signature_hash)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && is_file( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		dbDelta( $sql );

		update_option( 'wm_peppol_db_version', self::SCHEMA_VERSION, false );
	}

	public static function current_chain_tip(): string {
		global $wpdb;
		$table = self::invoices_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tip = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT signature_hash FROM %i WHERE prev_hash <> %s ORDER BY id DESC LIMIT %d',
				$table,
				'',
				1
			)
		);
		return $tip ? (string) $tip : self::GENESIS_HASH;
	}

	public static function generate_signature(
		string $invoice_number,
		string $direction,
		float $gross_amount,
		string $payload_hash,
		string $timestamp,
		string $prev_hash
	): string {
		$salt = defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ? (string) AUTH_KEY : wp_salt( 'auth' );
		$payload = implode( '|', [
			$invoice_number,
			$direction,
			number_format( $gross_amount, 2, '.', '' ),
			$payload_hash,
			$timestamp,
			$prev_hash,
		] );
		return hash_hmac( 'sha256', $payload, $salt );
	}

	public static function record_invoice( array $data ): int|false {
		global $wpdb;
		$table = self::invoices_table();

		$timestamp      = current_time( 'mysql', true );
		$prev_hash      = self::current_chain_tip();
		$gross_amount   = (float) ( $data['gross_amount'] ?? 0.0 );
		$payload_hash   = (string) ( $data['payload_hash'] ?? hash( 'sha256', '' ) );
		$invoice_number = sanitize_text_field( (string) ( $data['invoice_number'] ?? '' ) );
		$direction      = in_array( $data['direction'] ?? '', [ 'INBOUND', 'OUTBOUND' ], true ) ? $data['direction'] : 'INBOUND';

		$signature = self::generate_signature(
			$invoice_number,
			$direction,
			$gross_amount,
			$payload_hash,
			$timestamp,
			$prev_hash
		);

		$inserted = $wpdb->insert(
			$table,
			[
				'invoice_number' => $invoice_number,
				'direction'      => $direction,
				'format'         => sanitize_text_field( (string) ( $data['format'] ?? 'UBL_INVOICE' ) ),
				'profile'        => sanitize_text_field( (string) ( $data['profile'] ?? '' ) ),
				'sender_id'      => sanitize_text_field( (string) ( $data['sender_id'] ?? '' ) ),
				'receiver_id'    => sanitize_text_field( (string) ( $data['receiver_id'] ?? '' ) ),
				'net_amount'     => (float) ( $data['net_amount'] ?? 0.0 ),
				'tax_amount'     => (float) ( $data['tax_amount'] ?? 0.0 ),
				'gross_amount'   => $gross_amount,
				'currency'       => sanitize_text_field( (string) ( $data['currency'] ?? 'EUR' ) ),
				'payload_hash'   => $payload_hash,
				'prev_hash'      => $prev_hash,
				'signature_hash' => $signature,
				'created_at'     => $timestamp,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return false;
		}

		$id = (int) $wpdb->insert_id;

		update_option(
			self::TIP_OPTION,
			[
				'hash'  => $signature,
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				'count' => (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ),
			],
			false
		);

		return $id;
	}

	public static function verify_chain(): array {
		global $wpdb;
		$table = self::invoices_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE 1 = %d ORDER BY id ASC',
				$table,
				1
			)
		);

		$result = [
			'valid'        => true,
			'checked'      => 0,
			'broken_at_id' => null,
			'error'        => null,
		];

		if ( empty( $rows ) ) {
			return $result;
		}

		$expected_prev = self::GENESIS_HASH;

		foreach ( $rows as $row ) {
			$id = (int) $row->id;

			if ( $row->prev_hash !== $expected_prev ) {
				$result['valid']        = false;
				$result['broken_at_id'] = $id;
				$result['error']        = sprintf( 'Kettenbruch bei Beleg-ID %d: Vorgängerhash stimmt nicht überein.', $id );
				return $result;
			}

			$expected_signature = self::generate_signature(
				(string) $row->invoice_number,
				(string) $row->direction,
				(float) $row->gross_amount,
				(string) $row->payload_hash,
				(string) $row->created_at,
				(string) $row->prev_hash
			);

			if ( ! hash_equals( $expected_signature, (string) $row->signature_hash ) ) {
				$result['valid']        = false;
				$result['broken_at_id'] = $id;
				$result['error']        = sprintf( 'Signatur-Manipulation bei Beleg-ID %d festgestellt.', $id );
				return $result;
			}

			$expected_prev = (string) $row->signature_hash;
			++$result['checked'];
		}

		return $result;
	}

	public static function sanitize_csv_cell( string $value ): string {
		$dangerous = [ '=', '+', '-', '@', "\t", "\r" ];
		$first = mb_substr( $value, 0, 1 );
		if ( in_array( $first, $dangerous, true ) ) {
			return "'" . $value;
		}
		return $value;
	}
}
