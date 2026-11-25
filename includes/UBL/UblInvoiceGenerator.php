<?php
/**
 * Peppol BIS Billing 3.0 (EN 16931, UBL 2.1) invoice generator.
 *
 * Element order matters: the UBL 2.1 schema declares Invoice as an xs:sequence, so a document
 * with every mandatory element present but in the wrong order is still schema-invalid and is
 * refused at the access point, not at the receiver. The build order below follows the published
 * tree at docs.peppol.eu/poacc/billing/3.0/syntax/ubl-invoice/tree/.
 *
 * Checked against Peppol BIS Billing 3.0, May 2026 release, on 2026-09-15:
 *   - syntax pages: EndpointID 1..1 holds the identifier only, its scheme goes in @schemeID (page example
 *     7300010000001 with 0088); seller PartyIdentification 0..n; buyer PartyName and PartyTaxScheme 0..1;
 *     PostalAddress 1..1 with Country 1..1; PartyLegalEntity 1..1; PaymentMeans 0..n; PayableRoundingAmount
 *     (BT-114) 0..1 directly before PayableAmount.
 *   - codelist/UNCL1001-inv: the invoice type codes below. 381 is listed only in UNCL1001-cn, the CreditNote
 *     syntax (rules P0100/P0101), which this generator does not produce.
 *   - rules/ubl-tc434: BR-CO-13, -15, -16, -17, BR-S-02, -05 to -09, BR-CO-09, BR-CL-10, BR-61.
 *   - rules/ubl-peppol: PEPPOL-EN16931-R003 "A buyer reference or purchase order reference MUST be provided."
 *
 * Until that day the generator invented what it was not given: seller "Musterstrasse 1" with VAT id
 * DE123456789, buyer "Kunde"/"Unbekannt"/DE000000000, a payable amount of 100.00, the invoice number as buyer
 * reference, one VAT breakdown at the document rate for lines of any rate, a seller PartyIdentification with
 * scheme 9930 (not an ISO 6523 ICD code, BR-CL-10) and EndpointID values that repeated the scheme. It now
 * raises InvoiceDataException naming what is missing.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\UBL;

use WMCyber\Peppol\Core\Settings;

// tests/bootstrap.php defines ABSPATH for PHPUnit, so the plain guard serves both.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a Peppol BIS Billing 3.0 UBL invoice.
 */
final class UblInvoiceGenerator {

	public const CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';
	public const PROFILE_ID       = 'urn:fdc:peppol.eu:2017:poacc:billing:01:1.0';

	/** UBL Invoice document namespace. */
	private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

	/** UBL Common Aggregate Components namespace. */
	private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

	/** UBL Common Basic Components namespace. */
	private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

	/** Commercial invoice, UNTDID 1001. */
	private const INVOICE_TYPE_COMMERCIAL = '380';

	/** Credit note, UNTDID 1001. Refused by build(): Peppol BIS lists it only for the CreditNote syntax. */
	public const INVOICE_TYPE_CREDIT_NOTE = '381';

	/** Corrected invoice, UNTDID 1001. */
	public const INVOICE_TYPE_CORRECTED = '384';

	/** Invoice type codes of codelist/UNCL1001-inv, Peppol BIS Billing 3.0 May 2026 release. */
	public const INVOICE_TYPE_CODES = [
		'71', '80', '82', '84', '102', '218', '219', '326', '331', '380', '382', '383', '384', '386',
		'388', '389', '393', '395', '553', '575', '623', '780', '817', '870', '875', '876', '877',
	];

	/** Credit transfer, UNTDID 4461. */
	private const PAYMENT_MEANS_CREDIT_TRANSFER = '30';

	/** Credit transfer codes after which BR-61 requires the payment account: 30 credit transfer, 58 SEPA credit transfer. */
	private const CREDIT_TRANSFER_CODES = [ '30', '58' ];

	private \DOMDocument $dom;

	/**
	 * Generate Peppol BIS Billing 3.0 UBL 2.1 XML invoice.
	 *
	 * @param array<string, mixed> $data Invoice data, see build().
	 * @return string UBL 2.1 XML.
	 * @throws InvoiceDataException When the data does not describe a complete invoice.
	 */
	public function generate_ubl_xml( array $data ): string {
		return $this->build( $data )['xml'];
	}

	/**
	 * Build the document and return it with the values the callers store.
	 *
	 * Required: invoice_number, buyer_reference (BT-10), a buyer (endpoint, name, country) and a seller (endpoint,
	 * name, street, city, postal_code, country, vat_id, and an IBAN for payment by credit transfer). The seller
	 * comes from the `seller` array, falling back field by field to the options of Core\Settings. Amounts come
	 * from `lines`, from `net_amount` or from a gross `payable_amount`; with a gross amount, what the per-rate tax
	 * calculation leaves over is carried as BT-114 so that BT-115 stays the amount given.
	 *
	 * @param array<string, mixed> $data Invoice data.
	 * @return array{xml: string, invoice_number: string, issue_date: string, currency: string, seller_endpoint: string, buyer_endpoint: string, totals: array<string, mixed>}
	 * @throws InvoiceDataException When the data does not describe a complete invoice.
	 */
	public function build( array $data ): array {
		$problems = [];

		$invoice_number = $this->text( $data['invoice_number'] ?? '' );
		if ( '' === $invoice_number ) {
			$problems[] = 'invoice_number';
		}

		// PEPPOL-EN16931-R003. The invoice number used to stand in; it is the seller's number, not the buyer's.
		$buyer_reference = $this->text( $data['buyer_reference'] ?? '' );
		if ( '' === $buyer_reference ) {
			$problems[] = 'buyer_reference';
		}

		$issue_date = $this->text( $data['issue_date'] ?? gmdate( 'Y-m-d' ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $issue_date ) ) {
			$problems[] = 'issue_date';
		}

		$type_code = $this->text( $data['invoice_type_code'] ?? self::INVOICE_TYPE_COMMERCIAL );
		if ( self::INVOICE_TYPE_CREDIT_NOTE === $type_code ) {
			$problems[] = 'credit_note';
		} elseif ( ! in_array( $type_code, self::INVOICE_TYPE_CODES, true ) ) {
			$problems[] = 'type_code';
		}

		$currency = $this->text( $data['currency'] ?? 'EUR' );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$problems[] = 'currency';
		}

		$seller = $this->party( $data, 'seller', $problems );
		$buyer  = $this->party( $data, 'buyer', $problems );

		$means_code = $this->text( $data['payment_means_code'] ?? self::PAYMENT_MEANS_CREDIT_TRANSFER );
		if ( in_array( $means_code, self::CREDIT_TRANSFER_CODES, true ) && '' === $seller['iban'] ) {
			$problems[] = 'seller.iban';
		}

		$vat_rate    = $this->number( $data['vat_rate'] ?? 19.0, $problems );
		$vat_cat     = $this->text( $data['vat_category'] ?? 'S' );
		$adjustments = $this->document_adjustments( $data, $vat_rate, $vat_cat, $problems );
		[ $lines, $gross_in ] = $this->derive_lines( $data, $adjustments, $vat_rate, $vat_cat, $problems );
		$totals      = $this->derive_totals( $data, $lines, $adjustments, $gross_in, $problems );

		if ( [] !== $problems ) {
			// Problem codes, never printed as they are; callers print describe(), escaped where they render it.
			throw new InvoiceDataException( $problems ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$this->dom                     = new \DOMDocument( '1.0', 'UTF-8' );
		$this->dom->formatOutput       = true;
		$this->dom->preserveWhiteSpace = false;

		$invoice = $this->dom->createElementNS( self::NS_INVOICE, 'Invoice' );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC );
		$invoice->setAttributeNS( 'http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC );
		$this->dom->appendChild( $invoice );

		// --- Header, in schema sequence order -------------------------------
		$this->cbc( $invoice, 'CustomizationID', self::CUSTOMIZATION_ID );
		$this->cbc( $invoice, 'ProfileID', self::PROFILE_ID );
		$this->cbc( $invoice, 'ID', $invoice_number );
		$this->cbc( $invoice, 'IssueDate', $issue_date );
		$this->cbc( $invoice, 'DueDate', $this->text( $data['due_date'] ?? $this->default_due_date( $issue_date, $data ) ) );
		$this->cbc( $invoice, 'InvoiceTypeCode', $type_code );
		// BT-22. Free-text note.
		if ( ! empty( $data['note'] ) ) {
			$this->cbc( $invoice, 'Note', $this->text( $data['note'] ) );
		}

		$this->cbc( $invoice, 'DocumentCurrencyCode', $currency );
		$this->cbc( $invoice, 'BuyerReference', $buyer_reference );

		// BG-14 Invoice period (BT-73/BT-74).
		if ( ! empty( $data['period_start'] ) || ! empty( $data['period_end'] ) ) {
			$period = $this->cac( $invoice, 'InvoicePeriod' );
			if ( ! empty( $data['period_start'] ) ) {
				$this->cbc( $period, 'StartDate', $this->text( $data['period_start'] ) );
			}
			if ( ! empty( $data['period_end'] ) ) {
				$this->cbc( $period, 'EndDate', $this->text( $data['period_end'] ) );
			}
		}

		// BG-3 Preceding invoice reference, e.g. for a corrected invoice (384).
		foreach ( $this->preceding_references( $data ) as $reference ) {
			$billing = $this->cac( $invoice, 'BillingReference' );
			$doc_ref = $this->cac( $billing, 'InvoiceDocumentReference' );
			$this->cbc( $doc_ref, 'ID', (string) $reference['id'] );
			if ( ! empty( $reference['issue_date'] ) ) {
				$this->cbc( $doc_ref, 'IssueDate', (string) $reference['issue_date'] );
			}
		}

		// --- Parties --------------------------------------------------------
		$this->build_party( $invoice, 'cac:AccountingSupplierParty', $seller );
		$this->build_party( $invoice, 'cac:AccountingCustomerParty', $buyer );

		// --- Delivery (BG-13) -----------------------------------------------
		// Defaults to the issue date; `delivery_date => false` suppresses it.
		if ( false !== ( $data['delivery_date'] ?? null ) ) {
			$delivery = $this->cac( $invoice, 'Delivery' );
			$this->cbc( $delivery, 'ActualDeliveryDate', $this->text( $data['delivery_date'] ?? $issue_date ) );
		}

		// --- Payment means --------------------------------------------------
		$means = $this->cac( $invoice, 'PaymentMeans' );
		$this->cbc( $means, 'PaymentMeansCode', $means_code );
		if ( '' !== $seller['iban'] ) {
			$account = $this->cac( $means, 'PayeeFinancialAccount' );
			$this->cbc( $account, 'ID', $seller['iban'] );
		}

		// --- Payment terms (BT-20) ------------------------------------------
		if ( ! empty( $data['payment_terms'] ) ) {
			$this->cbc( $this->cac( $invoice, 'PaymentTerms' ), 'Note', $this->text( $data['payment_terms'] ) );
		}

		// --- Document-level allowances and charges (BG-20 / BG-21) ----------
		foreach ( $adjustments as $adjustment ) {
			$node = $this->cac( $invoice, 'AllowanceCharge' );
			$this->cbc( $node, 'ChargeIndicator', $adjustment['is_charge'] ? 'true' : 'false' );
			if ( null !== $adjustment['reason_code'] && '' !== $adjustment['reason_code'] ) {
				$this->cbc( $node, 'AllowanceChargeReasonCode', $adjustment['reason_code'] );
			}
			$this->cbc( $node, 'AllowanceChargeReason', $adjustment['reason'] );
			$this->amount( $node, 'Amount', $adjustment['amount'], $currency );

			$adj_tax = $this->cac( $node, 'TaxCategory' );
			$this->cbc( $adj_tax, 'ID', $adjustment['vat_category'] );
			$this->cbc( $adj_tax, 'Percent', $this->num( $adjustment['vat_rate'] ) );
			$this->cbc( $this->cac( $adj_tax, 'TaxScheme' ), 'ID', 'VAT' );
		}

		// --- TaxTotal: one VAT breakdown per category and rate (BR-S-08, BR-S-09) ---
		$tax_total = $this->cac( $invoice, 'TaxTotal' );
		$this->amount( $tax_total, 'TaxAmount', $totals['tax'], $currency );
		foreach ( $totals['groups'] as $group ) {
			$subtotal = $this->cac( $tax_total, 'TaxSubtotal' );
			$this->amount( $subtotal, 'TaxableAmount', $group['taxable'], $currency );
			$this->amount( $subtotal, 'TaxAmount', $group['tax'], $currency );

			$category = $this->cac( $subtotal, 'TaxCategory' );
			$this->cbc( $category, 'ID', $group['category'] );
			$this->cbc( $category, 'Percent', $this->num( $group['rate'] ) );
			$this->cbc( $this->cac( $category, 'TaxScheme' ), 'ID', 'VAT' );
		}

		// --- LegalMonetaryTotal ---------------------------------------------
		// Sequence: LineExtension, TaxExclusive, TaxInclusive, AllowanceTotal, ChargeTotal,
		// Prepaid, PayableRounding, Payable.
		$monetary = $this->cac( $invoice, 'LegalMonetaryTotal' );
		$this->amount( $monetary, 'LineExtensionAmount', $totals['lines'], $currency );   // BT-106
		$this->amount( $monetary, 'TaxExclusiveAmount', $totals['net'], $currency );      // BT-109
		$this->amount( $monetary, 'TaxInclusiveAmount', $totals['gross'], $currency );    // BT-112
		if ( $totals['allowances'] > 0.0 ) {
			$this->amount( $monetary, 'AllowanceTotalAmount', $totals['allowances'], $currency ); // BT-107
		}
		if ( $totals['charges'] > 0.0 ) {
			$this->amount( $monetary, 'ChargeTotalAmount', $totals['charges'], $currency );       // BT-108
		}
		if ( $totals['prepaid'] > 0.0 ) {
			$this->amount( $monetary, 'PrepaidAmount', $totals['prepaid'], $currency );           // BT-113
		}
		if ( abs( $totals['rounding'] ) >= 0.005 ) {
			$this->amount( $monetary, 'PayableRoundingAmount', $totals['rounding'], $currency );  // BT-114
		}
		$this->amount( $monetary, 'PayableAmount', $totals['payable'], $currency );       // BT-115

		// --- InvoiceLine ----------------------------------------------------
		foreach ( $lines as $line ) {
			$this->build_line( $invoice, $line, $currency );
		}

		$result = [
			'xml'             => (string) $this->dom->saveXML(),
			'invoice_number'  => $invoice_number,
			'issue_date'      => $issue_date,
			'currency'        => $currency,
			'seller_endpoint' => $seller['endpoint'],
			'buyer_endpoint'  => $buyer['endpoint'],
			'totals'          => $totals,
		];

		if ( function_exists( 'apply_filters' ) ) {
			/**
			 * Filter the built Peppol BIS Billing 3.0 UBL 2.1 document array.
			 *
			 * @param array<string, mixed> $result Built document array (`xml`, `invoice_number`, `totals`, ...).
			 * @param array<string, mixed> $data   Input invoice data.
			 */
			$filtered = apply_filters( 'wm_peppol_ubl_document', $result, $data );
			if ( is_array( $filtered ) && isset( $filtered['xml'] ) && is_string( $filtered['xml'] ) ) {
				$result = $filtered;
			}
		}

		return $result;
	}

	// -----------------------------------------------------------------------
	// Builders
	// -----------------------------------------------------------------------

	/**
	 * Build a supplier or customer party in schema sequence order.
	 *
	 * @param \DOMElement          $parent  Invoice element.
	 * @param string               $wrapper cac:AccountingSupplierParty or cac:AccountingCustomerParty.
	 * @param array<string, string> $party  Party data from party().
	 * @return void
	 */
	private function build_party( \DOMElement $parent, string $wrapper, array $party ): void {
		$wrap = $parent->appendChild( $this->dom->createElementNS( self::NS_CAC, $wrapper ) );
		$node = $this->cac( $wrap, 'Party' );

		// cbc:EndpointID — the identifier, with its EAS scheme in @schemeID.
		$endpoint = $this->cbc( $node, 'EndpointID', $party['endpoint_id'] );
		$endpoint->setAttribute( 'schemeID', $party['endpoint_scheme'] );

		// cac:PartyIdentification — only when the caller names an identifier (0..n; its scheme is an ICD code, BR-CL-10).
		if ( '' !== $party['party_id'] ) {
			$id = $this->cbc( $this->cac( $node, 'PartyIdentification' ), 'ID', $party['party_id'] );
			if ( '' !== $party['party_id_scheme'] ) {
				$id->setAttribute( 'schemeID', $party['party_id_scheme'] );
			}
		}

		// cac:PartyName
		$this->cbc( $this->cac( $node, 'PartyName' ), 'Name', $party['name'] );

		// cac:PostalAddress — StreetName, CityName, PostalZone, Country in this order.
		$address = $this->cac( $node, 'PostalAddress' );
		foreach ( [ 'street' => 'StreetName', 'city' => 'CityName', 'postal_code' => 'PostalZone' ] as $field => $element ) {
			if ( '' !== $party[ $field ] ) {
				$this->cbc( $address, $element, $party[ $field ] );
			}
		}
		$this->cbc( $this->cac( $address, 'Country' ), 'IdentificationCode', $party['country'] );

		// cac:PartyTaxScheme — only with a VAT identifier.
		if ( '' !== $party['vat_id'] ) {
			$tax_scheme = $this->cac( $node, 'PartyTaxScheme' );
			$this->cbc( $tax_scheme, 'CompanyID', $party['vat_id'] );
			$this->cbc( $this->cac( $tax_scheme, 'TaxScheme' ), 'ID', 'VAT' );
		}

		// cac:PartyLegalEntity
		$legal = $this->cac( $node, 'PartyLegalEntity' );
		$this->cbc( $legal, 'RegistrationName', $party['legal_name'] );
		if ( '' !== $party['company_id'] ) {
			$this->cbc( $legal, 'CompanyID', $party['company_id'] );
		}
	}

	/**
	 * Build one cac:InvoiceLine in schema sequence order.
	 *
	 * @param \DOMElement          $parent   Invoice element.
	 * @param array<string, mixed> $line     Line data.
	 * @param string               $currency Document currency.
	 * @return void
	 */
	private function build_line( \DOMElement $parent, array $line, string $currency ): void {
		$node = $this->cac( $parent, 'InvoiceLine' );

		$this->cbc( $node, 'ID', (string) $line['id'] );

		$quantity = $this->cbc( $node, 'InvoicedQuantity', $this->num( (float) $line['quantity'] ) );
		// UN/ECE Recommendation 20. C62 is "one" — the unit for a service or a countable item.
		$quantity->setAttribute( 'unitCode', (string) $line['unit_code'] );

		$this->amount( $node, 'LineExtensionAmount', (float) $line['net'], $currency );

		$item = $this->cac( $node, 'Item' );
		$this->cbc( $item, 'Name', (string) $line['name'] );

		$item_tax = $this->cac( $item, 'ClassifiedTaxCategory' );
		$this->cbc( $item_tax, 'ID', (string) $line['vat_category'] );
		$this->cbc( $item_tax, 'Percent', $this->num( (float) $line['vat_rate'] ) );
		$this->cbc( $this->cac( $item_tax, 'TaxScheme' ), 'ID', 'VAT' );

		$price = $this->cac( $node, 'Price' );
		$this->amount( $price, 'PriceAmount', (float) $line['unit_price'], $currency );
	}

	// -----------------------------------------------------------------------
	// Derivation
	// -----------------------------------------------------------------------

	/**
	 * Seller or buyer: supplied array, then the flat `<role>_endpoint`, then (seller only) the stored settings.
	 *
	 * @param array<string, mixed> $data     Invoice data.
	 * @param string               $role     'seller' or 'buyer'.
	 * @param string[]             $problems Collected problem codes.
	 * @return array<string, string>
	 */
	private function party( array $data, string $role, array &$problems ): array {
		$supplied = isset( $data[ $role ] ) && is_array( $data[ $role ] ) ? $data[ $role ] : [];
		$stored   = 'seller' === $role ? Settings::seller() : [];
		$flat     = $this->text( $data[ $role . '_endpoint' ] ?? '' );

		$party = [];
		foreach ( [ 'endpoint', 'name', 'legal_name', 'street', 'city', 'postal_code', 'country', 'vat_id', 'company_id', 'iban', 'party_id', 'party_id_scheme' ] as $field ) {
			$value = $this->text( $supplied[ $field ] ?? '' );
			if ( '' === $value && 'endpoint' === $field ) {
				$value = $flat;
			}
			if ( '' === $value ) {
				$value = $stored[ $field ] ?? '';
			}
			$party[ $field ] = $value;
		}
		if ( '' === $party['legal_name'] ) {
			$party['legal_name'] = $party['name'];
		}

		$required = 'seller' === $role ? [ 'endpoint', 'name', 'street', 'city', 'postal_code', 'country', 'vat_id' ] : [ 'endpoint', 'name', 'country' ];
		foreach ( $required as $field ) {
			if ( '' === $party[ $field ] ) {
				$problems[] = "{$role}.{$field}";
			}
		}
		// A Peppol participant id is <EAS scheme>:<identifier>; the EAS codes are four digits (9930, 0204, 0088).
		if ( '' !== $party['endpoint'] && ! preg_match( '/^\d{4}:\S+$/', $party['endpoint'] ) ) {
			$problems[] = "{$role}.endpoint_format";
		}
		if ( '' !== $party['country'] && ! preg_match( '/^[A-Z]{2}$/', $party['country'] ) ) {
			$problems[] = "{$role}.country_format";
		}
		// BR-CO-09: a VAT identifier starts with the ISO 3166-1 alpha-2 code of its country (EL for Greece).
		if ( '' !== $party['vat_id'] && ! preg_match( '/^[A-Z]{2}/', $party['vat_id'] ) ) {
			$problems[] = "{$role}.vat_id_prefix";
		}

		$parts                    = explode( ':', $party['endpoint'], 2 );
		$party['endpoint_scheme'] = $parts[0];
		$party['endpoint_id']     = $parts[1] ?? '';

		return $party;
	}

	/**
	 * Invoice lines, or one line derived from net_amount or a gross payable_amount.
	 *
	 * @param array<string, mixed>             $data        Invoice data.
	 * @param array<int, array<string, mixed>> $adjustments Normalised adjustments.
	 * @param float                            $vat_rate    Document VAT rate.
	 * @param string                           $vat_cat     Document VAT category.
	 * @param string[]                         $problems    Collected problem codes.
	 * @return array{0: array<int, array<string, mixed>>, 1: float|null} Lines, and the gross amount when one was given.
	 */
	private function derive_lines( array $data, array $adjustments, float $vat_rate, string $vat_cat, array &$problems ): array {
		if ( ! empty( $data['lines'] ) && is_array( $data['lines'] ) ) {
			$lines = [];
			foreach ( array_values( $data['lines'] ) as $i => $raw ) {
				$raw = is_array( $raw ) ? $raw : [];
				$qty = $this->number( $raw['quantity'] ?? 1, $problems );
				$net = isset( $raw['net'] ) ? $this->number( $raw['net'], $problems ) : $this->number( $raw['unit_price'] ?? 0, $problems ) * $qty;
				$lines[] = $this->line(
					(string) ( $raw['id'] ?? $i + 1 ),
					$this->text( $raw['name'] ?? '' ),
					$qty,
					$this->text( $raw['unit_code'] ?? 'C62' ),
					$net,
					isset( $raw['vat_rate'] ) ? $this->number( $raw['vat_rate'], $problems ) : $vat_rate,
					$this->text( $raw['vat_category'] ?? $vat_cat ),
					$problems
				);
			}
			return [ $lines, null ];
		}

		$name = $this->text( $data['line_description'] ?? '' );

		if ( isset( $data['net_amount'] ) ) {
			$net = $this->number( $data['net_amount'], $problems );
			return [ [ $this->line( '1', $name, 1.0, 'C62', $net, $vat_rate, $vat_cat, $problems ) ], null ];
		}

		if ( isset( $data['payable_amount'] ) ) {
			// A gross total, as WooCommerce get_total() gives it. The line sum is worked back through the adjustments;
			// the remainder the tax calculation leaves is carried as BT-114 in derive_totals().
			$gross_in   = round( $this->number( $data['payable_amount'], $problems ), 2 );
			$allowances = 0.0;
			$charges    = 0.0;
			foreach ( $adjustments as $adjustment ) {
				if ( $adjustment['is_charge'] ) {
					$charges += $adjustment['amount'];
				} else {
					$allowances += $adjustment['amount'];
				}
			}
			$net_in = $vat_rate > -100.0 ? round( $gross_in / ( 1 + $vat_rate / 100 ), 2 ) : 0.0;
			return [ [ $this->line( '1', $name, 1.0, 'C62', $net_in + $allowances - $charges, $vat_rate, $vat_cat, $problems ) ], $gross_in ];
		}

		$problems[] = 'amounts';
		return [ [], null ];
	}

	/**
	 * One normalised line.
	 *
	 * @param string   $id       Line id.
	 * @param string   $name     Item name (BR-25).
	 * @param float    $qty      Quantity.
	 * @param string   $unit     Unit code.
	 * @param float    $net      Line net amount.
	 * @param float    $rate     VAT rate.
	 * @param string   $category VAT category.
	 * @param string[] $problems Collected problem codes.
	 * @return array<string, mixed>
	 */
	private function line( string $id, string $name, float $qty, string $unit, float $net, float $rate, string $category, array &$problems ): array {
		if ( '' === $name ) {
			$problems[] = 'line.name';
		}
		return [
			'id'           => $id,
			'name'         => $name,
			'quantity'     => $qty,
			'unit_code'    => '' !== $unit ? $unit : 'C62',
			'net'          => round( $net, 2 ),
			'unit_price'   => round( $qty > 0 ? $net / $qty : $net, 2 ),
			'vat_rate'     => $rate,
			'vat_category' => '' !== $category ? $category : 'S',
		];
	}

	/**
	 * Totals and the VAT breakdown.
	 *
	 * BR-CO-13: BT-109 = BT-106 - BT-107 + BT-108. BR-S-08: taxable amount per category and rate = its line nets
	 * + charges - allowances. BR-CO-17 / BR-S-09: tax = taxable x rate / 100. BR-CO-15: BT-112 = BT-109 + BT-110.
	 * BR-CO-16: BT-115 = BT-112 - BT-113 + BT-114.
	 *
	 * @param array<string, mixed>             $data        Invoice data.
	 * @param array<int, array<string, mixed>> $lines       Normalised lines.
	 * @param array<int, array<string, mixed>> $adjustments Normalised adjustments.
	 * @param float|null                       $gross_in    Gross amount given instead of lines, if any.
	 * @param string[]                         $problems    Collected problem codes.
	 * @return array<string, mixed>
	 */
	private function derive_totals( array $data, array $lines, array $adjustments, ?float $gross_in, array &$problems ): array {
		$groups = [];
		$add    = static function ( string $category, float $rate, float $amount ) use ( &$groups ): void {
			$key = $category . '|' . number_format( $rate, 2, '.', '' );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [ 'category' => $category, 'rate' => $rate, 'taxable' => 0.0, 'tax' => 0.0 ];
			}
			$groups[ $key ]['taxable'] += $amount;
		};

		$lines_total = 0.0;
		foreach ( $lines as $line ) {
			$lines_total += $line['net'];
			$add( $line['vat_category'], $line['vat_rate'], $line['net'] );
		}

		$allowances = 0.0;
		$charges    = 0.0;
		foreach ( $adjustments as $adjustment ) {
			if ( $adjustment['is_charge'] ) {
				$charges += $adjustment['amount'];
				$add( $adjustment['vat_category'], $adjustment['vat_rate'], $adjustment['amount'] );
			} else {
				$allowances += $adjustment['amount'];
				$add( $adjustment['vat_category'], $adjustment['vat_rate'], -$adjustment['amount'] );
			}
		}

		$tax = 0.0;
		foreach ( $groups as $key => $group ) {
			if ( $group['rate'] < 0.0 ) {
				$problems[] = 'rate_negative';
			}
			// BR-S-05, BR-S-06, BR-S-07: a standard-rated line, allowance or charge has a rate above zero.
			if ( 'S' === $group['category'] && $group['rate'] <= 0.0 ) {
				$problems[] = 'rate_standard';
			}
			$groups[ $key ]['taxable'] = round( $group['taxable'], 2 );
			$groups[ $key ]['tax']     = round( $groups[ $key ]['taxable'] * $group['rate'] / 100, 2 );
			$tax                      += $groups[ $key ]['tax'];
		}

		$lines_total = round( $lines_total, 2 );
		$allowances  = round( $allowances, 2 );
		$charges     = round( $charges, 2 );
		$net         = round( $lines_total - $allowances + $charges, 2 );
		$tax         = round( $tax, 2 );
		$gross       = round( $net + $tax, 2 );
		$prepaid     = round( $this->number( $data['prepaid_amount'] ?? 0.0, $problems ), 2 );
		$rounding    = round( $this->number( $data['rounding_amount'] ?? 0.0, $problems ), 2 );
		if ( null !== $gross_in ) {
			$rounding = round( $rounding + $gross_in - $gross, 2 );
		}
		$payable = round( $gross - $prepaid + $rounding, 2 );

		return [
			'lines'      => $lines_total,
			'allowances' => $allowances,
			'charges'    => $charges,
			'net'        => $net,
			'tax'        => $tax,
			'gross'      => $gross,
			'prepaid'    => $prepaid,
			'rounding'   => $rounding,
			'payable'    => $payable,
			'groups'     => array_values( $groups ),
		];
	}

	/**
	 * Normalise document-level allowances and charges.
	 *
	 * Accepts an explicit `adjustments` list and the two shapes an order produces: a discount and a shipping
	 * charge. Each takes the document VAT category and rate unless it names its own.
	 *
	 * @param array<string, mixed> $data     Invoice data.
	 * @param float                $vat_rate Document VAT rate.
	 * @param string               $vat_cat  Document VAT category.
	 * @param string[]             $problems Collected problem codes.
	 * @return array<int, array<string, mixed>>
	 */
	private function document_adjustments( array $data, float $vat_rate, string $vat_cat, array &$problems ): array {
		$out = [];

		if ( ! empty( $data['adjustments'] ) && is_array( $data['adjustments'] ) ) {
			foreach ( $data['adjustments'] as $raw ) {
				$raw       = is_array( $raw ) ? $raw : [];
				$is_charge = (bool) ( $raw['is_charge'] ?? false );
				$out[]     = [
					'is_charge'    => $is_charge,
					'amount'       => round( $this->number( $raw['amount'] ?? 0, $problems ), 2 ),
					'reason'       => $this->text( $raw['reason'] ?? ( $is_charge ? 'Zuschlag' : 'Nachlass' ) ),
					'reason_code'  => isset( $raw['reason_code'] ) ? $this->text( $raw['reason_code'] ) : null,
					'vat_rate'     => isset( $raw['vat_rate'] ) ? $this->number( $raw['vat_rate'], $problems ) : $vat_rate,
					'vat_category' => $this->text( $raw['vat_category'] ?? $vat_cat ),
				];
			}
		}

		// UNTDID 5189 code 95 = discount; UNTDID 7161 code SAA = shipping and handling.
		if ( ! empty( $data['discount_amount'] ) ) {
			$out[] = [
				'is_charge'    => false,
				'amount'       => round( $this->number( $data['discount_amount'], $problems ), 2 ),
				'reason'       => $this->text( $data['discount_reason'] ?? 'Rabatt' ),
				'reason_code'  => '95',
				'vat_rate'     => $vat_rate,
				'vat_category' => $vat_cat,
			];
		}
		if ( ! empty( $data['shipping_amount'] ) ) {
			$out[] = [
				'is_charge'    => true,
				'amount'       => round( $this->number( $data['shipping_amount'], $problems ), 2 ),
				'reason'       => $this->text( $data['shipping_reason'] ?? 'Versandkosten' ),
				'reason_code'  => 'SAA',
				'vat_rate'     => $vat_rate,
				'vat_category' => $vat_cat,
			];
		}

		return $out;
	}

	/**
	 * Preceding invoice references (BG-3).
	 *
	 * @param array<string, mixed> $data Invoice data.
	 * @return array<int, array<string, mixed>>
	 */
	private function preceding_references( array $data ): array {
		$refs = [];

		if ( ! empty( $data['preceding_invoices'] ) && is_array( $data['preceding_invoices'] ) ) {
			foreach ( $data['preceding_invoices'] as $raw ) {
				if ( is_string( $raw ) ) {
					$refs[] = [ 'id' => $raw ];
					continue;
				}
				$refs[] = [
					'id'         => (string) ( $raw['id'] ?? '' ),
					'issue_date' => $raw['issue_date'] ?? null,
				];
			}
		} elseif ( ! empty( $data['preceding_invoice'] ) ) {
			$refs[] = [
				'id'         => (string) $data['preceding_invoice'],
				'issue_date' => $data['preceding_invoice_date'] ?? null,
			];
		}

		return array_values( array_filter( $refs, static fn( $r ) => '' !== (string) $r['id'] ) );
	}

	/**
	 * Default payment term: issue date plus `payment_terms_days` (14 unless given).
	 *
	 * @param string               $issue Issue date.
	 * @param array<string, mixed> $data  Invoice data.
	 * @return string
	 */
	private function default_due_date( string $issue, array $data ): string {
		$days = (int) ( $data['payment_terms_days'] ?? 14 );
		$ts   = strtotime( $issue . ' +' . $days . ' days' );

		return false === $ts ? $issue : gmdate( 'Y-m-d', $ts );
	}

	// -----------------------------------------------------------------------
	// Value helpers
	// -----------------------------------------------------------------------

	/** A scalar as trimmed text; anything else as ''. */
	private function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * A finite number, or 0.0 and the problem code amount_not_numeric.
	 *
	 * @param mixed    $value    Raw value.
	 * @param string[] $problems Collected problem codes.
	 * @return float
	 */
	private function number( mixed $value, array &$problems ): float {
		if ( is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( trim( $value ) ) ) ) {
			$float = (float) ( is_string( $value ) ? trim( $value ) : $value );
			if ( is_finite( $float ) ) {
				return $float;
			}
		}
		$problems[] = 'amount_not_numeric';
		return 0.0;
	}

	// -----------------------------------------------------------------------
	// DOM helpers
	// -----------------------------------------------------------------------

	/**
	 * Append a cac: element and return it.
	 *
	 * @param \DOMElement $parent Parent.
	 * @param string      $name   Local name without prefix.
	 * @return \DOMElement
	 */
	private function cac( \DOMElement $parent, string $name ): \DOMElement {
		/** @var \DOMElement $el */
		$el = $parent->appendChild( $this->dom->createElementNS( self::NS_CAC, 'cac:' . $name ) );
		return $el;
	}

	/**
	 * Append a cbc: element with text content and return it.
	 *
	 * @param \DOMElement $parent Parent.
	 * @param string      $name   Local name without prefix.
	 * @param string      $value  Text content.
	 * @return \DOMElement
	 */
	private function cbc( \DOMElement $parent, string $name, string $value ): \DOMElement {
		/** @var \DOMElement $el */
		$el = $parent->appendChild( $this->dom->createElementNS( self::NS_CBC, 'cbc:' . $name, htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) ) );
		return $el;
	}

	/**
	 * Append a monetary cbc: element carrying its mandatory currencyID attribute.
	 *
	 * @param \DOMElement $parent   Parent.
	 * @param string      $name     Local name without prefix.
	 * @param float       $value    Amount.
	 * @param string      $currency ISO 4217 code.
	 * @return \DOMElement
	 */
	private function amount( \DOMElement $parent, string $name, float $value, string $currency ): \DOMElement {
		$el = $this->cbc( $parent, $name, number_format( $value, 2, '.', '' ) );
		$el->setAttribute( 'currencyID', $currency );
		return $el;
	}

	/**
	 * Format a decimal without thousands separators.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private function num( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', '' ), '0' ), '.' ) ?: '0';
	}
}
