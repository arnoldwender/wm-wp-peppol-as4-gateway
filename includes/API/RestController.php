<?php
/**
 * REST routes of namespace wm-peppol/v1.
 *
 * Until 2026-09-15: /invoices/generate took a body that was not JSON into a TypeError, stored a
 * total_payable_amount of 100.00 next to an XML that said 595.00, answered 201 when the insert failed, and
 * filled missing parties with invented ids; /invoice-status took an array as invoice number ("Array to string
 * conversion") and answered for the demo rows; /lookup returned a fixed access point for any id. Every route
 * now declares its arguments.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\API;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WMCyber\Peppol\Core\InvoiceStore;
use WMCyber\Peppol\Discovery\SmpClient;
use WMCyber\Peppol\Validation\SchematronValidator;
use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestController {
	private const REST_NAMESPACE = 'wm-peppol/v1';

	/** Status lookups accepted per client address per hour. */
	private const MAX_LOOKUPS_PER_HOUR = 20;

	/** Default public REST requests accepted per client address per minute at the route gate. */
	private const DEFAULT_RATE_LIMIT_PER_MIN = 60;

	public function __construct(
		private readonly UblInvoiceGenerator $ubl_generator,
		private readonly SmpClient $smp_client
	) {}

	public function register_routes(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	/** Registered on rest_api_init. */
	public function routes(): void {
		$admin  = static fn(): bool => current_user_can( 'manage_options' );
		$string = static fn( int $max ): array => [
			'type'              => 'string',
			'required'          => true,
			'minLength'         => 1,
			'maxLength'         => $max,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_request_arg',
		];

		register_rest_route( self::REST_NAMESPACE, '/invoices', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_invoices' ],
			'permission_callback' => $admin,
			'args'                => [
				'limit' => [
					'type'              => 'integer',
					'default'           => 50,
					'minimum'           => 1,
					'maximum'           => 500,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'rest_sanitize_request_arg',
				],
			],
		] );

		// The body is the generator's input object (see UblInvoiceGenerator::build()); generate_invoice() checks it.
		register_rest_route( self::REST_NAMESPACE, '/invoices/generate', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'generate_invoice' ],
			'permission_callback' => $admin,
		] );

		register_rest_route( self::REST_NAMESPACE, '/lookup', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'lookup_participant' ],
			'permission_callback' => $admin,
			'args'                => [
				'participant_id' => $string( 64 ),
				'document_type'  => [ 'required' => false ] + $string( 512 ),
			],
		] );

		register_rest_route( self::REST_NAMESPACE, '/schematron', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'run_schematron' ],
			'permission_callback' => $admin,
			'args'                => [
				'xml' => [
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 1,
					// A UBL invoice with an embedded attachment is large; the limit is the point where
					// the document is no longer an invoice.
					'maxLength'         => 8388608,
					'validate_callback' => 'rest_validate_request_arg',
				],
			],
		] );

		// Public by design: the buyer of an invoice has no account here. The caller must know
		// BOTH the invoice number and the buyer's Peppol ID, and the endpoint is rate limited. It
		// reports the status that is stored — it does not assert conformance.
		register_rest_route( self::REST_NAMESPACE, '/invoice-status', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'invoice_status' ],
			'permission_callback' => [ $this, 'check_public_invoice_lookup_access' ],
			'args'                => [
				'invoice_number'       => $string( 64 ),
				'buyer_participant_id' => $string( 64 ),
			],
		] );
	}

	/**
	 * Permission callback for the public invoice status lookup route.
	 *
	 * Administrators bypass the rate limiter; unauthenticated visitors are rate-limited per minute
	 * via an HMAC-SHA256 hash of their client address (never storing raw IP addresses).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function check_public_invoice_lookup_access( WP_REST_Request $request ): bool|WP_Error {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		$limit   = max( 1, (int) apply_filters( 'wm_peppol_rest_rate_limit', self::DEFAULT_RATE_LIMIT_PER_MIN, $request ) );
		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip_hash = hash_hmac( 'sha256', $address, wp_salt( 'auth' ) );
		$bucket  = 'wm_peppol_rl_' . substr( $ip_hash, 0, 32 );
		$seen    = (int) get_transient( $bucket );

		if ( $seen >= $limit ) {
			return new WP_Error(
				'wm_peppol_rate_limited',
				__( 'Zu viele Abfragen von dieser Adresse. Bitte versuchen Sie es später erneut.', 'wm-peppol-as4-gateway' ),
				[ 'status' => 429 ]
			);
		}

		set_transient( $bucket, $seen + 1, MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Report the stored status of one invoice to the party that already knows its identifiers.
	 *
	 * Demo rows are not reported: they describe no invoice.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function invoice_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip_hash = hash_hmac( 'sha256', $address, wp_salt( 'auth' ) );
		$bucket  = 'wm_peppol_st_' . substr( $ip_hash, 0, 32 );
		$seen    = (int) get_transient( $bucket );

		if ( $seen >= self::MAX_LOOKUPS_PER_HOUR ) {
			return new WP_Error(
				'wm_peppol_rate_limited',
				__( 'Zu viele Abfragen von dieser Adresse. Bitte versuchen Sie es später erneut.', 'wm-peppol-as4-gateway' ),
				[ 'status' => 429 ]
			);
		}
		set_transient( $bucket, $seen + 1, HOUR_IN_SECONDS );

		$number   = sanitize_text_field( (string) $request->get_param( 'invoice_number' ) );
		$buyer_id = sanitize_text_field( (string) $request->get_param( 'buyer_participant_id' ) );

		if ( '' === $number || '' === $buyer_id ) {
			return new WP_Error(
				'wm_peppol_missing_params',
				__( 'Rechnungsnummer und Käufer-Peppol-ID sind erforderlich.', 'wm-peppol-as4-gateway' ),
				[ 'status' => 400 ]
			);
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$invoice = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, invoice_number, issue_date, currency_code, total_payable_amount, status, xml_hash_sha256
				 FROM %i WHERE invoice_number = %s AND buyer_participant_id = %s LIMIT 1",
				$wpdb->prefix . 'wm_peppol_invoices',
				$number,
				$buyer_id
			),
			ARRAY_A
		);

		// Demo rows describe no invoice: status demo, and the stub rows the demo button wrote before 2026-09-15 with
		// status "delivered" (InvoiceStore::demo_ids()). They answer like an unknown invoice.
		if ( null === $invoice || in_array( (int) $invoice['id'], InvoiceStore::demo_ids(), true ) ) {
			// Same shape and same code as a hit. Answering 404 here would turn the endpoint into
			// an oracle for which invoice numbers exist.
			return new WP_REST_Response(
				[
					'success' => true,
					'found'   => false,
					'message' => __( 'Zu diesen Angaben liegt keine Rechnung vor.', 'wm-peppol-as4-gateway' ),
				],
				200
			);
		}

		$transmission = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT transport_status, non_repudiation_receipt, as4_error_code, created_at FROM %i WHERE invoice_id = %d ORDER BY id DESC LIMIT 1',
				$wpdb->prefix . 'wm_peppol_transmissions',
				(int) $invoice['id']
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return new WP_REST_Response(
			[
				'success'          => true,
				'found'            => true,
				'invoice_number'   => $invoice['invoice_number'],
				'issue_date'       => $invoice['issue_date'],
				'currency_code'    => $invoice['currency_code'],
				'total_payable'    => $invoice['total_payable_amount'],
				'invoice_status'   => $invoice['status'],
				'xml_sha256'       => $invoice['xml_hash_sha256'],
				// Reported, not asserted. 'none' means no transmission has been recorded.
				'transport_status' => $transmission['transport_status'] ?? 'none',
				'as4_error_code'   => $transmission['as4_error_code'] ?? null,
				// A receipt the webhook accepted (HMAC with the shared secret); its content is not verified further.
				'has_receipt'      => ! empty( $transmission['non_repudiation_receipt'] ),
			],
			200
		);
	}

	/**
	 * The most recent invoices, `limit` rows (default 50).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_invoices( WP_REST_Request $request ): WP_REST_Response {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, invoice_number, buyer_participant_id, issue_date, currency_code, total_payable_amount, status, created_at FROM %i ORDER BY id DESC LIMIT %d',
				$wpdb->prefix . 'wm_peppol_invoices',
				max( 1, min( 500, (int) $request->get_param( 'limit' ) ) )
			),
			ARRAY_A
		);

		return new WP_REST_Response( [
			'success'  => true,
			'invoices' => is_array( $rows ) ? $rows : [],
		] );
	}

	/**
	 * Build and store one invoice from a JSON object.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function generate_invoice( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		global $wpdb;

		$params = $request->get_json_params();
		if ( ! is_array( $params ) || ( [] !== $params && array_keys( $params ) === range( 0, count( $params ) - 1 ) ) ) {
			return new WP_Error( 'wm_peppol_invalid_body', __( 'Der Anfragekörper muss ein JSON-Objekt sein.', 'wm-peppol-as4-gateway' ), [ 'status' => 400 ] );
		}

		try {
			$document = $this->ubl_generator->build( $params );
		} catch ( InvoiceDataException $e ) {
			return new WP_Error( 'wm_peppol_invoice_data', $e->describe(), [ 'status' => 400, 'problems' => $e->problems() ] );
		}

		$table = $wpdb->prefix . 'wm_peppol_invoices';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE invoice_number = %s', $table, $document['invoice_number'] ) ) ) {
			return new WP_Error( 'wm_peppol_duplicate_invoice', __( 'Eine Rechnung mit dieser Nummer ist bereits gespeichert.', 'wm-peppol-as4-gateway' ), [ 'status' => 409 ] );
		}

		$hash = hash( 'sha256', $document['xml'] );
		$now  = current_time( 'mysql', true );
		$ok   = $wpdb->insert(
			$table,
			[
				'invoice_number'        => $document['invoice_number'],
				'buyer_participant_id'  => $document['buyer_endpoint'],
				'seller_participant_id' => $document['seller_endpoint'],
				'issue_date'            => $document['issue_date'],
				'currency_code'         => $document['currency'],
				'total_payable_amount'  => $document['totals']['payable'],
				'ubl_xml'               => $document['xml'],
				'xml_hash_sha256'       => $hash,
				'status'                => 'generated',
				'created_at'            => $now,
				'updated_at'            => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s' ]
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( false === $ok ) {
			return new WP_Error( 'wm_peppol_insert_failed', __( 'Die Rechnung wurde erzeugt, aber nicht gespeichert.', 'wm-peppol-as4-gateway' ), [ 'status' => 500 ] );
		}

		$invoice_id = (int) $wpdb->insert_id;
		do_action( 'wm_peppol_invoice_recorded', $invoice_id, $document['invoice_number'], 'rest' );

		return new WP_REST_Response( [
			'success'        => true,
			'invoice_id'     => $invoice_id,
			'invoice_number' => $document['invoice_number'],
			'payable_amount' => $document['totals']['payable'],
			'currency'       => $document['currency'],
			'xml_sha256'     => $hash,
		], 201 );
	}

	/**
	 * SMP lookup: SML for the SMP, SMP for the receiving access point of a document type.
	 *
	 * Answers what the network said, or the error of the step that failed with its own code — a
	 * participant that is not registered (404) is a different answer from an SMP that did not respond
	 * (502), and both are different from an identifier that has the wrong shape (400).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function lookup_participant( WP_REST_Request $request ) {
		$result = $this->smp_client->lookup_receiver_endpoint(
			(string) $request->get_param( 'participant_id' ),
			'' !== (string) $request->get_param( 'document_type' )
				? (string) $request->get_param( 'document_type' )
				: SmpClient::DOCTYPE_INVOICE
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [ 'success' => true ] + $result, 200 );
	}

	/**
	 * Run the shipped EN 16931 rule set over a posted document.
	 *
	 * Answers 200 with the findings whether or not any fired: a document with fatal findings is a
	 * measurement, not a failed request. `fatal` is the number to act on. The answer never says the
	 * document is conformant, because this plugin does not run the reference implementation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function run_schematron( WP_REST_Request $request ) {
		$result = ( new SchematronValidator() )->validate_xml( (string) $request->get_param( 'xml' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [ 'success' => true ] + $result, 200 );
	}
}
