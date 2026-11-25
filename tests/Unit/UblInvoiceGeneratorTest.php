<?php
declare(strict_types=1);

namespace WMCyber\Peppol\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

final class UblInvoiceGeneratorTest extends TestCase {
	private UblInvoiceGenerator $generator;

	protected function setUp(): void {
		parent::setUp();
		$this->generator = new UblInvoiceGenerator();
	}

	public function test_generate_ubl_xml_structure(): void {
		$data = [
			'invoice_number'   => 'INV-2026-TEST',
			'issue_date'       => '2026-08-27',
			'buyer_reference'  => 'PO-4711',
			'seller_endpoint'  => '9930:DE111222333',
			'buyer_endpoint'   => '9930:DE444555666',
			'seller'           => [ 'name' => 'Verkäufer GmbH', 'street' => 'Weg 1', 'city' => 'Halle (Saale)', 'postal_code' => '06108', 'country' => 'DE', 'vat_id' => 'DE111222333', 'iban' => 'DE89370400440532013000' ],
			'buyer'            => [ 'name' => 'Käufer AG', 'country' => 'DE' ],
			'line_description' => 'Leistung',
			'payable_amount'   => 1250.75,
			'currency'         => 'EUR',
		];

		$xml = $this->generator->generate_ubl_xml( $data );

		$this->assertStringContainsString( 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2', $xml );
		$this->assertStringContainsString( UblInvoiceGenerator::CUSTOMIZATION_ID, $xml );
		$this->assertStringContainsString( UblInvoiceGenerator::PROFILE_ID, $xml );
		$this->assertStringContainsString( '<cbc:ID>INV-2026-TEST</cbc:ID>', $xml );
		$this->assertStringContainsString( '<cbc:PayableAmount currencyID="EUR">1250.75</cbc:PayableAmount>', $xml );
		$this->assertStringContainsString( '<cbc:EndpointID schemeID="9930">DE111222333</cbc:EndpointID>', $xml );
	}

	public function test_missing_parties_are_refused(): void {
		$this->expectException( InvoiceDataException::class );
		$this->generator->generate_ubl_xml( [ 'invoice_number' => 'INV-1', 'payable_amount' => 100 ] );
	}
}
