<?php
/**
 * Measured state of the invoice tables, shared by the admin page, the dashboard widget and the hub adapter.
 *
 * Demo rows: the seeder writes status "demo". The seeder before 2026-09-15 wrote three rows with status
 * "delivered"/"queued" and a one-element XML; those are recognised by their exact invoice number and XML, so
 * the screens stop counting them as invoices and the removal action can delete them. Nothing deletes them on
 * its own.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InvoiceStore {

	public const DEMO_STATUS = 'demo';

	/** Invoice numbers of the demo rows written before 2026-09-15; each carried the XML <Invoice><ID>number</ID></Invoice>. */
	public const LEGACY_DEMO_NUMBERS = [ 'INV-2026-9041', 'INV-2026-8922', 'INV-2026-9104' ];

	public static function invoices_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_peppol_invoices';
	}

	public static function transmissions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wm_peppol_transmissions';
	}

	/** True when the invoice and transmission tables exist. */
	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( [ self::invoices_table(), self::transmissions_table() ] as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Ids of demo rows, current and legacy.
	 *
	 * @return int[]
	 */
	public static function demo_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, invoice_number, status, ubl_xml FROM %i WHERE status = %s OR invoice_number IN (%s, %s, %s)',
				self::invoices_table(),
				self::DEMO_STATUS,
				self::LEGACY_DEMO_NUMBERS[0],
				self::LEGACY_DEMO_NUMBERS[1],
				self::LEGACY_DEMO_NUMBERS[2]
			),
			ARRAY_A
		);

		$ids = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			if ( self::DEMO_STATUS === $row['status'] || '<Invoice><ID>' . $row['invoice_number'] . '</ID></Invoice>' === $row['ubl_xml'] ) {
				$ids[] = (int) $row['id'];
			}
		}
		return $ids;
	}

	/**
	 * Row counts. `invoices` excludes demo rows.
	 *
	 * @return array{tables: bool, invoices: int, demo: int, transmissions: int, delivered: int}
	 */
	public static function counts(): array {
		if ( ! self::tables_exist() ) {
			return [ 'tables' => false, 'invoices' => 0, 'demo' => 0, 'transmissions' => 0, 'delivered' => 0 ];
		}

		global $wpdb;
		$demo = count( self::demo_ids() );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$all           = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::invoices_table() ) );
		$transmissions = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::transmissions_table() ) );
		$delivered     = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE transport_status = %s', self::transmissions_table(), 'delivered' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return [
			'tables'        => true,
			'invoices'      => max( 0, $all - $demo ),
			'demo'          => $demo,
			'transmissions' => $transmissions,
			'delivered'     => $delivered,
		];
	}
}
