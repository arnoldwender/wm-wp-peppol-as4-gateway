<?php
/**
 * WP-CLI commands `wp peppol`.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_CLI;
use WP_CLI_Command;
use WMCyber\Peppol\Core\Plugin;
use WMCyber\Peppol\Discovery\SmpClient;
use WMCyber\Peppol\Validation\SchematronValidator;
use WMCyber\Peppol\AS4\DeliveryRegister;
use WMCyber\Peppol\Core\Settings;
use WMCyber\Peppol\UBL\InvoiceDataException;

final class PeppolCommand extends WP_CLI_Command {
	public static function register(): void {
		WP_CLI::add_command( 'peppol', self::class );
	}

	/**
	 * Generate a Peppol BIS Billing 3.0 UBL 2.1 XML invoice. Nothing is stored or sent.
	 *
	 * The seller comes from the plugin settings. Buyer, buyer reference and positions come from --data, a JSON
	 * object with the generator's keys (buyer, buyer_reference, lines or line_description with net_amount,
	 * vat_rate, ...). --amount sets a gross payable_amount. Missing data is reported, never filled in.
	 *
	 * ## OPTIONS
	 *
	 * <invoice_number>
	 * : Unique invoice number (e.g. INV-2026-1001).
	 *
	 * [--data=<file>]
	 * : JSON file with the invoice data.
	 *
	 * [--amount=<amount>]
	 * : Gross amount due; the positions are derived from it.
	 *
	 * [--output=<file>]
	 * : Path to write the generated UBL XML.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol generate-ubl INV-2026-1001 --data=invoice.json --output=/tmp/invoice.xml
	 *
	 * @subcommand generate-ubl
	 */
	public function generate_ubl( array $args, array $assoc_args ): void {
		$data = [];
		if ( isset( $assoc_args['data'] ) ) {
			$file = (string) $assoc_args['data'];
			if ( ! is_readable( $file ) ) {
				WP_CLI::error( "File not found: {$file}" );
			}
			$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.
			if ( ! is_array( $data ) ) {
				WP_CLI::error( "{$file} does not contain a JSON object." );
			}
		}
		$data['invoice_number'] = (string) $args[0];
		if ( isset( $assoc_args['amount'] ) ) {
			$data['payable_amount'] = (string) $assoc_args['amount'];
		}

		try {
			$xml = Plugin::instance()->get_ubl_generator()->generate_ubl_xml( $data );
		} catch ( InvoiceDataException $e ) {
			WP_CLI::error( $e->describe() );
		}

		$output_file = $assoc_args['output'] ?? null;
		if ( $output_file ) {
			file_put_contents( (string) $output_file, $xml ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- path named on the command line.
			WP_CLI::success( sprintf( 'Peppol BIS 3.0 UBL XML written to %s (not stored, not sent).', $output_file ) );
		} else {
			WP_CLI::line( $xml );
		}
	}

	/**
	 * Look a Peppol participant up in the SML and its SMP, and report the receiving access point.
	 *
	 * Reports what the network answered. A participant that is not registered, or that does not accept
	 * the document type, is an error with its own code and a sentence saying which of the two it is.
	 *
	 * ## OPTIONS
	 *
	 * <participant_id>
	 * : Participant ID, e.g. 9930:DE123456789 or iso6523-actorid-upis::0007:5567321707.
	 *
	 * [--document-type=<id>]
	 * : Document type to ask for; defaults to the Peppol BIS Billing 3.0 invoice this plugin builds.
	 *
	 * [--format=<format>]
	 * : Output format: table (default), json or yaml.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol lookup 0007:5567321707
	 */
	public function lookup( array $args, array $assoc_args ): void {
		$client        = Plugin::instance()->get_smp_client();
		$document_type = (string) ( $assoc_args['document-type'] ?? SmpClient::DOCTYPE_INVOICE );
		$result        = $client->lookup_receiver_endpoint( (string) $args[0], $document_type );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( '[%s] %s', $result->get_error_code(), $result->get_error_message() ) );
		}

		$format = (string) ( $assoc_args['format'] ?? 'table' );
		if ( 'table' !== $format ) {
			WP_CLI\Utils\format_items( $format, [ $result ], array_keys( $result ) );
			return;
		}

		WP_CLI::line( '=== Peppol-Empfänger ===' );
		WP_CLI::line( 'Teilnehmer:        ' . $result['participant_id'] );
		WP_CLI::line( 'SML-Name:          ' . $result['dns_name'] );
		WP_CLI::line( 'SMP:               ' . $result['smp_url'] );
		WP_CLI::line( 'Access Point:      ' . $result['access_point_url'] );
		WP_CLI::line( 'Transportprofil:   ' . $result['transport_profile'] );
		WP_CLI::line( 'Prozesse:          ' . ( [] === $result['process_ids'] ? '(keine genannt)' : implode( ', ', $result['process_ids'] ) ) );
		WP_CLI::line( 'Zertifikat:        ' . ( '' === $result['certificate_subject'] ? '(nicht lesbar)' : $result['certificate_subject'] ) );
		WP_CLI::line( 'Zertifikat gültig: ' . $result['certificate_valid_from'] . ' bis ' . $result['certificate_valid_to'] . ( $result['certificate_expired'] ? ' (ABGELAUFEN)' : '' ) );
		WP_CLI::line( 'Dienst bis:        ' . $result['service_expiration_date'] );
		WP_CLI::line( 'Aus dem Zwischenspeicher: ' . ( $result['cached'] ? 'ja' : 'nein' ) );
		WP_CLI::line( 'Geprüft wurde die Registrierung im Netz, nicht die Vertrauenskette des Zertifikats zur Peppol-PKI.' );
	}

	/**
	 * Operational status: version, tables, row counts, configuration. Reports what is stored.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol status
	 */
	public function status( array $args, array $assoc_args ): void {
		global $wpdb;

		WP_CLI::line( 'WM Peppol AS4 Gateway ' . WM_PEPPOL_VERSION );
		foreach ( [ 'wm_peppol_invoices', 'wm_peppol_transmissions', 'wm_peppol_audit_log' ] as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
			$count  = $exists ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ) : 0;
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			WP_CLI::line( sprintf( '%-25s %s', $suffix . ':', $exists ? $count . ' rows' : 'missing (re-activate the plugin)' ) );
		}
		$missing = Settings::missing_seller_fields();
		WP_CLI::line( sprintf( '%-25s %s', 'seller data:', [] === $missing ? 'complete' : 'missing ' . implode( ', ', $missing ) . ' (no invoice can be built)' ) );
		WP_CLI::line( sprintf( '%-25s %s', 'dispatch:', Settings::dispatch_configured() ? 'KoSIT and Phase4 URLs configured' : 'not configured, nothing is sent' ) );
		$secret = class_exists( 'WM_Peppol_Webhook_Receiver' ) ? \WM_Peppol_Webhook_Receiver::secret() : '';
		WP_CLI::line( sprintf( '%-25s %s', 'receipt webhook:', '' !== $secret ? 'secret configured' : 'no secret configured, receipts are refused (401)' ) );
		WP_CLI::line( sprintf( '%-25s %s', 'SMP discovery:', 'SML zone ' . SmpClient::zone() ) );
	}

	/**
	 * Check an invoice XML file (UBL 2.1 Invoice or CreditNote, or CII) with the structural checks of this plugin.
	 *
	 * This is not a schema or Schematron validation: a file that passes can still be refused by an access point.
	 * Exits 1 when a check fails.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the XML invoice file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol validate /path/to/invoice.xml
	 */
	public function validate( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "File not found: {$file}" );
		}
		if ( ! class_exists( 'WM_Peppol_Validator' ) ) {
			WP_CLI::error( 'WM_Peppol_Validator is not loaded.' );
		}

		$result = \WM_Peppol_Validator::validate( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.

		foreach ( $result['warnings'] as $warning ) {
			WP_CLI::warning( $warning );
		}
		if ( $result['valid'] ) {
			WP_CLI::success( sprintf(
				'%s (%s) passed the structural checks of this plugin (no schema or Schematron validation). Profile: %s | Payable: %s %s',
				$result['invoice_number'],
				$result['format'],
				$result['profile'],
				isset( $result['metadata']['payable_amount'] ) ? number_format( (float) $result['metadata']['payable_amount'], 2, '.', '' ) : number_format( (float) ( $result['metadata']['gross_amount'] ?? 0 ), 2, '.', '' ),
				$result['metadata']['currency'] ?? ''
			) );
			return;
		}
		WP_CLI::error( sprintf( "Checks failed (%s):\n  - %s", $result['format'], implode( "\n  - ", $result['errors'] ) ) );
	}

	/**
	 * Read the delivery register, or check that nothing in it was changed after it was written.
	 *
	 * One chained row per verified AS4 receipt. Each carries the fingerprint of the row before it, so
	 * an edit or a removal breaks every hash after it. This is not a timestamp from a trusted third
	 * party and not a qualified seal: it shows the rows have not changed since this site wrote them.
	 *
	 * ## OPTIONS
	 *
	 * [--verify]
	 * : Recompute the chain from the first row and exit 1 if it is broken.
	 *
	 * [--limit=<limit>]
	 * : Rows to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol receipts
	 *     wp peppol receipts --verify
	 */
	public function receipts( array $args, array $assoc_args ): void {
		if ( isset( $assoc_args['verify'] ) ) {
			$chain = DeliveryRegister::verify_chain();
			if ( ! $chain['valid'] ) {
				WP_CLI::error( sprintf( '%s (%d Zeilen bis dahin geprüft)', $chain['reason'], $chain['checked'] ) );
			}
			WP_CLI::success( sprintf( 'Die Kette ist unverändert: %d Zeilen geprüft.', $chain['checked'] ) );
			return;
		}

		$rows = DeliveryRegister::recent( (int) ( $assoc_args['limit'] ?? 20 ) );
		if ( [] === $rows ) {
			WP_CLI::line( 'Das Register ist leer: es wurde noch keine signierte AS4-Quittung verarbeitet.' );
			return;
		}

		WP_CLI\Utils\format_items(
			(string) ( $assoc_args['format'] ?? 'table' ),
			array_map(
				static function ( array $row ): array {
					$detail = is_array( $row['detail'] ) ? $row['detail'] : [];

					return [
						'id'         => $row['id'],
						'created_at' => $row['created_at'],
						'action'     => $row['action'],
						'invoice'    => $row['invoice_number'],
						'message_id' => $row['message_id'],
						'signature'  => ( $detail['signature_verified'] ?? false ) ? 'gültig' : 'nicht gültig',
						'payload'    => (string) ( $detail['payload_match'] ?? 'unknown' ),
						'signer'     => (string) ( $detail['signer']['subject'] ?? '' ),
						'hash'       => substr( (string) $row['current_hash'], 0, 16 ),
					];
				},
				$rows
			),
			[ 'id', 'created_at', 'action', 'invoice', 'message_id', 'signature', 'payload', 'signer', 'hash' ]
		);
	}

	/**
	 * Run the official EN 16931 rule set for UBL over an invoice file.
	 *
	 * This evaluates the business and syntax rules of the artefact the plugin ships, reports every
	 * rule that fired, and exits 1 when one of them is fatal. It is not the reference implementation
	 * and it does not run the official XSLT: a file with no finding here can still be refused by an
	 * access point. The structural checks of `wp peppol validate` stay where they are; this is the
	 * separate, wider run.
	 *
	 * ## OPTIONS
	 *
	 * [<file>]
	 * : Path to the XML invoice file. Omit it with --coverage.
	 *
	 * [--coverage]
	 * : Report which rules the shipped table holds and which of them are evaluated, and exit.
	 *
	 * [--warnings]
	 * : Also list the findings that are not fatal.
	 *
	 * [--format=<format>]
	 * : table or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol schematron /path/to/invoice.xml
	 *     wp peppol schematron --coverage
	 */
	public function schematron( array $args, array $assoc_args ): void {
		$validator = new SchematronValidator();
		$artefact  = $validator->artefact();
		$json      = 'json' === ( $assoc_args['format'] ?? 'table' );

		if ( isset( $assoc_args['coverage'] ) ) {
			$coverage = $validator->coverage();
			if ( $json ) {
				WP_CLI::line( (string) wp_json_encode( [ 'artefact' => $artefact ] + $coverage ) );
				return;
			}
			WP_CLI::line( sprintf( '%s %s (%s), %s', $artefact['name'], $artefact['version'], $artefact['released'], $artefact['licence'] ) );
			WP_CLI::line( sprintf( '%-28s %d', 'assertions in the table:', $coverage['total'] ) );
			WP_CLI::line( sprintf( '%-28s %d', 'of those evaluated:', $coverage['evaluated'] ) );
			WP_CLI::line( sprintf( '%-28s %d', 'EN 16931 business rules:', $coverage['business'] ) );
			WP_CLI::line( sprintf( '%-28s %d', 'fatal:', $coverage['fatal'] ) );
			if ( [] !== $coverage['unsupported'] ) {
				WP_CLI::line( 'not evaluated:' );
				foreach ( $coverage['unsupported'] as $rule ) {
					WP_CLI::line( sprintf( '  %-14s %s', $rule['id'], $rule['reason'] ) );
				}
			}
			return;
		}

		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			WP_CLI::error( "File not found: {$file}" );
		}

		$result = $validator->validate_xml( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named on the command line.
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( sprintf( '[%s] %s', $result->get_error_code(), $result->get_error_message() ) );
		}

		if ( $json ) {
			WP_CLI::line( (string) wp_json_encode( $result ) );
			if ( $result['fatal'] > 0 ) {
				WP_CLI::halt( 1 );
			}
			return;
		}

		WP_CLI::line( sprintf( 'Regelwerk:         EN 16931 UBL %s (%s)', $artefact['version'], $artefact['released'] ) );
		WP_CLI::line( sprintf( 'Geprüfte Regeln:   %d', $result['evaluated'] ) );

		$show_warnings = isset( $assoc_args['warnings'] );
		foreach ( $result['findings'] as $finding ) {
			if ( 'fatal' !== $finding['flag'] && ! $show_warnings ) {
				continue;
			}
			WP_CLI::line( sprintf( '[%s] %s', strtoupper( $finding['flag'] ), $finding['message'] ) );
			WP_CLI::line( sprintf( '        %s', $finding['location'] ) );
		}

		if ( [] !== $result['unevaluated'] ) {
			WP_CLI::warning( sprintf(
				/* translators: %s: comma separated rule identifiers */
				__( 'Diese Regeln konnten nicht ausgewertet werden und zählen weder als erfüllt noch als verletzt: %s', 'wm-peppol-as4-gateway' ),
				implode( ', ', $result['unevaluated'] )
			) );
		}

		if ( $result['truncated'] ) {
			WP_CLI::warning( sprintf( 'Mehr als %d Befunde; die Liste ist gekürzt.', SchematronValidator::MAX_FINDINGS ) );
		}

		if ( $result['fatal'] > 0 ) {
			WP_CLI::error( sprintf( '%d fatale und %d nicht-fatale Befunde.', $result['fatal'], $result['warning'] ) );
		}

		WP_CLI::success( sprintf(
			'Kein fataler Befund (%d Hinweise). Das ist keine Konformitätsaussage: die Prüfung deckt das mitgelieferte Regelwerk ab, nicht die Annahme durch einen Access Point.',
			$result['warning']
		) );
	}

	/**
	 * List the most recent invoices stored by the gateway.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Rows to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml. In csv, a cell that starts with = + - @ or a tab is prefixed with an apostrophe.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp peppol list-invoices --limit=5 --format=json
	 *
	 * @subcommand list-invoices
	 */
	public function list_invoices( array $args, array $assoc_args ): void {
		global $wpdb;
		$limit  = max( 1, min( 500, (int) ( $assoc_args['limit'] ?? 20 ) ) );
		$format = (string) ( $assoc_args['format'] ?? 'table' );
		$fields = [ 'id', 'invoice_number', 'buyer_participant_id', 'issue_date', 'currency_code', 'total_payable_amount', 'status', 'created_at' ];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, invoice_number, buyer_participant_id, issue_date, currency_code, total_payable_amount, status, created_at FROM %i ORDER BY id DESC LIMIT %d',
				$wpdb->prefix . 'wm_peppol_invoices',
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = is_array( $rows ) ? $rows : [];
		// Buyer ids and invoice numbers come from REST callers and orders: a spreadsheet must not run them as formulas.
		if ( 'csv' === $format ) {
			$rows = array_map( static fn( array $row ): array => array_map( static fn( $cell ): string => \WM_Peppol_Database::sanitize_csv_cell( (string) $cell ), $row ), $rows );
		}
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
