<?php
/**
 * UblInvoiceGenerator: no invented data (D15), parties per the Peppol BIS syntax pages, one VAT breakdown per rate
 * (D14), rounding carried as BT-114 (D12, D13), type codes (D35) and the payment account (BR-61).
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\UBL\InvoiceDataException;
use WMCyber\Peppol\UBL\UblInvoiceGenerator;

pp_group( 'GENERATOR 1 · missing data is named, never filled in', static function (): void {
	pp_reset();
	$problems = pp_problems( static fn() => pp_build( [ 'invoice_number' => 'X-1', 'payable_amount' => 119.00 ] ) );
	pp_assert( is_array( $problems ), 'an invoice number and an amount alone raise InvoiceDataException (was: seller "Musterstrasse 1", buyer "Kunde"/"Unbekannt"/DE000000000)' );
	foreach ( [ 'seller.endpoint', 'seller.name', 'seller.street', 'seller.city', 'seller.postal_code', 'seller.country', 'seller.vat_id', 'seller.iban', 'buyer.endpoint', 'buyer.name', 'buyer.country', 'buyer_reference', 'line.name' ] as $code ) {
		pp_assert( in_array( $code, (array) $problems, true ), "names {$code}" );
	}
	pp_assert( in_array( 'invoice_number', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'invoice_number' => '' ] ) ) ), true ), 'names a missing invoice number (was: INV-2026-001)' );
	pp_assert( in_array( 'amounts', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'lines' => [] ] ) ) ), true ), 'names missing amounts (was: a payable amount of 100.00)' );

	$code = pp_code( 'includes/UBL/UblInvoiceGenerator.php' );
	foreach ( [ 'Musterstrasse', 'Unbekannt', 'DE000000000', 'DE123456789', "'Kunde'", 'Leistung laut Vereinbarung', 'INV-2026-001', '100.00' ] as $placeholder ) {
		pp_assert( ! str_contains( $code, $placeholder ), "no placeholder {$placeholder} in the generator" );
	}

	// Every code the generator can raise has a sentence.
	preg_match_all( "/\\\$problems\\[\\] = '([a-z_.]+)'/", $code, $static );
	$codes = $static[1];
	foreach ( [ 'endpoint', 'name', 'street', 'city', 'postal_code', 'country', 'vat_id', 'endpoint_format', 'country_format', 'vat_id_prefix' ] as $field ) {
		$codes[] = "seller.{$field}";
		$codes[] = "buyer.{$field}";
	}
	$codes = array_values( array_diff( array_unique( $codes ), [ 'buyer.street', 'buyer.city', 'buyer.postal_code', 'buyer.vat_id' ] ) );
	$text  = ( new InvoiceDataException( $codes ) )->describe();
	$raw   = array_filter( $codes, static fn( string $c ): bool => str_contains( $text, $c ) );
	pp_assert( count( $codes ) > 20 && [] === $raw, 'describe() turns every problem code into a sentence (' . count( $codes ) . ' codes)' . ( $raw ? ': raw ' . implode( ', ', $raw ) : '' ) );

	pp_seller_options();
	$data = pp_invoice();
	unset( $data['seller'] );
	$doc = pp_build( $data );
	pp_assert( 'Weg 1' === pp_text( pp_xpath( $doc['xml'] ), '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cbc:StreetName' ) && '9930:DE111222333' === $doc['seller_endpoint'], 'the seller comes from the options the settings screen writes' );
} );

pp_group( 'GENERATOR 2 · parties follow the syntax pages (EndpointID, PartyIdentification, PartyTaxScheme)', static function (): void {
	pp_reset();
	$x = pp_xpath( pp_build( pp_invoice() )['xml'] );
	$s = '/ubl:Invoice/cac:AccountingSupplierParty/cac:Party';
	$b = '/ubl:Invoice/cac:AccountingCustomerParty/cac:Party';

	pp_assert( 'DE111222333' === pp_text( $x, "{$s}/cbc:EndpointID" ) && '9930' === pp_attr( $x, "{$s}/cbc:EndpointID", 'schemeID' ), 'seller EndpointID holds the identifier, its scheme is in @schemeID (was: "9930:DE111222333")' );
	pp_assert( '4000000000001' === pp_text( $x, "{$b}/cbc:EndpointID" ) && '0088' === pp_attr( $x, "{$b}/cbc:EndpointID", 'schemeID' ), 'buyer EndpointID likewise' );
	pp_assert( 0 === pp_count( $x, '//cac:PartyIdentification' ), 'no PartyIdentification unless one is given (was: scheme 9930, which is not an ICD code, BR-CL-10)' );
	pp_assert( 0 === pp_count( $x, "{$b}/cac:PartyTaxScheme" ) && 1 === pp_count( $x, "{$s}/cac:PartyTaxScheme" ), 'buyer PartyTaxScheme only with a VAT id (was: DE000000000); the seller has one' );
	pp_assert( 'Muster & Söhne GmbH' === pp_text( $x, "{$s}/cac:PartyName/cbc:Name" ), 'an ampersand in a name arrives as text' );

	$seller = pp_seller() + [ 'party_id' => '4000000000002', 'party_id_scheme' => '0088' ];
	$buyer  = pp_buyer() + [ 'vat_id' => 'DE444555666' ];
	$x2     = pp_xpath( pp_build( pp_invoice( [ 'seller' => $seller, 'buyer' => $buyer ] ) )['xml'] );
	pp_assert( '4000000000002' === pp_text( $x2, "{$s}/cac:PartyIdentification/cbc:ID" ) && '0088' === pp_attr( $x2, "{$s}/cac:PartyIdentification/cbc:ID", 'schemeID' ), 'a given party identifier is emitted with its scheme' );
	pp_assert( 'DE444555666' === pp_text( $x2, "{$b}/cac:PartyTaxScheme/cbc:CompanyID" ), 'a given buyer VAT id is emitted' );

	pp_assert( in_array( 'seller.endpoint_format', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'seller' => [ 'endpoint' => 'DE111222333' ] + pp_seller() ] ) ) ), true ), 'an endpoint without scheme is refused' );
	pp_assert( in_array( 'buyer.country_format', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'buyer' => [ 'country' => 'de' ] + pp_buyer() ] ) ) ), true ), 'a lower-case country code is refused' );
	pp_assert( in_array( 'seller.vat_id_prefix', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'seller' => [ 'vat_id' => '111222333' ] + pp_seller() ] ) ) ), true ), 'a VAT id without country prefix is refused (BR-CO-09)' );
} );

pp_group( 'GENERATOR 3 · one VAT breakdown per rate, rounding carried as BT-114', static function (): void {
	pp_reset();
	$mixed = pp_build(
		pp_invoice(
			[
				'lines' => [
					[ 'name' => 'A', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 19 ],
					[ 'name' => 'B', 'quantity' => 1, 'unit_price' => 100, 'vat_rate' => 7 ],
				],
			]
		)
	);
	$x = pp_xpath( $mixed['xml'] );
	pp_assert( 2 === pp_count( $x, '//cac:TaxTotal/cac:TaxSubtotal' ), 'lines at 19 % and 7 % give two VAT breakdowns (was: one, at the document rate)' );
	pp_assert( '26.00' === pp_text( $x, '/ubl:Invoice/cac:TaxTotal/cbc:TaxAmount' ) && '7.00' === pp_text( $x, "//cac:TaxSubtotal[cac:TaxCategory/cbc:Percent='7']/cbc:TaxAmount" ), 'tax per rate: 19.00 + 7.00 = 26.00 (was: 38.00)' );
	pp_assert( WM_Peppol_Validator::validate( $mixed['xml'] )['valid'], 'the mixed-rate document passes the checks' );

	$charge = pp_xpath( pp_build( pp_invoice( [ 'adjustments' => [ [ 'is_charge' => true, 'amount' => 10, 'reason' => 'Versand', 'vat_rate' => 7 ] ] ] ) )['xml'] );
	pp_assert( '10.00' === pp_text( $charge, "//cac:TaxSubtotal[cac:TaxCategory/cbc:Percent='7']/cbc:TaxableAmount" ) && '0.70' === pp_text( $charge, "//cac:TaxSubtotal[cac:TaxCategory/cbc:Percent='7']/cbc:TaxAmount" ), 'a charge at its own rate lands in the breakdown of that rate (BR-S-08)' );

	$wrong = [];
	for ( $cents = 1; $cents <= 2000; $cents++ ) {
		$gross  = $cents / 100;
		$totals = pp_build( pp_invoice( [ 'lines' => [], 'line_description' => 'Leistung', 'payable_amount' => $gross, 'vat_rate' => 19 ] ) )['totals'];
		if ( abs( $totals['payable'] - $gross ) >= 0.005 || abs( round( $totals['gross'] - $totals['prepaid'] + $totals['rounding'], 2 ) - $totals['payable'] ) >= 0.005 ) {
			$wrong[] = $gross;
		}
	}
	pp_assert( [] === $wrong, 'every gross amount from 0.01 to 20.00 at 19 % stays the payable amount and BR-CO-16 holds (was: e.g. 0.03 -> 0.04)' . ( $wrong ? ': ' . implode( ', ', array_slice( $wrong, 0, 5 ) ) : '' ) );

	$small = pp_build( pp_invoice( [ 'lines' => [], 'line_description' => 'Leistung', 'payable_amount' => 0.03 ] ) );
	$xs    = pp_xpath( $small['xml'] );
	pp_assert( '0.03' === pp_text( $xs, '//cac:LegalMonetaryTotal/cbc:PayableAmount' ) && '-0.01' === pp_text( $xs, '//cac:LegalMonetaryTotal/cbc:PayableRoundingAmount' ), 'gross 0.03 stays payable 0.03, the remainder -0.01 is BT-114' );
	pp_assert( WM_Peppol_Validator::validate( $small['xml'] )['valid'], 'and that document passes the checks' );

	$rounded  = pp_xpath( pp_build( pp_invoice( [ 'rounding_amount' => 0.01 ] ) )['xml'] );
	$sequence = [];
	foreach ( $rounded->query( '/ubl:Invoice/cac:LegalMonetaryTotal/cbc:*' ) as $node ) {
		$sequence[] = $node->localName;
	}
	pp_assert( '0.01' === pp_text( $rounded, '//cbc:PayableRoundingAmount' ) && '119.01' === pp_text( $rounded, '//cbc:PayableAmount' ), 'rounding_amount 0.01 is emitted as PayableRoundingAmount (was: absent while PayableAmount said 119.01)' );
	pp_assert( [ 'LineExtensionAmount', 'TaxExclusiveAmount', 'TaxInclusiveAmount', 'PayableRoundingAmount', 'PayableAmount' ] === $sequence, 'PayableRoundingAmount sits directly before PayableAmount' );

	pp_assert( in_array( 'rate_standard', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'lines' => [ [ 'name' => 'A', 'quantity' => 1, 'unit_price' => 10, 'vat_rate' => 0 ] ] ] ) ) ), true ), 'a standard-rated line at 0 % is refused (BR-S-05)' );
	pp_assert( in_array( 'amount_not_numeric', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'lines' => [ [ 'name' => 'A', 'quantity' => 'zwei', 'unit_price' => 10 ] ] ] ) ) ), true ), 'a quantity that is not a number is refused' );
} );

pp_group( 'GENERATOR 4 · type codes, currency, dates and the payment account', static function (): void {
	pp_reset();
	pp_assert( in_array( 'credit_note', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'invoice_type_code' => '381', 'preceding_invoice' => 'T-1' ] ) ) ), true ), 'type 381 is refused: Peppol BIS lists it only for the CreditNote syntax (was: emitted in an Invoice, without a check for its reference)' );
	pp_assert( in_array( 'type_code', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'invoice_type_code' => 'banana' ] ) ) ), true ), 'a type code outside UNCL1001-inv is refused' );

	$corrected = pp_xpath( pp_build( pp_invoice( [ 'invoice_type_code' => '384', 'preceding_invoice' => 'T-2026-0000', 'preceding_invoice_date' => '2026-09-01' ] ) )['xml'] );
	pp_assert( '384' === pp_text( $corrected, '/ubl:Invoice/cbc:InvoiceTypeCode' ) && 'T-2026-0000' === pp_text( $corrected, '//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID' ), 'a corrected invoice (384) carries its preceding invoice' );
	pp_assert( UblInvoiceGenerator::INVOICE_TYPE_CODES === WM_Peppol_Validator::INVOICE_TYPE_CODES, 'generator and validator use the same invoice type code list' );

	pp_assert( in_array( 'currency', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'currency' => 'eur' ] ) ) ), true ), 'currency "eur" is refused' );
	pp_assert( in_array( 'issue_date', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'issue_date' => '15.09.2026' ] ) ) ), true ), 'an issue date that is not YYYY-MM-DD is refused' );

	$no_iban = [ 'iban' => '' ] + pp_seller();
	foreach ( [ '30', '58' ] as $means ) {
		pp_assert( in_array( 'seller.iban', (array) pp_problems( static fn() => pp_build( pp_invoice( [ 'seller' => $no_iban, 'payment_means_code' => $means ] ) ) ), true ), "payment means {$means} without IBAN is refused (BR-61)" );
	}
	$cash = pp_xpath( pp_build( pp_invoice( [ 'seller' => $no_iban, 'payment_means_code' => '10' ] ) )['xml'] );
	pp_assert( '10' === pp_text( $cash, '//cac:PaymentMeans/cbc:PaymentMeansCode' ) && 0 === pp_count( $cash, '//cac:PayeeFinancialAccount' ), 'another payment means needs no IBAN and shows none' );
} );
