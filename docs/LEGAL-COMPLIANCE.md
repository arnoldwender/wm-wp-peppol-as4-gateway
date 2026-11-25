# Standards reference — WM Peppol AS4 Gateway

What the generator emits and the validator checks, with the sources they were read from. Rewritten against the code on 2026-09-15. This document makes no statement about legal obligations to issue or receive electronic invoices; the earlier version did, and those statements were not checked at their source.

Sources, read on 2026-09-15:

* Peppol BIS Billing 3.0, May 2026 release, docs.peppol.eu/poacc/billing/3.0/: UBL invoice syntax pages, code lists UNCL1001-inv, UNCL1001-cn, EAS and ICD, "EN16931 model bound to UBL" rules and "Rules for Peppol BIS 3.0 Billing".
* KoSIT, "Leitweg-ID Format-Spezifikation", version 2.0.2 of 28.07.2021.

## 1. Elements the generator always emits

Cardinality 1..1 or 1..n on the syntax pages, plus the seller VAT identifier BR-S-02 requires for standard-rated lines. `tests/Unit/En16931ConformanceTest.php` asserts each by XPath.

| Block | Elements |
| --- | --- |
| Header | CustomizationID, ProfileID, ID, IssueDate, DueDate, InvoiceTypeCode, DocumentCurrencyCode, BuyerReference |
| Seller | EndpointID with @schemeID, PartyName, PostalAddress (StreetName, CityName, PostalZone, Country), PartyTaxScheme, PartyLegalEntity |
| Buyer | EndpointID with @schemeID, PartyName, PostalAddress (Country; street, city and postal code when given), PartyLegalEntity; PartyTaxScheme only with a VAT id |
| Payment | PaymentMeans/PaymentMeansCode; PayeeFinancialAccount with the seller IBAN |
| VAT | TaxTotal/TaxAmount, one TaxSubtotal per category and rate |
| Totals | LineExtensionAmount, TaxExclusiveAmount, TaxInclusiveAmount, PayableAmount |
| Lines | ID, InvoicedQuantity with unitCode, LineExtensionAmount, Item (Name, ClassifiedTaxCategory), Price/PriceAmount |

Seller PartyIdentification (0..n on the syntax page) is emitted only when the caller passes `party_id`: its scheme must be an ISO 6523 ICD code (BR-CL-10), and 9930, the scheme the generator used to copy there, is an EAS code that the ICD list does not contain.

## 2. Emitted when given

Delivery/ActualDeliveryDate (defaults to the issue date, `delivery_date => false` suppresses it), InvoicePeriod, BillingReference, Note, PaymentTerms, AllowanceCharge with AllowanceTotalAmount and ChargeTotalAmount, PrepaidAmount, PayableRoundingAmount.

## 3. Calculation

```text
BT-106 = sum of line net amounts                      (BR-CO-10)
BT-109 = BT-106 - BT-107 + BT-108                      (BR-CO-13)
per category and rate: taxable = line nets + charges - allowances   (BR-S-08)
per category and rate: tax = taxable x rate / 100, rounded to cents (BR-S-09, BR-CO-17)
BT-110 = sum of the breakdown taxes
BT-112 = BT-109 + BT-110                               (BR-CO-15)
BT-115 = BT-112 - BT-113 + BT-114                      (BR-CO-16)
```

With a gross `payable_amount` as input, BT-114 is the difference between that amount and BT-112, so BT-115 equals the input.

## 4. Refused input

Missing invoice number, buyer reference (PEPPOL-EN16931-R003), buyer endpoint, name or country, seller endpoint, name, street, city, postal code, country or VAT id; an IBAN missing for payment means 30 or 58 (BR-61); a participant id that is not `four-digit scheme:identifier`; a country code that is not two capital letters; a VAT id without two-letter prefix (BR-CO-09); a standard-rated line, allowance or charge at 0 % (BR-S-05 to BR-S-07); a type code outside UNCL1001-inv, and 381, which the Peppol list gives only to the CreditNote syntax (P0100); a currency that is not three capital letters; an issue date that is not YYYY-MM-DD.

## 5. Structural checks (`WM_Peppol_Validator`)

UBL Invoice and CreditNote: BR-01 to BR-16, BR-CO-10, -13, -15, -16, -18, P0100 and P0101 for Peppol BIS documents, the syntax of BR-CL-01 and BR-CL-04, PEPPOL-EN16931-R003 as error for Peppol BIS and warning otherwise, BR-DE-15 for documents whose CustomizationID names XRechnung. Amounts are compared to the cent. CII: BR-01 to BR-03, BR-06, BR-07, the three header totals and BR-CO-15.

Not checked: the UBL schema, the Schematron rules of EN 16931, Peppol and XRechnung, code list membership beyond the type codes, identifier check digits other than the Leitweg-ID.

## 6. Leitweg-ID

`WM_Peppol_Leitweg_Validator` follows chapters 2.1 to 2.4 of the KoSIT specification: Grobadressierung of 2 to 12 digits, optional Feinadressierung of up to 30 letters (not case-sensitive) and digits, two check digits by ISO/IEC 7064 Modulo 97-10 over both parts without hyphens, letters A=10 to Z=35. The specification's worked example `04011000-1234512345-06` is a test case. The order-edit screen refuses an ID that fails; the generator does not check the buyer reference.

## 7. Not implemented

Attached documents (BG-24), the CreditNote syntax, tax exemption categories and reasons, currencies other than the document currency for VAT, order references (BT-13), and any Schematron validation.
