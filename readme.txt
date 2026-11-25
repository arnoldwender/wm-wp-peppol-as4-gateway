=== WM Peppol AS4 Gateway ===
Contributors: arnoldwender
Tags: peppol, e-invoicing, ubl, xrechnung, woocommerce
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

UBL invoices with the Peppol BIS Billing 3.0 identifier, the official EN 16931 rule set, SML/SMP receiver discovery and verified AS4 receipts.

== Description ==

The plugin builds UBL 2.1 invoices carrying the Peppol BIS Billing 3.0 specification identifier and refuses to build one when required data is missing instead of filling it in. It checks invoice files structurally (required terms, type codes, sum rules) and against the official EN 16931 rule set for UBL, looks a receiver up in the Peppol SML and its SMP, stores invoices, hands the invoices of completed WooCommerce orders to a KoSIT validator and a Phase4 sender when both are configured, verifies the signed AS4 receipt that answers a transmission and keeps it in a chained register, and shows buyers the stored status in a portal (shortcode `[wm_peppol_invoice_portal]`, Gutenberg block `wm/peppol-invoice-portal`, WP 6.5+ Block Bindings source `wm-peppol/status`, and GenerateBlocks 2.x dynamic tag `{{wm_peppol_status}}`).

Not implemented: AS4 signing, encryption and transport inside the plugin (the Phase4 service does that), the CreditNote syntax, tax exemptions, XSD schema validation, an archive. Uninstall preserves options and database tables by default unless `wm_peppol_delete_data_on_uninstall` is explicitly enabled under Peppol Gateway > Einstellungen.

No conformance is claimed anywhere: the rule set is the Schematron of artefact 1.3.16, not the reference implementation, and a document with no finding can still be refused by an access point.

= Receipt webhook =

The Phase4 service posts receipts to `/wp-json/peppol/v1/receipt`. The route refuses every request until a shared secret is configured: the constant `WM_PEPPOL_RECEIPT_SECRET` in wp-config.php or the option `wm_peppol_receipt_secret`. Each receipt carries `X-WM-Peppol-Signature`, the base64 HMAC-SHA256 of the raw body, and the fields `messageId` and `status` (`SUCCESS` or `FAILURE`).

= WP-CLI =

* `wp peppol status` reports version, tables, row counts, seller data, dispatch configuration and whether the receipt secret is set.
* `wp peppol validate <file>` runs the structural checks on an invoice XML (UBL or CII) and exits 1 when one fails.
* `wp peppol list-invoices [--limit=20] [--format=table]` lists recent invoices.
* `wp peppol generate-ubl <number> [--data=<json file>] [--amount=] [--output=]` builds a document without storing or sending it.
* `wp peppol receipts` reads the delivery register and `--verify` recomputes its hash chain. The receipt route verifies the signed AS4 receipt and checks its NonRepudiationInformation digests against the document that was sent; one that does not match is refused, not recorded as delivered.
* `wp peppol schematron <file>` runs the official EN 16931 rule set for UBL (artefact 1.3.16, 979 assertions) and reports every rule that fired. Measured against the artefact's own corpus: 1129 cases, all matching.
* `wp peppol lookup <participant-id>` asks the SML over DNS for the SMP of that participant, then the SMP for the receiving AS4 access point, and reports the address, the process ids and the certificate dates.

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-peppol-as4-gateway/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload the `wm-peppol-as4-gateway` folder to `/wp-content/plugins/` and activate it. Activation creates the invoice, transmission and audit tables.
2. Enter the seller data under Peppol Gateway > Einstellungen. Without it no invoice is built.
3. Optional: WooCommerce. Orders with a Peppol receiver id get an invoice on completion.
4. Optional: the KoSIT validator and Phase4 URLs, and the receipt secret, before anything is to be sent.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-peppol-as4-gateway/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Overview: stored invoices, Phase4 handovers, receipts, missing seller data and the three demo invoices
2. Block editor with wm/peppol-invoice-portal status lookup block
3. Public invoice status portal rendered via [wm_peppol_invoice_portal]

== Changelog ==


= 1.2.2 =
* Fixed: Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

= 1.2.1 =
* The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
* Fixed: thirteen strings had no translation in any catalog (the three trust-anchor settings with their descriptions, the five reasons a signed receipt is refused, and the activation message about missing PHP extensions); every site language showed them in German.
* Fixed: in eleven languages with more than two plural forms, the three demo-invoice counts used one form for every count above one; each form now matches its count, in the editor catalogs too.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* The first screenshot description named an invoice generator, Schematron validation and an SML/SMP lookup the screen does not show; it now describes the overview it shows.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.5 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-peppol-as4-gateway` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.

= 1.1.4 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..3.png`) and `== Screenshots ==` descriptions.
* Add `README.de.md`, `README.es.md`, root `llms.txt`, live previews, and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-peppol-as4-gateway/`).

= 1.1.3 =
* Point Plugin URI to the official plugin page.

= 1.1.2 =
* Added `wp_initialize_site` multisite provisioning when network-activated (`switch_to_blog` / `Database\Schema::activate()` / `restore_current_blog` in `try`/`finally`).
* Added `$network_wide` support to `Database\Schema::activate()` and `::deactivate()` and guarded `dbDelta()` loading.
* Set explicit `autoload = false` when writing `wm_peppol_db_version` in `WM_Peppol_Database::create_tables()`.

= 1.1.1 =
* Enforced X.509 certificate temporal validity (`notBefore` / `notAfter`) on signed AS4 receipts in `AS4\ReceiptEvidence`.
* Added optional SHA-256 signer certificate pinning (`wm_peppol_trusted_signer_sha256` / `WM_PEPPOL_TRUSTED_SIGNER_SHA256`), local PEM CA trust-bundle chain verification (`wm_peppol_trust_bundle_pem` / `WM_PEPPOL_TRUST_BUNDLE_PEM` / `WM_PEPPOL_TRUST_BUNDLE_FILE`, zero external network calls), and strict trust-anchor enforcement (`wm_peppol_require_trust_anchor` / `WM_PEPPOL_REQUIRE_TRUST_ANCHOR`), refusing untrusted signers on `/wp-json/peppol/v1/receipt` with HTTP 422 (`wm_peppol_receipt_untrusted_signer`).
* Added activation prerequisite check for `ext-dom` and `ext-openssl` (`Database\Schema::enforce_prerequisites()`), deactivating cleanly via `wp_die()` when missing.
* Added MySQL 8.0 integration CI job alongside the PHP 8.1/8.2/8.3 test matrix.

= 1.1.0 =
* Added compiled Gutenberg block `wm/peppol-invoice-portal` (`block.json`, `build/index.js`, `InspectorControls`, `ServerSideRender`) visible in the Block Inserter.
* Added WP 6.5+ Block Bindings source `wm-peppol/status` and GenerateBlocks 2.x dynamic tag `{{wm_peppol_status}}`.
* Added developer hooks `wm_peppol_ready`, `wm_peppol_invoice_recorded`, `wm_peppol_ubl_document` and `wm_peppol_rest_rate_limit`.
* Changed `uninstall.php` to preserve options and the three Peppol database tables by default unless the opt-in setting `wm_peppol_delete_data_on_uninstall` is enabled.
* Added transient-based hashed IP rate-limiting callback on `/wm-peppol/v1/invoice-status` and prepared `$wpdb` queries in the legacy ledger helper.
* Compiled complete UI and JS translations across 28 locales, added WordPress Playground `blueprint.json` and CycloneDX 1.6 `bom.json`, and aligned WP-CLI documentation on `wp peppol list-invoices`.
* Official EN 16931 Schematron validator (artefact 1.3.16, 979 assertions), live SML/SMP receiver discovery (`Discovery\SmpClient`), and signed AS4 receipt verification with chained delivery register (`AS4\ReceiptEvidence`, `AS4\DeliveryRegister`).
* Reader pass: UBL generator without invented fallback fields, single WooCommerce order flow, fail-closed HMAC receipt webhook, and WM Suite admin design family sheets.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.2 =
Screenshot 1 retaken with the current WM Suite menu. No code, data or settings change.

= 1.2.1 =
The family sheet of WM Suite Hub 1.2.5 and translation fixes: thirteen settings and receipt messages, and plural forms in eleven languages. No data or settings change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
