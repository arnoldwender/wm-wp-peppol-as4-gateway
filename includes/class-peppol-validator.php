<?php
/**
 * WM Peppol AS4 Gateway — structural checks of an electronic invoice.
 *
 * What this is: a set of XPath checks on a UBL 2.1 Invoice or CreditNote and a smaller set on a UN/CEFACT CII
 * invoice, each labelled with the rule it follows. What this is not: a UBL schema validation or a Schematron
 * run of EN 16931, Peppol BIS Billing 3.0 or XRechnung. A document that passes here can still be refused by an
 * access point or by the KoSIT validator.
 *
 * Rule texts and code lists read on 2026-09-15 at docs.peppol.eu/poacc/billing/3.0/ (May 2026 release):
 * rules/ubl-tc434 (BR-01 to BR-16, BR-CO-10, -13, -15, -16, -18, BR-CL-01, BR-CL-04), rules/ubl-peppol
 * (PEPPOL-EN16931-R003, -R004, P0100, P0101), codelist/UNCL1001-inv and UNCL1001-cn.
 *
 * Until that day a document without TaxTotal, totals or lines was reported valid (a missing amount became NaN
 * and every comparison with NaN is false), every CreditNote failed because the check looked for
 * InvoiceTypeCode, type code "banana" and currency "ZZZ" passed, and a missing BuyerReference carried the label
 * BR-10, which is the buyer postal address rule.
 *
 * @package WM_Peppol_AS4_Gateway
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WM_Peppol_Validator {

	/**
	 * Recognized e-invoice syntax formats.
	 */
	public const FORMAT_UBL_INVOICE     = 'UBL_INVOICE';
	public const FORMAT_UBL_CREDIT_NOTE = 'UBL_CREDIT_NOTE';
	public const FORMAT_CII_INVOICE     = 'CII_INVOICE';
	public const FORMAT_UNKNOWN         = 'UNKNOWN';

	/** Specification identifier of Peppol BIS Billing 3.0 (PEPPOL-EN16931-R004). */
	public const PEPPOL_BIS = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0';

	/** codelist/UNCL1001-inv (rule P0100). */
	public const INVOICE_TYPE_CODES = [
		'71', '80', '82', '84', '102', '218', '219', '326', '331', '380', '382', '383', '384', '386',
		'388', '389', '393', '395', '553', '575', '623', '780', '817', '870', '875', '876', '877',
	];

	/** codelist/UNCL1001-cn (rule P0101). */
	public const CREDIT_NOTE_TYPE_CODES = [ '81', '83', '381', '396', '532' ];

	/** Amounts equal when they differ by less than half a cent. */
	private const CENT = 0.005;

	/**
	 * Check an XML string.
	 *
	 * @param string $xml_content Raw XML string.
	 * @return array{valid: bool, format: string, profile: string, invoice_number: string, errors: array<string>, warnings: array<string>, metadata: array}
	 */
	public static function validate( string $xml_content ): array {
		$result = [
			'valid'          => false,
			'format'         => self::FORMAT_UNKNOWN,
			'profile'        => '',
			'invoice_number' => '',
			'errors'         => [],
			'warnings'       => [],
			'metadata'       => [],
		];

		if ( '' === trim( $xml_content ) ) {
			$result['errors'][] = 'Das übergebene XML-Dokument ist leer.';
			return $result;
		}

		$prev_use_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();

		$dom                     = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		// LIBXML_NONET: no external entity or DTD is fetched.
		$loaded = @$dom->loadXML( $xml_content, LIBXML_NONET | LIBXML_NOBLANKS );

		if ( ! $loaded ) {
			foreach ( libxml_get_errors() as $err ) {
				$result['errors'][] = 'XML-Syntaxfehler [Zeile ' . $err->line . ']: ' . trim( $err->message );
			}
			libxml_clear_errors();
			libxml_use_internal_errors( $prev_use_errors );
			return $result;
		}

		libxml_clear_errors();
		libxml_use_internal_errors( $prev_use_errors );

		$root = $dom->documentElement;
		if ( ! $root ) {
			$result['errors'][] = 'Kein Wurzelelement im XML-Dokument gefunden.';
			return $result;
		}

		$root_name = $root->localName;
		$ns        = $root->namespaceURI ?? '';

		if ( 'Invoice' === $root_name && 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2' === $ns ) {
			$result['format'] = self::FORMAT_UBL_INVOICE;
			self::validate_ubl( $dom, $result, false );
		} elseif ( 'CreditNote' === $root_name && 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2' === $ns ) {
			$result['format'] = self::FORMAT_UBL_CREDIT_NOTE;
			self::validate_ubl( $dom, $result, true );
		} elseif ( 'CrossIndustryInvoice' === $root_name || str_contains( $ns, 'CrossIndustryInvoice' ) ) {
			$result['format'] = self::FORMAT_CII_INVOICE;
			self::validate_cii( $dom, $result );
		} else {
			$result['errors'][] = 'Unbekanntes oder nicht unterstütztes E-Rechnungsformat: <' . $root_name . '> in Namespace ' . $ns;
			return $result;
		}

		$result['valid'] = [] === $result['errors'];
		return $result;
	}

	/**
	 * Checks of a UBL 2.1 Invoice or CreditNote.
	 *
	 * @param DOMDocument          $dom         Parsed document.
	 * @param array<string, mixed> $result      Result, extended in place.
	 * @param bool                 $credit_note True for a CreditNote root.
	 */
	private static function validate_ubl( DOMDocument $dom, array &$result, bool $credit_note ): void {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2' );
		$xpath->registerNamespace( 'cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2' );
		$text = static fn( string $expr ): string => trim( (string) $xpath->evaluate( "string({$expr})" ) );

		// BR-01
		$result['profile'] = $text( '/*/cbc:CustomizationID' );
		if ( '' === $result['profile'] ) {
			$result['errors'][] = '[BR-01] Spezifikationskennung (BT-24, cbc:CustomizationID) fehlt.';
		}
		$peppol = str_starts_with( $result['profile'], self::PEPPOL_BIS );

		// BR-02
		$result['invoice_number'] = $text( '/*/cbc:ID' );
		if ( '' === $result['invoice_number'] ) {
			$result['errors'][] = '[BR-02] Rechnungsnummer (BT-1, cbc:ID) fehlt.';
		}

		// BR-03
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $text( '/*/cbc:IssueDate' ) ) ) {
			$result['errors'][] = '[BR-03] Rechnungsdatum (BT-2, cbc:IssueDate) fehlt oder hat nicht die Form JJJJ-MM-TT.';
		}

		// BR-04, and the type code list of the profile (P0100 / P0101).
		$type_element = $credit_note ? 'CreditNoteTypeCode' : 'InvoiceTypeCode';
		$type_code    = $text( "/*/cbc:{$type_element}" );
		if ( '' === $type_code ) {
			$result['errors'][] = "[BR-04] Rechnungstyp-Code (BT-3, cbc:{$type_element}) fehlt.";
		} elseif ( $peppol ) {
			$allowed = $credit_note ? self::CREDIT_NOTE_TYPE_CODES : self::INVOICE_TYPE_CODES;
			if ( ! in_array( $type_code, $allowed, true ) ) {
				$result['errors'][] = sprintf( '[%s] Rechnungstyp-Code "%s" steht nicht in der Peppol-Liste für %s.', $credit_note ? 'P0101' : 'P0100', $type_code, $credit_note ? 'Gutschriften' : 'Rechnungen' );
			}
		} elseif ( ! preg_match( '/^\d{1,3}$/', $type_code ) ) {
			$result['errors'][] = sprintf( '[BR-CL-01] Rechnungstyp-Code "%s" ist kein UNTDID-1001-Code.', $type_code );
		}

		// BR-05 and the syntax of BR-CL-04 (three capital letters; the ISO 4217 list itself is not held here).
		$currency = $text( '/*/cbc:DocumentCurrencyCode' );
		if ( '' === $currency ) {
			$result['errors'][] = '[BR-05] Währungscode (BT-5, cbc:DocumentCurrencyCode) fehlt.';
		} elseif ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			$result['errors'][] = sprintf( '[BR-CL-04] Währungscode "%s" hat nicht die Form eines ISO-4217-Codes.', $currency );
		}

		// BT-10 buyer reference.
		$buyer_ref = $text( '/*/cbc:BuyerReference' );
		$order_ref = $text( '/*/cac:OrderReference/cbc:ID' );
		if ( '' === $buyer_ref ) {
			if ( str_contains( $result['profile'], 'xrechnung' ) || str_contains( $result['profile'], 'xeinkauf.de' ) ) {
				$result['errors'][] = '[BR-DE-15] Käuferreferenz (BT-10) fehlt; XRechnung verlangt sie.';
			} elseif ( $peppol && '' === $order_ref ) {
				$result['errors'][] = '[PEPPOL-EN16931-R003] Weder Käuferreferenz (BT-10) noch Bestellreferenz (BT-13) ist angegeben.';
			} elseif ( '' === $order_ref ) {
				$result['warnings'][] = 'Käuferreferenz (BT-10) fehlt.';
			}
		}

		// BR-06 to BR-11: names, postal addresses, country codes.
		$seller = '/*/cac:AccountingSupplierParty/cac:Party';
		$buyer  = '/*/cac:AccountingCustomerParty/cac:Party';
		$seller_name = $text( "{$seller}/cac:PartyLegalEntity/cbc:RegistrationName | {$seller}/cac:PartyName/cbc:Name" );
		$buyer_name  = $text( "{$buyer}/cac:PartyLegalEntity/cbc:RegistrationName | {$buyer}/cac:PartyName/cbc:Name" );
		if ( '' === $seller_name ) {
			$result['errors'][] = '[BR-06] Name des Verkäufers (BT-27) fehlt.';
		}
		if ( '' === $buyer_name ) {
			$result['errors'][] = '[BR-07] Name des Käufers (BT-44) fehlt.';
		}
		if ( 0 === $xpath->query( "{$seller}/cac:PostalAddress" )->length ) {
			$result['errors'][] = '[BR-08] Anschrift des Verkäufers (BG-5) fehlt.';
		} elseif ( '' === $text( "{$seller}/cac:PostalAddress/cac:Country/cbc:IdentificationCode" ) ) {
			$result['errors'][] = '[BR-09] Ländercode des Verkäufers (BT-40) fehlt.';
		}
		if ( 0 === $xpath->query( "{$buyer}/cac:PostalAddress" )->length ) {
			$result['errors'][] = '[BR-10] Anschrift des Käufers (BG-8) fehlt.';
		} elseif ( '' === $text( "{$buyer}/cac:PostalAddress/cac:Country/cbc:IdentificationCode" ) ) {
			$result['errors'][] = '[BR-11] Ländercode des Käufers (BT-55) fehlt.';
		}

		// BR-12 to BR-15: the four mandatory totals.
		$total      = '/*/cac:LegalMonetaryTotal';
		$lines_sum  = self::amount( $xpath, "{$total}/cbc:LineExtensionAmount", '[BR-12] Summe der Positionsnettobeträge (BT-106)', true, $result );
		$net        = self::amount( $xpath, "{$total}/cbc:TaxExclusiveAmount", '[BR-13] Gesamtbetrag ohne Umsatzsteuer (BT-109)', true, $result );
		$gross      = self::amount( $xpath, "{$total}/cbc:TaxInclusiveAmount", '[BR-14] Gesamtbetrag mit Umsatzsteuer (BT-112)', true, $result );
		$payable    = self::amount( $xpath, "{$total}/cbc:PayableAmount", '[BR-15] Zahlbetrag (BT-115)', true, $result );
		$allowances = self::amount( $xpath, "{$total}/cbc:AllowanceTotalAmount", 'Summe der Abschläge (BT-107)', false, $result ) ?? 0.0;
		$charges    = self::amount( $xpath, "{$total}/cbc:ChargeTotalAmount", 'Summe der Zuschläge (BT-108)', false, $result ) ?? 0.0;
		$prepaid    = self::amount( $xpath, "{$total}/cbc:PrepaidAmount", 'Vorauszahlung (BT-113)', false, $result ) ?? 0.0;
		$rounding   = self::amount( $xpath, "{$total}/cbc:PayableRoundingAmount", 'Rundungsbetrag (BT-114)', false, $result ) ?? 0.0;

		// BR-CO-18 and the total VAT amount of the TaxTotal that carries the breakdown.
		$tax = null;
		if ( 0 === $xpath->query( '/*/cac:TaxTotal/cac:TaxSubtotal' )->length ) {
			$result['errors'][] = '[BR-CO-18] Umsatzsteueraufschlüsselung (BG-23, cac:TaxSubtotal) fehlt.';
		} else {
			$tax = self::amount( $xpath, '/*/cac:TaxTotal[cac:TaxSubtotal]/cbc:TaxAmount', 'Umsatzsteuergesamtbetrag (BT-110)', true, $result );
		}

		// BR-16
		$line_element = $credit_note ? 'CreditNoteLine' : 'InvoiceLine';
		$line_nodes   = $xpath->query( "/*/cac:{$line_element}/cbc:LineExtensionAmount" );
		if ( 0 === $xpath->query( "/*/cac:{$line_element}" )->length ) {
			$result['errors'][] = "[BR-16] Die Rechnung enthält keine Position (BG-25, cac:{$line_element}).";
		} elseif ( null !== $lines_sum ) {
			// BR-CO-10
			$sum = 0.0;
			foreach ( $line_nodes as $node ) {
				$sum += (float) $node->nodeValue;
			}
			if ( abs( round( $sum, 2 ) - $lines_sum ) >= self::CENT ) {
				$result['errors'][] = sprintf( '[BR-CO-10] Summe der Positionen %0.2f entspricht nicht BT-106 %0.2f.', $sum, $lines_sum );
			}
		}

		// BR-CO-13
		if ( null !== $lines_sum && null !== $net && abs( round( $lines_sum - $allowances + $charges, 2 ) - $net ) >= self::CENT ) {
			$result['errors'][] = sprintf( '[BR-CO-13] BT-106 %0.2f - BT-107 %0.2f + BT-108 %0.2f entspricht nicht BT-109 %0.2f.', $lines_sum, $allowances, $charges, $net );
		}
		// BR-CO-15
		if ( null !== $net && null !== $tax && null !== $gross && abs( round( $net + $tax, 2 ) - $gross ) >= self::CENT ) {
			$result['errors'][] = sprintf( '[BR-CO-15] Netto %0.2f + Steuer %0.2f entspricht nicht Brutto %0.2f.', $net, $tax, $gross );
		}
		// BR-CO-16
		if ( null !== $gross && null !== $payable && abs( round( $gross - $prepaid + $rounding, 2 ) - $payable ) >= self::CENT ) {
			$result['errors'][] = sprintf( '[BR-CO-16] Brutto %0.2f - Vorauszahlung %0.2f + Rundung %0.2f entspricht nicht dem Zahlbetrag %0.2f.', $gross, $prepaid, $rounding, $payable );
		}

		$result['metadata'] = [
			'seller_name'    => $seller_name,
			'buyer_name'     => $buyer_name,
			'net_amount'     => $net,
			'tax_amount'     => $tax,
			'gross_amount'   => $gross,
			'payable_amount' => $payable,
			'currency'       => $currency,
			'buyer_ref'      => $buyer_ref,
		];
	}

	/**
	 * Checks of a UN/CEFACT CII invoice: fewer than for UBL (header, names, the three header totals).
	 *
	 * @param DOMDocument          $dom    Parsed document.
	 * @param array<string, mixed> $result Result, extended in place.
	 */
	private static function validate_cii( DOMDocument $dom, array &$result ): void {
		$xpath = new DOMXPath( $dom );
		$xpath->registerNamespace( 'rsm', 'urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100' );
		$xpath->registerNamespace( 'ram', 'urn:un:unece:uncefact:data:standard:ReusableAggregateBusinessInformationEntity:100' );
		$xpath->registerNamespace( 'udt', 'urn:un:unece:uncefact:data:standard:UnqualifiedDataType:100' );
		$text = static fn( string $expr ): string => trim( (string) $xpath->evaluate( "string({$expr})" ) );

		$result['profile'] = $text( '//ram:GuidelineSpecifiedDocumentContextParameter/ram:ID' );
		if ( '' === $result['profile'] ) {
			$result['errors'][] = '[BR-01] CII Guideline ID (BT-24) fehlt.';
		}

		$result['invoice_number'] = $text( '//rsm:ExchangedDocument/ram:ID' );
		if ( '' === $result['invoice_number'] ) {
			$result['errors'][] = '[BR-02] Rechnungsnummer (rsm:ExchangedDocument/ram:ID) fehlt.';
		}

		if ( '' === $text( '//rsm:ExchangedDocument/ram:IssueDateTime/udt:DateTimeString' ) ) {
			$result['errors'][] = '[BR-03] Rechnungsdatum (rsm:ExchangedDocument/ram:IssueDateTime) fehlt.';
		}

		$seller_name = $text( '//ram:ApplicableHeaderTradeAgreement/ram:SellerTradeParty/ram:Name' );
		$buyer_name  = $text( '//ram:ApplicableHeaderTradeAgreement/ram:BuyerTradeParty/ram:Name' );
		if ( '' === $seller_name ) {
			$result['errors'][] = '[BR-06] Name des Verkäufers (SellerTradeParty/Name) fehlt.';
		}
		if ( '' === $buyer_name ) {
			$result['errors'][] = '[BR-07] Name des Käufers (BuyerTradeParty/Name) fehlt.';
		}

		$sum   = '//ram:SpecifiedTradeSettlementHeaderMonetarySummation';
		$net   = self::amount( $xpath, "{$sum}/ram:TaxBasisTotalAmount", '[BR-13] Gesamtbetrag ohne Umsatzsteuer (TaxBasisTotalAmount)', true, $result );
		$tax   = self::amount( $xpath, "{$sum}/ram:TaxTotalAmount", 'Umsatzsteuergesamtbetrag (TaxTotalAmount)', true, $result );
		$gross = self::amount( $xpath, "{$sum}/ram:GrandTotalAmount", '[BR-14] Gesamtbetrag mit Umsatzsteuer (GrandTotalAmount)', true, $result );

		if ( null !== $net && null !== $tax && null !== $gross && abs( round( $net + $tax, 2 ) - $gross ) >= self::CENT ) {
			$result['errors'][] = sprintf( '[BR-CO-15] Netto %0.2f + Steuer %0.2f entspricht nicht GrandTotal %0.2f.', $net, $tax, $gross );
		}

		$result['metadata'] = [
			'seller_name'  => $seller_name,
			'buyer_name'   => $buyer_name,
			'net_amount'   => $net,
			'tax_amount'   => $tax,
			'gross_amount' => $gross,
			'currency'     => $text( '//ram:InvoiceCurrencyCode' ),
		];
	}

	/**
	 * An amount element as a float, null when it is absent or not a number.
	 *
	 * A missing required amount and a non-numeric one are errors; a missing optional one is not.
	 *
	 * @param DOMXPath             $xpath    Bound XPath.
	 * @param string               $expr     Element path.
	 * @param string               $label    Rule label and term for the message.
	 * @param bool                 $required Whether absence is an error.
	 * @param array<string, mixed> $result   Result, extended in place.
	 * @return float|null
	 */
	private static function amount( DOMXPath $xpath, string $expr, string $label, bool $required, array &$result ): ?float {
		$nodes = $xpath->query( $expr );
		if ( false === $nodes || 0 === $nodes->length ) {
			if ( $required ) {
				$result['errors'][] = "{$label} fehlt.";
			}
			return null;
		}
		$raw = trim( (string) $nodes->item( 0 )->nodeValue );
		if ( ! is_numeric( $raw ) ) {
			$result['errors'][] = "{$label} ist keine Zahl: \"{$raw}\".";
			return null;
		}
		return round( (float) $raw, 2 );
	}
}
