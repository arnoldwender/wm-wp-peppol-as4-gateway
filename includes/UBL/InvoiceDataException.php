<?php
/**
 * Raised when an invoice cannot be built from the data given.
 *
 * The generator used to fill every gap: seller "Musterstrasse 1" with VAT id DE123456789, buyer
 * "Kunde"/"Unbekannt"/DE000000000, a payable amount of 100.00 and the invoice number as buyer reference.
 * It now names what is missing instead. Problems are stable codes, so the generator stays free of
 * WordPress; describe() turns them into translated sentences where WordPress is loaded.
 *
 * @package WMCyber\Peppol
 */

declare(strict_types=1);

namespace WMCyber\Peppol\UBL;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class InvoiceDataException extends \InvalidArgumentException {

	/** @var string[] */
	private array $problems;

	/**
	 * @param string[] $problems Problem codes, e.g. "seller.street".
	 */
	public function __construct( array $problems ) {
		$this->problems = array_values( array_unique( $problems ) );
		parent::__construct( implode( ', ', $this->problems ) );
	}

	/** @return string[] */
	public function problems(): array {
		return $this->problems;
	}

	/** The problems as one translated sentence list. */
	public function describe(): string {
		$labels = self::labels();
		return implode( ' ', array_map( static fn( string $code ): string => $labels[ $code ] ?? $code, $this->problems ) );
	}

	/**
	 * Human text for every problem code the generator raises.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return [
			'invoice_number'        => __( 'Die Rechnungsnummer fehlt.', 'wm-peppol-as4-gateway' ),
			'buyer_reference'       => __( 'Die Käuferreferenz (BT-10) fehlt; Peppol BIS verlangt sie, solange keine Bestellreferenz angegeben ist.', 'wm-peppol-as4-gateway' ),
			'issue_date'            => __( 'Das Rechnungsdatum hat nicht die Form JJJJ-MM-TT.', 'wm-peppol-as4-gateway' ),
			'amounts'               => __( 'Es fehlen Rechnungspositionen oder ein Betrag.', 'wm-peppol-as4-gateway' ),
			'amount_not_numeric'    => __( 'Ein Betrag ist keine Zahl.', 'wm-peppol-as4-gateway' ),
			'line.name'             => __( 'Eine Position hat keine Bezeichnung.', 'wm-peppol-as4-gateway' ),
			'rate_negative'         => __( 'Ein Steuersatz ist negativ.', 'wm-peppol-as4-gateway' ),
			'type_code'             => __( 'Der Rechnungstyp-Code steht nicht in der Peppol-Liste für Rechnungen.', 'wm-peppol-as4-gateway' ),
			'credit_note'           => __( 'Eine Gutschrift (Typ 381) braucht die UBL-Syntax CreditNote, die dieser Generator nicht erzeugt.', 'wm-peppol-as4-gateway' ),
			'currency'              => __( 'Der Währungscode muss aus drei Großbuchstaben bestehen.', 'wm-peppol-as4-gateway' ),
			'seller.endpoint'       => __( 'Die Peppol-ID des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.name'           => __( 'Der Name des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.street'         => __( 'Die Straße des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.city'           => __( 'Der Ort des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.postal_code'    => __( 'Die Postleitzahl des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.country'        => __( 'Das Land des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.vat_id'         => __( 'Die USt-IdNr. des Verkäufers fehlt.', 'wm-peppol-as4-gateway' ),
			'seller.endpoint_format' => __( 'Die Peppol-ID des Verkäufers hat nicht die Form Schema:Kennung (vierstelliges Schema).', 'wm-peppol-as4-gateway' ),
			'seller.country_format' => __( 'Das Land des Verkäufers muss ein zweistelliger Ländercode sein.', 'wm-peppol-as4-gateway' ),
			'seller.vat_id_prefix'  => __( 'Die USt-IdNr. des Verkäufers beginnt nicht mit einem zweistelligen Ländercode.', 'wm-peppol-as4-gateway' ),
			'seller.iban'           => __( 'Für Zahlung per Überweisung fehlt die IBAN des Verkäufers.', 'wm-peppol-as4-gateway' ),
			'buyer.endpoint'        => __( 'Die Peppol-ID des Käufers fehlt.', 'wm-peppol-as4-gateway' ),
			'buyer.name'            => __( 'Der Name des Käufers fehlt.', 'wm-peppol-as4-gateway' ),
			'buyer.country'         => __( 'Das Land des Käufers fehlt.', 'wm-peppol-as4-gateway' ),
			'buyer.endpoint_format' => __( 'Die Peppol-ID des Käufers hat nicht die Form Schema:Kennung (vierstelliges Schema).', 'wm-peppol-as4-gateway' ),
			'buyer.country_format'  => __( 'Das Land des Käufers muss ein zweistelliger Ländercode sein.', 'wm-peppol-as4-gateway' ),
			'buyer.vat_id_prefix'   => __( 'Die USt-IdNr. des Käufers beginnt nicht mit einem zweistelligen Ländercode.', 'wm-peppol-as4-gateway' ),
			'rate_standard'         => __( 'Eine Position oder ein Zu-/Abschlag mit Steuerkategorie S hat keinen Steuersatz über null.', 'wm-peppol-as4-gateway' ),
		];
	}
}
