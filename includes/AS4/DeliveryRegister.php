<?php
/**
 * Append-only register of delivery evidence, chained so a later edit is visible.
 *
 * `wm_peppol_audit_log` has existed since the first version and nothing ever wrote to it; the HMAC
 * ledger in includes/class-peppol-database.php was written against a different table layout and was
 * never called. This writes the table the plugin actually creates, and leaves that class alone.
 *
 * Each row carries the hash of the row before it, so removing or editing one breaks every hash after
 * it. The chain is keyed with the site's AUTH_KEY, which means a copy of the database alone does not
 * let anyone recompute a consistent chain — and also that restoring a database onto a site with
 * different salts makes the chain unverifiable, which is stated rather than hidden.
 *
 * What it is not: it is not a timestamp from a trusted third party and not a qualified seal. It shows
 * that the rows have not changed since this site wrote them.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\AS4;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DeliveryRegister {

	/** The hash a chain starts from, so the first row is chained to something fixed. */
	public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

	public const ACTION_RECEIPT   = 'receipt';
	public const ACTION_DISPATCH  = 'dispatch';
	public const ACTION_REJECTED  = 'receipt_rejected';

	/** Table name, which is the one Database\Schema creates. */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'wm_peppol_audit_log';
	}

	/**
	 * The key the chain is computed with. Never stored in a row and never reported.
	 */
	private static function key(): string {
		if ( defined( 'AUTH_KEY' ) && '' !== (string) AUTH_KEY ) {
			return (string) AUTH_KEY;
		}

		return wp_salt( 'auth' );
	}

	/**
	 * The fingerprint of one row, over the row's own fields and the row before it.
	 *
	 * The detail is folded in as its SHA-256 rather than in full, so the fingerprint does not grow
	 * with the evidence and a reader can recompute it from the stored column.
	 */
	public static function fingerprint( string $previous, string $invoice_number, string $action, string $message_id, string $detail_json, string $created_at ): string {
		return hash_hmac(
			'sha256',
			implode( '|', [ $previous, $invoice_number, $action, $message_id, hash( 'sha256', $detail_json ), $created_at ] ),
			self::key()
		);
	}

	/** The fingerprint of the newest row, or the genesis hash on an empty register. */
	public static function tip(): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tip = $wpdb->get_var( $wpdb->prepare( 'SELECT current_hash FROM %i ORDER BY id DESC LIMIT 1', self::table() ) );

		return is_string( $tip ) && '' !== $tip ? $tip : self::GENESIS;
	}

	/**
	 * Append one entry.
	 *
	 * @param array<string, mixed> $detail What was measured. Never the payload, never an address.
	 * @return int|false The row id, or false when the insert failed.
	 */
	public static function record( string $invoice_number, string $action, string $message_id, array $detail ) {
		global $wpdb;

		$created_at  = current_time( 'mysql', true );
		$previous    = self::tip();
		$detail_json = (string) wp_json_encode( $detail );
		$current     = self::fingerprint( $previous, $invoice_number, $action, $message_id, $detail_json, $created_at );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			self::table(),
			[
				'invoice_number' => $invoice_number,
				'action'         => $action,
				'message_id'     => $message_id,
				'detail_json'    => $detail_json,
				'previous_hash'  => $previous,
				'current_hash'   => $current,
				'created_at'     => $created_at,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Recompute the chain from the first row.
	 *
	 * @return array{valid:bool, checked:int, broken_at:int|null, reason:string}
	 */
	public static function verify_chain(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_number, action, message_id, detail_json, previous_hash, current_hash, created_at FROM %i ORDER BY id ASC', self::table() ), ARRAY_A );

		$expected = self::GENESIS;
		$checked  = 0;

		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];

			if ( (string) $row['previous_hash'] !== $expected ) {
				return [
					'valid'     => false,
					'checked'   => $checked,
					'broken_at' => $id,
					'reason'    => sprintf(
						/* translators: %d: row id */
						__( 'Zeile %d verweist nicht auf die Zeile davor: eine Zeile wurde entfernt oder eingefügt.', 'wm-peppol-as4-gateway' ),
						$id
					),
				];
			}

			$fingerprint = self::fingerprint(
				(string) $row['previous_hash'],
				(string) $row['invoice_number'],
				(string) $row['action'],
				(string) $row['message_id'],
				(string) $row['detail_json'],
				(string) $row['created_at']
			);

			if ( ! hash_equals( $fingerprint, (string) $row['current_hash'] ) ) {
				return [
					'valid'     => false,
					'checked'   => $checked,
					'broken_at' => $id,
					'reason'    => sprintf(
						/* translators: %d: row id */
						__( 'Zeile %d wurde nach dem Schreiben verändert, oder die Datenbank stammt von einer Installation mit anderen Schlüsseln.', 'wm-peppol-as4-gateway' ),
						$id
					),
				];
			}

			$expected = (string) $row['current_hash'];
			$checked++;
		}

		return [ 'valid' => true, 'checked' => $checked, 'broken_at' => null, 'reason' => '' ];
	}

	/**
	 * The most recent entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;

		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, invoice_number, action, message_id, detail_json, current_hash, created_at FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ), ARRAY_A );

		return array_map(
			static function ( array $row ): array {
				$row['detail'] = json_decode( (string) $row['detail_json'], true );
				unset( $row['detail_json'] );

				return $row;
			},
			(array) $rows
		);
	}
}
