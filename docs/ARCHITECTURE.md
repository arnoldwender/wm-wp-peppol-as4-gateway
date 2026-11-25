# Architecture — WM Peppol AS4 Gateway

Rewritten against the code on 2026-09-15, discovery added 2026-09-16. The earlier version described a sender access point with WS-Security signatures and payload encryption; neither is code in this repository. Discovery was a stub then and is real now.

## 1. Where the plugin sits

In the Peppol four-corner model the seller (corner 1) hands an invoice to its access point (corner 2), which delivers it to the buyer's access point (corner 3) and so to the buyer (corner 4). The plugin runs at corner 1. It builds, checks and stores the document, and it looks the receiving access point up in the SML and its SMP. A Phase4 service that you run, with its own access point certificates, is the sender: the plugin opens no AS4 connection and holds no certificate.

```text
Completed WooCommerce order ─┐
Admin form, REST, WP-CLI ────┼─> UblInvoiceGenerator ──> wm_peppol_invoices
                             │                                │
                             │                                │ scheduled event wm_peppol_dispatch_invoice
                             │                                v   (only when KoSIT and Phase4 URLs are set)
                             │   WM_Peppol_Validator ─> KoSIT validator (HTTP) ─> Phase4 sender (HTTP)
                             │                                                        │ messageId
                             │                                                        v
                             │                                            wm_peppol_transmissions
Phase4 service ── signed receipt ──> POST peppol/v1/receipt ──> transmission, invoice, order status
Buyer ── invoice number + Peppol id ──> POST wm-peppol/v1/invoice-status ──> stored state
```

## 2. Components

| File | Responsibility |
| --- | --- |
| `wm-peppol-as4-gateway.php` | Constants, PSR-4 autoloader, loads the non-namespaced classes, activation and deactivation hooks, `wp_initialize_site` multisite provisioning, `WM_Peppol_Gateway` (order-edit fields, receipt route), hub registration |
| `includes/Core/Plugin.php` | Boot on `plugins_loaded`: REST routes, admin page, WooCommerce flow, portal, WP-CLI |
| `includes/Core/Settings.php` | Option names and readers for seller data, service URLs, receipt secret |
| `includes/Core/InvoiceStore.php` | Measured state: tables present, demo rows, row counts |
| `includes/UBL/UblInvoiceGenerator.php` | Builds the UBL invoice; refuses missing data with `InvoiceDataException` |
| `includes/class-peppol-validator.php` | Structural checks of UBL Invoice, UBL CreditNote and CII |
| `includes/WooCommerce/WooCommerceInvoiceHook.php` | The order flow: document from the order, storage, scheduled dispatch, transmission row |
| `includes/class-peppol-phase4-client.php` | HTTP client of the KoSIT validator and the Phase4 sender |
| `includes/class-peppol-webhook-receiver.php` | Receipt route: HMAC check, declared arguments, status mapping |
| `includes/API/RestController.php` | `wm-peppol/v1` routes |
| `includes/Admin/AdminDashboard.php` | Admin page and dashboard widget |
| `includes/Frontend/InvoicePortal.php`, `blocks/invoice-portal/block.json`, `build/index.js`, `assets/css/wm-peppol-portal.css`, `assets/js/wm-peppol-portal.js` | Public status portal (shortcode + Gutenberg block `wm/peppol-invoice-portal`) |
| `includes/Frontend/DynamicBindings.php` | WordPress 6.5+ Block Bindings source `wm-peppol/status` and GenerateBlocks 2.x dynamic tag `{{wm_peppol_status}}` |
| `includes/CLI/PeppolCommand.php` | `wp peppol` |
| `includes/Adapters/Peppol_Spoke_Adapter.php` | Hub contract: health, telemetry, self-test, SBOM |
| `includes/class-peppol-leitweg-validator.php` | Leitweg-ID structure and check digits (KoSIT specification 2.0.2) |
| `includes/AS4/ReceiptEvidence.php` | Verifies a signed AS4 receipt: XMLDSig over the canonicalised SignedInfo, every ds:Reference re-digested, and the NonRepudiationInformation digests compared with the document that was sent |
| `includes/AS4/DeliveryRegister.php` | Append-only register of that evidence in `wm_peppol_audit_log`, each row chained to the one before it |
| `includes/Validation/SchematronValidator.php`, `includes/Validation/En16931RuleSet.php` | EN 16931 rule engine and the generated rule table (artefact 1.3.16): patterns, ordered rules, first match per node, assert and report, bound variables. The table is produced by `tools/en16931-compile-schematron.php` and checked by `tools/en16931-schematron-conformance.php` against the artefact's own 1129 cases |
| `includes/Discovery/SmpClient.php` | Receiver discovery: SML name (Base32 of SHA-256, no padding) to NAPTR `Meta:SMP` to the SMP's AS4 endpoint, its certificate dates and process ids. Injectable resolver; six-hour cache of successes only |
| `includes/AS4/As4MessagePackager.php`, `includes/class-peppol-as4-client.php` | Build an ebMS3 UserMessage and an SBDH envelope; no runtime path uses them |
| `includes/class-peppol-database.php` | HMAC ledger for a different table layout; loaded, not called |
| `includes/Database/Schema.php` | Tables on activation (single-site and multisite network-wide); clears the dispatch event on deactivation |

## 3. Data

**`wm_peppol_invoices`**: one row per invoice with its XML and SHA-256. Status: `generated` (stored), `sent` (handed to Phase4), `delivered` and `failed` (from a receipt), `dispatch_failed` (refused by the checks, KoSIT or Phase4, or no messageId), `demo`.

**`wm_peppol_transmissions`**: one row per hand-over with the `messageId`; `c2_sender_ap` and `c3_receiver_ap` hold the sender and receiver participant ids; `non_repudiation_receipt` holds the receipt body the webhook accepted.

**`wm_peppol_audit_log`**: the delivery register. One row per AS4 receipt, accepted or rejected, with `message_id`, the measurements in `detail_json`, and `previous_hash`/`current_hash` chaining it to the row before. `AS4\DeliveryRegister::verify_chain()` recomputes it; `wp peppol receipts --verify` reports the first row that does not hold.

**Options**: `wm_peppol_endpoint_id`, `wm_peppol_seller_*`, `wm_peppol_kosit_url`, `wm_peppol_phase4_url`, `wm_peppol_receipt_secret`, `wm_peppol_sml_zone` (production or test SML), `wm_peppol_schematron_gate` (a fatal EN 16931 finding stops a dispatch; off by default), `wm_peppol_delete_data_on_uninstall` (opt-in removal of options and tables on uninstall; `0` by default). **Order meta**: `_peppol_endpoint_id`, `_peppol_leitweg_id`, `_peppol_buyer_reference`, `_peppol_invoice_id`, `_peppol_message_id`, `_peppol_status`.

## 4. Identifiers the code uses

| Value | Where |
| --- | --- |
| CustomizationID `urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poacc:billing:3.0` | Generator; the validator treats a document starting with it as Peppol BIS (PEPPOL-EN16931-R004) |
| ProfileID `urn:fdc:peppol.eu:2017:poacc:billing:01:1.0` | Generator; process id of the Phase4 request |
| Document type id: UBL Invoice namespace + `::Invoice##` + CustomizationID + `::2.1` | Phase4 request. Not verified against the Phase4 service documentation |
| EAS scheme 9930 (Germany VAT number), 0204 (Peppol-Leitweg-ID), 0088 (GLN) | Examples in UI and tests; codelist/eas, May 2026 release |

## 5. Not built

AS4 transport, signing and encryption inside the plugin (the receipts that answer a transmission ARE verified); the CreditNote syntax; tax exemption categories; XSD schema validation; wiring of the HMAC ledger; an export before uninstall.

---

<!-- wm-measured-inventory -->

## Measured inventory

Regenerated from the code, never hand-edited. Everything above this line is written by hand and stays.

```bash
python3 the fleet's architecture inventory tool wm-peppol-as4-gateway --append-to docs/ARCHITECTURE.md
```

## Inventory by layer

**31 PHP files, 15814 lines** (excluding `tests/`, `vendor/`, `node_modules/`, `languages/`).

| Layer | Files | Lines |
|---|---:|---:|
| `(raíz)` | 2 | 313 |
| `build` | 1 | 11 |
| `includes` | 6 | 1153 |
| `includes/API` | 1 | 383 |
| `includes/AS4` | 3 | 898 |
| `includes/Adapters` | 1 | 191 |
| `includes/Admin` | 1 | 790 |
| `includes/CLI` | 1 | 431 |
| `includes/Core` | 3 | 414 |
| `includes/Database` | 1 | 185 |
| `includes/Discovery` | 1 | 504 |
| `includes/Frontend` | 2 | 293 |
| `includes/UBL` | 2 | 882 |
| `includes/Validation` | 2 | 7728 |
| `includes/WooCommerce` | 1 | 402 |
| `tools` | 3 | 1236 |

---

## Classes

| Class | File |
|---|---|
| `AdminDashboard` | [`includes/Admin/AdminDashboard.php`](../includes/Admin/AdminDashboard.php) |
| `As4MessagePackager` | [`includes/AS4/As4MessagePackager.php`](../includes/AS4/As4MessagePackager.php) |
| `DeliveryRegister` | [`includes/AS4/DeliveryRegister.php`](../includes/AS4/DeliveryRegister.php) |
| `DynamicBindings` | [`includes/Frontend/DynamicBindings.php`](../includes/Frontend/DynamicBindings.php) |
| `InvoiceDataException` | [`includes/UBL/InvoiceDataException.php`](../includes/UBL/InvoiceDataException.php) |
| `InvoicePortal` | [`includes/Frontend/InvoicePortal.php`](../includes/Frontend/InvoicePortal.php) |
| `InvoiceStore` | [`includes/Core/InvoiceStore.php`](../includes/Core/InvoiceStore.php) |
| `PeppolCommand` | [`includes/CLI/PeppolCommand.php`](../includes/CLI/PeppolCommand.php) |
| `Peppol_Spoke_Adapter` | [`includes/Adapters/Peppol_Spoke_Adapter.php`](../includes/Adapters/Peppol_Spoke_Adapter.php) |
| `Plugin` | [`includes/Core/Plugin.php`](../includes/Core/Plugin.php) |
| `ReceiptEvidence` | [`includes/AS4/ReceiptEvidence.php`](../includes/AS4/ReceiptEvidence.php) |
| `RestController` | [`includes/API/RestController.php`](../includes/API/RestController.php) |
| `Schema` | [`includes/Database/Schema.php`](../includes/Database/Schema.php) |
| `SchematronValidator` | [`includes/Validation/SchematronValidator.php`](../includes/Validation/SchematronValidator.php) |
| `Settings` | [`includes/Core/Settings.php`](../includes/Core/Settings.php) |
| `SmpClient` | [`includes/Discovery/SmpClient.php`](../includes/Discovery/SmpClient.php) |
| `UblInvoiceGenerator` | [`includes/UBL/UblInvoiceGenerator.php`](../includes/UBL/UblInvoiceGenerator.php) |
| `WM_Peppol_AS4_Client` | [`includes/class-peppol-as4-client.php`](../includes/class-peppol-as4-client.php) |
| `WM_Peppol_Database` | [`includes/class-peppol-database.php`](../includes/class-peppol-database.php) |
| `WM_Peppol_Gateway` | [`wm-peppol-as4-gateway.php`](../wm-peppol-as4-gateway.php) |
| `WM_Peppol_Leitweg_Validator` | [`includes/class-peppol-leitweg-validator.php`](../includes/class-peppol-leitweg-validator.php) |
| `WM_Peppol_Phase4_Client` | [`includes/class-peppol-phase4-client.php`](../includes/class-peppol-phase4-client.php) |
| `WM_Peppol_Validator` | [`includes/class-peppol-validator.php`](../includes/class-peppol-validator.php) |
| `WM_Peppol_Webhook_Receiver` | [`includes/class-peppol-webhook-receiver.php`](../includes/class-peppol-webhook-receiver.php) |
| `WP_Error` | [`tools/en16931-schematron-conformance.php`](../tools/en16931-schematron-conformance.php) |
| `WP_Error` | [`tools/make-as4-receipt.php`](../tools/make-as4-receipt.php) |
| `WooCommerceInvoiceHook` | [`includes/WooCommerce/WooCommerceInvoiceHook.php`](../includes/WooCommerce/WooCommerceInvoiceHook.php) |

---

## Entry points

**REST routes:** `/invoice-status`, `/invoices`, `/invoices/generate`, `/lookup`, `/receipt`, `/schematron`

**WP-CLI:** `peppol`

**Shortcodes:** `wm_e_invoice_lookup`, `wm_peppol_invoice_portal`

**Gutenberg blocks:** `wm/peppol-invoice-portal`

---

## Persistence — who writes each key and who reads it

| Key | Written by | Read by |
|---|---|---|
| `wm_peppol_db_version` | `includes/class-peppol-database.php` | — |
| `wm_peppol_delete_data_on_uninstall` | — | `uninstall.php` |
| `wm_peppol_notice_` (transient) | — | — |
| `wm_peppol_receipt_secret` | — | `includes/class-peppol-webhook-receiver.php` |

**Read but never written by this plugin:** `wm_peppol_delete_data_on_uninstall`, `wm_peppol_receipt_secret`. The behaviour behind them is reachable only through `wp option update` or a filter — there is no screen that sets them, so an operator cannot switch it on.


**Tables** (prefixed with `$wpdb->prefix`): `wm_peppol_audit_log`, `wm_peppol_invoices`, `wm_peppol_transmissions`

---

## Gates

- `tests/bootstrap.php`
- `tests/mutations.php`
- `tests/run-mutations.sh`
- `tests/test-invoice-status-portal.php`
- `tests/test-pot-freshness.php`
- `tests/test-suite.php`
- `tests/test-ui-family.php`
- `tests/wp-runtime-peppol.php`
- `.github/workflows/ci.yml`

What CI actually runs is in the workflow files above — a test file that no workflow invokes is not a gate.

---

## Drift detected against the documentation

Nothing measurable: versions agree and every class named in the documents exists.

