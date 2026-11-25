<?php
/**
 * Peppol BIS Billing 3.0 (UBL 2.1) structure of the generated invoice.
 *
 * The generator emits a well-formed UBL document with the elements asserted here by XPath. Well-formed is not
 * the same as accepted: an access point validates against the EN 16931 and Peppol business rules, which no test
 * here runs (no schema, no Schematron).
 *
 * The mandatory list follows the syntax pages of Peppol BIS Billing 3.0, May 2026 release, read on 2026-09-15.
 * Until then it also required seller PartyIdentification and PartyName and buyer PartyName and PartyTaxScheme,
 * all 0..n or 0..1 on those pages, and the fixture passed no party data: the generator filled the parties with
 * placeholders, so "present" was proven on invented data.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

final class En16931ConformanceTest extends TestCase {

	private DOMXPath $xpath;

	/**
	 * Parties, buyer reference and a line description; the endpoints come through the flat keys.
	 *
	 * @return array<string, mixed>
	 */
	private static function base(): array {
		return array(
			'buyer_reference'  => 'PO-4711',
			'seller_endpoint'  => '9930:DE111222333',
			'buyer_endpoint'   => '9930:DE444555666',
			'seller'           => array(
				'name'        => 'Verkäufer GmbH',
				'street'      => 'Weg 1',
				'city'        => 'Halle (Saale)',
				'postal_code' => '06108',
				'country'     => 'DE',
				'vat_id'      => 'DE111222333',
				'iban'        => 'DE89370400440532013000',
			),
			'buyer'            => array(
				'name'        => 'Käufer AG',
				'street'      => 'Platz 2',
				'city'        => 'Leipzig',
				'postal_code' => '04109',
				'country'     => 'DE',
			),
			'line_description' => 'Leistung',
		);
	}

	protected function setUp(): void {
		parent::setUp();

		$generator = new UblInvoiceGenerator();
		$xml       = $generator->generate_ubl_xml(
			array_replace(
				self::base(),
				array(
					'invoice_number' => 'INV-2026-TEST',
					'issue_date'     => '2026-08-27',
					'payable_amount' => 1250.75,
					'currency'       => 'EUR',
				)
			)
		);

		$dom = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ), 'Generator emitted XML that does not parse.' );

		$this->xpath = new DOMXPath( $dom );
		$this->xpath->registerNamespace( 'ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' );
		$this->xpath->registerNamespace( 'cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' );
		$this->xpath->registerNamespace( 'cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' );
	}

	/**
	 * Count nodes matching an XPath expression.
	 *
	 * @param string $expr XPath.
	 * @return int
	 */
	private function count_nodes( string $expr ): int {
		$nodes = $this->xpath->query( $expr );
		return false === $nodes ? 0 : $nodes->length;
	}

	// --- Header ---------------------------------------------------------------

	public function test_bt1_invoice_number_is_present(): void {
		$this->assertSame( 1, $this->count_nodes( '/ubl:Invoice/cbc:ID' ), 'BT-1 Invoice number missing.' );
	}

	public function test_bt2_issue_date_is_present(): void {
		$this->assertSame( 1, $this->count_nodes( '/ubl:Invoice/cbc:IssueDate' ), 'BT-2 Issue date missing.' );
	}

	public function test_bt3_invoice_type_code_is_present(): void {
		$this->assertSame( 1, $this->count_nodes( '/ubl:Invoice/cbc:InvoiceTypeCode' ), 'BT-3 Invoice type code missing.' );
	}

	public function test_bt5_currency_is_present(): void {
		$this->assertSame( 1, $this->count_nodes( '/ubl:Invoice/cbc:DocumentCurrencyCode' ), 'BT-5 Currency missing.' );
	}

	public function test_payable_amount_carries_its_currency_attribute(): void {
		$nodes = $this->xpath->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' );
		$this->assertNotFalse( $nodes );
		$this->assertSame( 1, $nodes->length, 'BT-115 Amount due for payment missing.' );
		$this->assertSame( 'EUR', $nodes->item( 0 )->attributes->getNamedItem( 'currencyID' )->nodeValue );
		$this->assertSame( '1250.75', $nodes->item( 0 )->nodeValue );
	}

	public function test_namespaces_are_declared_once_on_the_root(): void {
		$generator = new UblInvoiceGenerator();
		$xml       = $generator->generate_ubl_xml( array_replace( self::base(), array( 'invoice_number' => 'INV-NS', 'net_amount' => 10.0 ) ) );

		$this->assertSame(
			1,
			substr_count( $xml, 'xmlns:cbc=' ),
			'xmlns:cbc is redeclared on descendants — the subtree was built detached from the root.'
		);
		$this->assertSame( 1, substr_count( $xml, 'xmlns:cac=' ) );
	}

	public function test_endpoint_id_holds_the_identifier_and_its_scheme_attribute(): void {
		$endpoint = $this->xpath->query( '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID' )->item( 0 );
		$this->assertSame( 'DE111222333', $endpoint->nodeValue );
		$this->assertSame( '9930', $endpoint->attributes->getNamedItem( 'schemeID' )->nodeValue );
	}

	// --- Mandatory structure ----------------------------------------------------

	public function test_bg23_vat_breakdown_is_present(): void {
		$this->assertSame( 1, $this->count_nodes( '/ubl:Invoice/cac:TaxTotal' ) );
	}

	public function test_bg25_at_least_one_invoice_line_is_present(): void {
		$this->assertGreaterThanOrEqual( 1, $this->count_nodes( '/ubl:Invoice/cac:InvoiceLine' ) );
	}

	public function test_legal_monetary_total_carries_all_four_mandatory_amounts(): void {
		foreach ( array( 'LineExtensionAmount', 'TaxExclusiveAmount', 'TaxInclusiveAmount', 'PayableAmount' ) as $term ) {
			$this->assertSame( 1, $this->count_nodes( "/ubl:Invoice/cac:LegalMonetaryTotal/cbc:{$term}" ), "{$term} missing." );
		}
	}

	public function test_seller_party_carries_address_and_legal_entity(): void {
		$base = '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party';
		$this->assertSame( 1, $this->count_nodes( "{$base}/cac:PostalAddress" ) );
		$this->assertSame( 1, $this->count_nodes( "{$base}/cac:PartyLegalEntity" ) );
	}

	public function test_buyer_party_carries_address_and_legal_entity(): void {
		$base = '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party';
		$this->assertSame( 1, $this->count_nodes( "{$base}/cac:PostalAddress" ) );
		$this->assertSame( 1, $this->count_nodes( "{$base}/cac:PartyLegalEntity" ) );
	}

	/**
	 * Elements of cardinality 1..1 or 1..n on the syntax pages (May 2026 release), plus the seller VAT identifier,
	 * which BR-S-02 requires for standard-rated lines.
	 *
	 * @return string[]
	 */
	private static function mandatory_paths(): array {
		return array(
			'/ubl:Invoice/cbc:CustomizationID',
			'/ubl:Invoice/cbc:ProfileID',
			'/ubl:Invoice/cbc:ID',
			'/ubl:Invoice/cbc:IssueDate',
			'/ubl:Invoice/cbc:InvoiceTypeCode',
			'/ubl:Invoice/cbc:DocumentCurrencyCode',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cac:TaxScheme/cbc:ID',
			'/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName',
			'/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cbc:EndpointID',
			'/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress',
			'/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress/cac:Country/cbc:IdentificationCode',
			'/ubl:Invoice/cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName',
			'/ubl:Invoice/cac:PaymentMeans/cbc:PaymentMeansCode',
			'/ubl:Invoice/cac:TaxTotal',
			'/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount',
			'/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxableAmount',
			'/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cbc:TaxAmount',
			'/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:ID',
			'/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cac:TaxScheme/cbc:ID',
			'/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount',
			'/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount',
			'/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount',
			'/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount',
			'/ubl:Invoice/cac:InvoiceLine',
			'/ubl:Invoice/cac:InvoiceLine/cbc:ID',
			'/ubl:Invoice/cac:InvoiceLine/cbc:InvoicedQuantity',
			'/ubl:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount',
			'/ubl:Invoice/cac:InvoiceLine/cac:Item/cbc:Name',
			'/ubl:Invoice/cac:InvoiceLine/cac:Item/cac:ClassifiedTaxCategory/cbc:ID',
			'/ubl:Invoice/cac:InvoiceLine/cac:Item/cac:ClassifiedTaxCategory/cac:TaxScheme/cbc:ID',
			'/ubl:Invoice/cac:InvoiceLine/cac:Price/cbc:PriceAmount',
		);
	}

	public function test_all_mandatory_elements_are_present(): void {
		$missing = array();
		foreach ( self::mandatory_paths() as $path ) {
			if ( 0 === $this->count_nodes( $path ) ) {
				$missing[] = $path;
			}
		}

		$this->assertGreaterThan( 30, count( self::mandatory_paths() ), 'The mandatory list itself went empty.' );
		$this->assertSame( array(), $missing, "Mandatory elements missing:\n" . implode( "\n", $missing ) );
	}

	public function test_header_elements_appear_in_schema_sequence_order(): void {
		// UBL 2.1 declares Invoice as xs:sequence: correct elements in the wrong order are schema-invalid.
		$expected = array(
			'CustomizationID',
			'ProfileID',
			'ID',
			'IssueDate',
			'DueDate',
			'InvoiceTypeCode',
			'DocumentCurrencyCode',
			'BuyerReference',
		);

		$actual = array();
		$nodes  = $this->xpath->query( '/ubl:Invoice/cbc:*' );
		$this->assertNotFalse( $nodes );
		foreach ( $nodes as $node ) {
			$actual[] = $node->localName;
		}

		$this->assertSame( $expected, $actual, 'Header elements are out of schema sequence order.' );
	}

	public function test_aggregate_blocks_appear_in_schema_sequence_order(): void {
		$expected = array(
			'AccountingSupplierParty',
			'AccountingCustomerParty',
			'Delivery',
			'PaymentMeans',
			'TaxTotal',
			'LegalMonetaryTotal',
			'InvoiceLine',
		);

		$actual = array();
		$nodes  = $this->xpath->query( '/ubl:Invoice/cac:*' );
		$this->assertNotFalse( $nodes );
		foreach ( $nodes as $node ) {
			$actual[] = $node->localName;
		}

		$this->assertSame( $expected, $actual, 'Aggregate blocks are out of schema sequence order.' );
	}

	public function test_br_co_15_totals_balance(): void {
		$net   = (float) $this->xpath->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' )->item( 0 )->nodeValue;
		$tax   = (float) $this->xpath->query( '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' )->item( 0 )->nodeValue;
		$gross = (float) $this->xpath->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount' )->item( 0 )->nodeValue;

		// BR-CO-15: TaxInclusiveAmount = TaxExclusiveAmount + TaxTotal/TaxAmount
		$this->assertEqualsWithDelta( $gross, $net + $tax, 0.005, 'BR-CO-15 violated: totals do not balance.' );
	}

	public function test_line_totals_sum_to_the_document_line_extension_amount(): void {
		// BR-CO-10: LineExtensionAmount = sum of line LineExtensionAmounts.
		$doc_total = (float) $this->xpath->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount' )->item( 0 )->nodeValue;

		$sum   = 0.0;
		$lines = $this->xpath->query( '/ubl:Invoice/cac:InvoiceLine/cbc:LineExtensionAmount' );
		$this->assertNotFalse( $lines );
		$this->assertGreaterThan( 0, $lines->length, 'No invoice lines to sum.' );
		foreach ( $lines as $line ) {
			$sum += (float) $line->nodeValue;
		}

		$this->assertEqualsWithDelta( $doc_total, $sum, 0.005, 'BR-CO-10 violated: line sum does not match the document total.' );
	}

	public function test_multiple_lines_are_rendered_and_summed(): void {
		$xp = $this->build(
			array(
				'invoice_number' => 'INV-MULTI',
				'lines'          => array(
					array( 'name' => 'Konzeption', 'quantity' => 2, 'unit_price' => 100.00 ),
					array( 'name' => 'Umsetzung', 'quantity' => 1, 'unit_price' => 100.00 ),
				),
			)
		);

		$this->assertSame( 2, $xp->query( '/ubl:Invoice/cac:InvoiceLine' )->length );
		$this->assertSame( '2', $xp->query( '/ubl:Invoice/cac:InvoiceLine[1]/cbc:InvoicedQuantity' )->item( 0 )->nodeValue );
		$this->assertSame( '200.00', $xp->query( '/ubl:Invoice/cac:InvoiceLine[1]/cbc:LineExtensionAmount' )->item( 0 )->nodeValue );
		$this->assertSame( '300.00', $xp->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount' )->item( 0 )->nodeValue );
	}

	public function test_one_vat_breakdown_per_rate(): void {
		$xp = $this->build(
			array(
				'lines' => array(
					array( 'name' => 'A', 'quantity' => 1, 'unit_price' => 100.00, 'vat_rate' => 19 ),
					array( 'name' => 'B', 'quantity' => 1, 'unit_price' => 100.00, 'vat_rate' => 7 ),
				),
			)
		);

		$this->assertSame( 2, $xp->query( '/ubl:Invoice/cac:TaxTotal/cac:TaxSubtotal' )->length );
		$this->assertSame( '26.00', $this->text( $xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' ) );
	}

	public function test_a_gross_amount_stays_payable_and_the_remainder_is_bt114(): void {
		$xp = $this->build( array( 'payable_amount' => 0.03 ) );

		$this->assertSame( '0.03', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' ) );
		$this->assertSame( '-0.01', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableRoundingAmount' ) );
	}

	// --- BG-3, BG-13, BG-14, BG-20/21 --------------------------------------

	/**
	 * Build a document on the base fixture and return an XPath bound to it.
	 *
	 * @param array<string, mixed> $data Invoice data.
	 * @return DOMXPath
	 */
	private function build( array $data ): DOMXPath {
		$xml = ( new UblInvoiceGenerator() )->generate_ubl_xml( array_replace( self::base(), array( 'invoice_number' => 'INV-BUILD' ), $data ) );
		$dom = new DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ) );

		$xp = new DOMXPath( $dom );
		$xp->registerNamespace( 'ubl', 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' );
		$xp->registerNamespace( 'cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' );
		$xp->registerNamespace( 'cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' );

		return $xp;
	}

	/**
	 * Text of the first node matching an expression.
	 *
	 * @param DOMXPath $xp   Bound XPath.
	 * @param string   $expr Expression.
	 * @return string
	 */
	private function text( DOMXPath $xp, string $expr ): string {
		$nodes = $xp->query( $expr );
		return ( false !== $nodes && $nodes->length > 0 ) ? (string) $nodes->item( 0 )->nodeValue : '';
	}

	public function test_bg13_delivery_date_defaults_to_the_issue_date(): void {
		$this->assertSame( '2026-08-27', $this->text( $this->xpath, '/ubl:Invoice/cac:Delivery/cbc:ActualDeliveryDate' ) );
	}

	public function test_bg13_delivery_date_can_be_set_explicitly(): void {
		$xp = $this->build( array( 'net_amount' => 10.0, 'issue_date' => '2026-08-27', 'delivery_date' => '2026-08-15' ) );
		$this->assertSame( '2026-08-15', $this->text( $xp, '/ubl:Invoice/cac:Delivery/cbc:ActualDeliveryDate' ) );
	}

	public function test_bg14_invoice_period_is_emitted_when_given(): void {
		$xp = $this->build( array( 'net_amount' => 10.0, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31' ) );
		$this->assertSame( '2026-08-01', $this->text( $xp, '/ubl:Invoice/cac:InvoicePeriod/cbc:StartDate' ) );
		$this->assertSame( '2026-08-31', $this->text( $xp, '/ubl:Invoice/cac:InvoicePeriod/cbc:EndDate' ) );
	}

	public function test_bg3_corrected_invoice_references_the_invoice_it_corrects(): void {
		$xp = $this->build(
			array(
				'invoice_number'         => 'KORR-2026-0001',
				'net_amount'             => 10.0,
				'invoice_type_code'      => UblInvoiceGenerator::INVOICE_TYPE_CORRECTED,
				'preceding_invoice'      => 'INV-2026-0042',
				'preceding_invoice_date' => '2026-07-15',
				'note'                   => 'Korrektur wegen Doppelberechnung',
			)
		);

		$this->assertSame( '384', $this->text( $xp, '/ubl:Invoice/cbc:InvoiceTypeCode' ) );
		$this->assertSame( 'INV-2026-0042', $this->text( $xp, '/ubl:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID' ) );
		$this->assertSame( '2026-07-15', $this->text( $xp, '/ubl:Invoice/cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate' ) );
		$this->assertSame( 'Korrektur wegen Doppelberechnung', $this->text( $xp, '/ubl:Invoice/cbc:Note' ) );
	}

	public function test_a_credit_note_type_is_refused_in_the_invoice_syntax(): void {
		// Peppol BIS lists 381 only in UNCL1001-cn, the code list of the CreditNote syntax (rule P0100).
		$this->expectException( InvoiceDataException::class );
		( new UblInvoiceGenerator() )->generate_ubl_xml( array_replace( self::base(), array( 'invoice_number' => 'STORNO-1', 'net_amount' => 10.0, 'invoice_type_code' => UblInvoiceGenerator::INVOICE_TYPE_CREDIT_NOTE, 'preceding_invoice' => 'INV-1' ) ) );
	}

	public function test_bg3_accepts_several_preceding_invoices(): void {
		$xp = $this->build( array( 'net_amount' => 10.0, 'preceding_invoices' => array( 'INV-1', 'INV-2', 'INV-3' ) ) );
		$this->assertSame( 3, $xp->query( '/ubl:Invoice/cac:BillingReference' )->length );
	}

	public function test_bg20_discount_is_an_allowance_and_lowers_the_total(): void {
		$xp = $this->build(
			array(
				'net_amount'      => 1000.00,
				'discount_amount' => 100.00,
				'vat_rate'        => 19.0,
			)
		);

		$this->assertSame( 'false', $this->text( $xp, '/ubl:Invoice/cac:AllowanceCharge/cbc:ChargeIndicator' ) );
		$this->assertSame( '95', $this->text( $xp, '/ubl:Invoice/cac:AllowanceCharge/cbc:AllowanceChargeReasonCode' ) );
		$this->assertSame( '100.00', $this->text( $xp, '/ubl:Invoice/cac:AllowanceCharge/cbc:Amount' ) );

		// BT-106 stays the line sum; BT-107 carries the allowance; BT-109 = BT-106 - BT-107.
		$this->assertSame( '1000.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount' ) );
		$this->assertSame( '100.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount' ) );
		$this->assertSame( '900.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' ) );
		$this->assertSame( '171.00', $this->text( $xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' ) );
		$this->assertSame( '1071.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' ) );
	}

	public function test_bg21_shipping_is_a_charge_and_raises_the_total(): void {
		$xp = $this->build(
			array(
				'net_amount'      => 1000.00,
				'shipping_amount' => 50.00,
				'vat_rate'        => 19.0,
			)
		);

		$this->assertSame( 'true', $this->text( $xp, '/ubl:Invoice/cac:AllowanceCharge/cbc:ChargeIndicator' ) );
		$this->assertSame( 'SAA', $this->text( $xp, '/ubl:Invoice/cac:AllowanceCharge/cbc:AllowanceChargeReasonCode' ) );
		$this->assertSame( '50.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:ChargeTotalAmount' ) );
		$this->assertSame( '1050.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' ) );
	}

	public function test_allowance_and_charge_together_balance_bt109(): void {
		$xp = $this->build(
			array(
				'net_amount'      => 1000.00,
				'discount_amount' => 120.00,
				'shipping_amount' => 45.00,
				'vat_rate'        => 19.0,
			)
		);

		$lines      = (float) $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:LineExtensionAmount' );
		$allowances = (float) $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount' );
		$charges    = (float) $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:ChargeTotalAmount' );
		$net        = (float) $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' );

		// BT-109 = BT-106 - BT-107 + BT-108
		$this->assertEqualsWithDelta( $lines - $allowances + $charges, $net, 0.005 );
		$this->assertSame( 925.00, $net );
	}

	public function test_prepaid_amount_lowers_only_the_payable(): void {
		$xp = $this->build(
			array(
				'net_amount'     => 1000.00,
				'prepaid_amount' => 200.00,
				'vat_rate'       => 19.0,
			)
		);

		// BT-115 = BT-112 - BT-113 + BT-114
		$this->assertSame( '1190.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount' ) );
		$this->assertSame( '200.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount' ) );
		$this->assertSame( '990.00', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' ) );
	}

	public function test_optional_blocks_are_absent_when_not_requested(): void {
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:AllowanceCharge' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:AllowanceTotalAmount' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:ChargeTotalAmount' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PrepaidAmount' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableRoundingAmount' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:BillingReference' ) );
		$this->assertSame( 0, $this->count_nodes( '/ubl:Invoice/cac:InvoicePeriod' ) );
		$this->assertSame( 0, $this->count_nodes( '//cac:PartyIdentification' ) );
	}

	public function test_coupon_and_shipping_round_trip(): void {
		$xp = $this->build(
			array(
				'invoice_number'  => 'WC-2026-0001',
				'net_amount'      => 500.00,
				'discount_amount' => 50.00,
				'shipping_amount' => 9.90,
				'vat_rate'        => 19.0,
			)
		);

		$this->assertSame( 2, $xp->query( '/ubl:Invoice/cac:AllowanceCharge' )->length );
		$this->assertSame( '459.90', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' ) );
		$this->assertSame( '87.38', $this->text( $xp, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' ) );
		$this->assertSame( '547.28', $this->text( $xp, '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:PayableAmount' ) );
	}
}
