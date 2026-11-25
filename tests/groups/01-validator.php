<?php
/**
 * WM_Peppol_Validator: UBL Invoice and CreditNote, CII, malformed input.
 *
 * Defects: D3 (a document without totals or lines passed), D4 (every CreditNote failed), D5 (type code "banana"
 * passed), the BR-10 label on the buyer reference, the 0.02 tolerance on BR-CO-15.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

use WMCyber\Peppol\UBL\UblInvoiceGenerator;

pp_group( 'VALIDATOR 1 · missing totals, missing lines and non-numeric amounts are errors', static function (): void {
	pp_reset();
	$xml = pp_build( pp_invoice() )['xml'];
	$ok  = WM_Peppol_Validator::validate( $xml );
	pp_assert( $ok['valid'], 'a generated invoice passes' . ( $ok['valid'] ? '' : ': ' . implode( ' | ', $ok['errors'] ) ) );
	pp_assert( 'T-2026-0001' === $ok['invoice_number'] && 119.0 === $ok['metadata']['payable_amount'], 'invoice number and payable amount are read from the document' );

	$bare = pp_edit_xml(
		$xml,
		static function ( DOMXPath $x ): void {
			pp_remove( $x, '/*/cac:TaxTotal' );
			pp_remove( $x, '/*/cac:LegalMonetaryTotal' );
			pp_remove( $x, '/*/cac:InvoiceLine' );
		}
	);
	$errors = pp_errors( $bare );
	pp_assert( ! WM_Peppol_Validator::validate( $bare )['valid'], 'without TaxTotal, totals and lines the document fails (was: valid, the missing amounts became NaN)' );
	foreach ( [ '[BR-12]', '[BR-13]', '[BR-14]', '[BR-15]', '[BR-16]', '[BR-CO-18]' ] as $rule ) {
		pp_assert( str_contains( $errors, $rule ), "the missing term is reported under {$rule}" );
	}

	$nan = pp_edit_xml(
		$xml,
		static function ( DOMXPath $x ): void {
			$x->query( '/*/cac:LegalMonetaryTotal/cbc:TaxExclusiveAmount' )->item( 0 )->nodeValue = 'abc';
		}
	);
	pp_assert( str_contains( pp_errors( $nan ), 'keine Zahl' ), 'a non-numeric amount is an error' );
} );

pp_group( 'VALIDATOR 2 · the sum rules hold to the cent', static function (): void {
	pp_reset();
	$xml = pp_build( pp_invoice() )['xml']; // net 100.00, tax 19.00, payable 119.00
	$set = static fn( array $values ): string => pp_edit_xml(
		$xml,
		static function ( DOMXPath $x ) use ( $values ): void {
			foreach ( $values as $expr => $value ) {
				$x->query( $expr )->item( 0 )->nodeValue = $value;
			}
		}
	);
	$total = '/*/cac:LegalMonetaryTotal/cbc:';

	pp_assert( str_contains( pp_errors( $set( [ $total . 'TaxInclusiveAmount' => '119.01', $total . 'PayableAmount' => '119.01' ] ) ), '[BR-CO-15]' ), 'net 100.00 + tax 19.00 against gross 119.01 fails BR-CO-15 (was: accepted within 0.02)' );
	pp_assert( str_contains( pp_errors( $set( [ $total . 'PayableAmount' => '120.00' ] ) ), '[BR-CO-16]' ), 'a payable amount other than gross - prepaid + rounding fails BR-CO-16' );
	pp_assert( str_contains( pp_errors( $set( [ '/*/cac:InvoiceLine/cbc:LineExtensionAmount' => '99.00' ] ) ), '[BR-CO-10]' ), 'lines that do not sum to BT-106 fail BR-CO-10' );
	pp_assert( str_contains( pp_errors( $set( [ $total . 'TaxExclusiveAmount' => '101.00', $total . 'TaxInclusiveAmount' => '120.00', $total . 'PayableAmount' => '120.00' ] ) ), '[BR-CO-13]' ), 'BT-109 other than BT-106 - BT-107 + BT-108 fails BR-CO-13' );
	pp_assert( WM_Peppol_Validator::validate( $set( [] ) )['valid'], 'the unchanged document still passes' );
} );

pp_group( 'VALIDATOR 3 · credit notes, type codes, currency, buyer reference, addresses', static function (): void {
	pp_reset();
	$xml    = pp_build( pp_invoice() )['xml'];
	$credit = str_replace(
		[ '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"', '</Invoice>', 'cbc:InvoiceTypeCode>380<', '</cbc:InvoiceTypeCode>', 'cac:InvoiceLine>', 'cbc:InvoicedQuantity' ],
		[ '<CreditNote xmlns="urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2"', '</CreditNote>', 'cbc:CreditNoteTypeCode>381<', '</cbc:CreditNoteTypeCode>', 'cac:CreditNoteLine>', 'cbc:CreditedQuantity' ],
		$xml
	);
	pp_assert( str_contains( $credit, '<CreditNote ' ) && str_contains( $credit, '<cac:CreditNoteLine>' ), 'fixture: the invoice was turned into a CreditNote' );

	$result = WM_Peppol_Validator::validate( $credit );
	pp_assert( $result['valid'] && WM_Peppol_Validator::FORMAT_UBL_CREDIT_NOTE === $result['format'], 'a balanced CreditNote with CreditNoteTypeCode 381 passes (was: "[BR-04] ... cbc:InvoiceTypeCode fehlt")' . ( $result['valid'] ? '' : ': ' . implode( ' | ', $result['errors'] ) ) );
	pp_assert( str_contains( pp_errors( str_replace( 'CreditNoteTypeCode>381<', 'CreditNoteTypeCode>380<', $credit ) ), '[P0101]' ), 'a CreditNote with type code 380 fails P0101' );
	pp_assert( str_contains( pp_errors( str_replace( 'InvoiceTypeCode>380<', 'InvoiceTypeCode>banana<', $xml ) ), '[P0100]' ), 'type code "banana" fails P0100 (was: valid)' );
	pp_assert( str_contains( pp_errors( str_replace( 'InvoiceTypeCode>380<', 'InvoiceTypeCode>381<', $xml ) ), '[P0100]' ), 'type code 381 in an Invoice fails P0100' );

	$plain = str_replace( UblInvoiceGenerator::CUSTOMIZATION_ID, 'urn:cen.eu:en16931:2017', $xml );
	pp_assert( WM_Peppol_Validator::validate( $plain )['valid'] && str_contains( pp_errors( str_replace( 'InvoiceTypeCode>380<', 'InvoiceTypeCode>banana<', $plain ) ), '[BR-CL-01]' ), 'outside Peppol BIS, 380 passes and a type code that is not UNTDID 1001 syntax fails BR-CL-01' );
	pp_assert( str_contains( pp_errors( str_replace( '<cbc:DocumentCurrencyCode>EUR<', '<cbc:DocumentCurrencyCode>eur<', $xml ) ), '[BR-CL-04]' ), 'currency "eur" fails BR-CL-04' );

	$no_ref = pp_edit_xml( $xml, static fn( DOMXPath $x ) => pp_remove( $x, '/*/cbc:BuyerReference' ) );
	$check  = WM_Peppol_Validator::validate( $no_ref );
	pp_assert( str_contains( implode( ' ', $check['errors'] ), '[PEPPOL-EN16931-R003]' ), 'Peppol BIS without buyer or order reference fails R003' );
	pp_assert( ! str_contains( implode( ' ', array_merge( $check['errors'], $check['warnings'] ) ), '[BR-10]' ), 'the missing buyer reference is not labelled BR-10, which is the buyer postal address rule' );

	$with_order = pp_edit_xml(
		$no_ref,
		static function ( DOMXPath $x, DOMDocument $dom ): void {
			$ref = $dom->createElementNS( 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2', 'cac:OrderReference' );
			$ref->appendChild( $dom->createElementNS( 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2', 'cbc:ID', 'PO-1' ) );
			$dom->documentElement->appendChild( $ref );
		}
	);
	pp_assert( ! str_contains( pp_errors( $with_order ), 'R003' ), 'an order reference satisfies R003' );
	$xrechnung = str_replace( UblInvoiceGenerator::CUSTOMIZATION_ID, 'urn:cen.eu:en16931:2017#compliant#urn:xeinkauf.de:kosit:xrechnung_3.0', $no_ref );
	pp_assert( str_contains( pp_errors( $xrechnung ), '[BR-DE-15]' ), 'an XRechnung without buyer reference fails BR-DE-15' );

	pp_assert( str_contains( pp_errors( pp_edit_xml( $xml, static fn( DOMXPath $x ) => pp_remove( $x, '/*/cac:AccountingCustomerParty/cac:Party/cac:PostalAddress' ) ) ), '[BR-10]' ), 'a buyer without postal address fails BR-10' );
	pp_assert( str_contains( pp_errors( pp_edit_xml( $xml, static fn( DOMXPath $x ) => pp_remove( $x, '/*/cac:AccountingSupplierParty/cac:Party/cac:PostalAddress/cac:Country' ) ) ), '[BR-09]' ), 'a seller address without country fails BR-09' );
} );

pp_group( 'VALIDATOR 4 · CII and malformed input', static function (): void {
	$cii = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rsm:CrossIndustryInvoice xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"
                          xmlns:ram="urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100"
                          xmlns:udt="urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100">
  <rsm:ExchangedDocumentContext>
    <ram:GuidelineSpecifiedDocumentContextParameter><ram:ID>urn:cen.eu:en16931:2017</ram:ID></ram:GuidelineSpecifiedDocumentContextParameter>
  </rsm:ExchangedDocumentContext>
  <rsm:ExchangedDocument>
    <ram:ID>CII-2026-999</ram:ID>
    <ram:IssueDateTime><udt:DateTimeString format="102">20260828</udt:DateTimeString></ram:IssueDateTime>
  </rsm:ExchangedDocument>
  <rsm:SupplyChainTradeTransaction>
    <ram:ApplicableHeaderTradeAgreement>
      <ram:SellerTradeParty><ram:Name>Verkäufer GmbH</ram:Name></ram:SellerTradeParty>
      <ram:BuyerTradeParty><ram:Name>Käufer GmbH</ram:Name></ram:BuyerTradeParty>
    </ram:ApplicableHeaderTradeAgreement>
    <ram:ApplicableHeaderTradeSettlement>
      <ram:InvoiceCurrencyCode>EUR</ram:InvoiceCurrencyCode>
      <ram:SpecifiedTradeSettlementHeaderMonetarySummation>
        <ram:TaxBasisTotalAmount>500.00</ram:TaxBasisTotalAmount>
        <ram:TaxTotalAmount>95.00</ram:TaxTotalAmount>
        <ram:GrandTotalAmount>595.00</ram:GrandTotalAmount>
      </ram:SpecifiedTradeSettlementHeaderMonetarySummation>
    </ram:ApplicableHeaderTradeSettlement>
  </rsm:SupplyChainTradeTransaction>
</rsm:CrossIndustryInvoice>
XML;

	$ok = WM_Peppol_Validator::validate( $cii );
	pp_assert( $ok['valid'] && WM_Peppol_Validator::FORMAT_CII_INVOICE === $ok['format'] && 595.0 === $ok['metadata']['gross_amount'], 'a balanced CII invoice passes and its grand total is read' );
	pp_assert( 'EUR' === $ok['metadata']['currency'], 'the CII currency is read from the document (was: always "EUR")' );
	pp_assert( str_contains( pp_errors( str_replace( '<ram:GrandTotalAmount>595.00</ram:GrandTotalAmount>', '', $cii ) ), '[BR-14]' ), 'a CII invoice without GrandTotalAmount fails BR-14 (was: NaN, valid)' );
	pp_assert( ! WM_Peppol_Validator::validate( '<Invoice><broken' )['valid'], 'malformed XML fails' );
	pp_assert( ! WM_Peppol_Validator::validate( '' )['valid'], 'empty input fails' );
	pp_assert( ! WM_Peppol_Validator::validate( '<Order/>' )['valid'], 'an unknown document type fails' );
} );
