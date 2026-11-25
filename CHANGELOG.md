# Changelog

All notable changes to **WM Peppol AS4 Gateway** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- Relaxed `DynamicBindings` GenerateBlocks `generateblocks_dynamic_tag_replacement` filter parameter to `mixed`.
- Renamed `RestController::NAMESPACE` to `REST_NAMESPACE` for PHP 8.5/8.6 compatibility.
- Standardized spoke registration filter to `wm_register_suite_module` in main plugin file and boot test group.
- Clarified GPLv2 or later licensing wording in `README.md` and synchronized `bom.json`.

## [1.2.2] - 2026-11-21

### Fixed

- Screenshot 1 showed the WM Suite menu from before WM Suite Hub 1.2.3, with entries the hub no longer has («Marketplace (Lösungspakete)», «Merkle-Audit-Protokoll», «CRA- & SBOM-Zentrale»). It is retaken with the current menu; the plugin's code is unchanged.

## [1.2.1] - 2026-11-21

### Changed

- The bundled family stylesheet is identical to WM Suite Hub 1.2.5 again: it starts the admin header below WordPress's help toggle instead of shortening it. This plugin's own screens register no help tab, so they change only where another plugin adds one.
- The catalogs carry the Author URI as `https://arnoldwender.com`, as the plugin header does; the old `www.` entry is kept only as obsolete.
- The 28 editor catalogs (`.json`) are rebuilt from the `.po` files: the block's title, description and keywords carry their block context, and every plural entry carries all the forms of its language.

### Fixed

- Thirteen strings had no translation in any of the 28 catalogs, so every site language showed them in German: the three trust-anchor settings with their descriptions (allowed certificate fingerprints, local CA certificate bundle, rejecting receipts without either), the five reasons a signed receipt is refused (no signing certificate, certificate expired or not yet valid, no trust anchor stored, signer not anchored) and the activation message about missing PHP extensions with its title.
- In the eleven languages with more than two plural forms (Arabic, Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak, Slovenian), the three demo-invoice counts (created, removed, and the removal button) used one form for every count above one. Each form now matches its count; Irish uses a label form ("sonraisc shamplacha: %d").

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.
- The description of the first screenshot (`readme.txt` and the three READMEs) named an invoice generator, EN 16931 Schematron validation and an SML/SMP lookup. The screen is the overview with its counters and the three demo invoices, and the description now says so.
- `CLAUDE.md` gave the version as 1.1.2 and described `assets/images/` files that had already been moved; it now points at the plugin header for the version and names `.wordpress-org/` for the banners and screenshots.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.5] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-peppol-as4-gateway/`, `https://github.com/arnoldwender/wm-wp-peppol-as4-gateway`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [1.1.4] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-3.png`) with `== Screenshots ==` in `readme.txt`.
- Added German (`README.de.md`) and Spanish (`README.es.md`) documentation with language switcher badges, root `llms.txt`, visual previews, and direct links to `https://arnoldwender.com/wordpress-plugins/wm-peppol-as4-gateway/`.

### Changed
- Archived superseded pre-2026-11-19 banners and mockups non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata, adapter fallback, test bootstrap, and SBOM (`bom.json`) to `1.1.4`.

## [1.1.3] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-peppol-as4-gateway/` and align release metadata at `v1.1.3`.

## [1.1.2] - 2026-11-18

### Fixed
- **Multisite site provisioning (`wm-peppol-as4-gateway.php`, `Database\Schema`):**
  - Added `wp_initialize_site` handler `wm_peppol_as4_gateway_initialize_site()` so new blogs created in a multisite network while the plugin is network-activated receive the three Peppol tables via `switch_to_blog()` -> `Database\Schema::activate()` -> `restore_current_blog()` inside `try`/`finally`.
  - Updated `Database\Schema::activate( bool $network_wide = false )` and `Database\Schema::deactivate( bool $network_wide = false )` to iterate network sites via `get_sites()` when network-activated/deactivated, and guarded `wp-admin/includes/upgrade.php` loading with `function_exists( 'dbDelta' )`.
- **Option autoload (`WM_Peppol_Database::create_tables()`):** passed explicit `false` autoload flag when writing `wm_peppol_db_version`.

## [1.1.1] - 2026-11-18

### Security & Fixes
- **AS4 receipt X.509 validity & trust anchoring (`AS4\ReceiptEvidence`, `WM_Peppol_Webhook_Receiver`, `Core\Settings`):**
  - Enforced certificate validity window (`notBefore <= now <= notAfter`) during XMLDSig verification so expired or not-yet-valid signing certificates are refused.
  - Added SHA-256 Access Point signer fingerprint pinning (`wm_peppol_trusted_signer_sha256` / `WM_PEPPOL_TRUSTED_SIGNER_SHA256`), local PKI CA trust-bundle chain verification (`wm_peppol_trust_bundle_pem` / `WM_PEPPOL_TRUST_BUNDLE_PEM` / `WM_PEPPOL_TRUST_BUNDLE_FILE` via `openssl_x509_checkpurpose`, zero external network calls), and strict trust-anchor enforcement (`wm_peppol_require_trust_anchor` / `WM_PEPPOL_REQUIRE_TRUST_ANCHOR`).
  - `/wp-json/peppol/v1/receipt` refuses untrusted AS4 receipt signers with HTTP `422` (`wm_peppol_receipt_untrusted_signer`) and records the rejection in the chained `DeliveryRegister`. Sites with neither pin nor CA bundle nor strict enforcement remain backward-compatible (`trust_status = 'unconfigured'`).
- **Activation prerequisite check (`Database\Schema::enforce_prerequisites()`):** checks `ext-dom` and `ext-openssl` before activating and calls `deactivate_plugins()` + `wp_die()` cleanly if missing.
- **CI:** added real `mysql:8.0` service integration job to `.github/workflows/ci.yml`.

## [1.1.0] - 2026-11-16

### Added
- Compiled Gutenberg block `wm/peppol-invoice-portal` (`blocks/invoice-portal/block.json`, `blocks-src/index.js`, `build/index.js`, `build/index.asset.php`) with `InspectorControls`, `ServerSideRender` and `wp_set_script_translations()`.
- WP 6.5+ Block Bindings source `wm-peppol/status` and GenerateBlocks 2.x dynamic tag `{{wm_peppol_status}}` (`includes/Frontend/DynamicBindings.php`).
- Developer extensibility hooks: actions `wm_peppol_ready` and `wm_peppol_invoice_recorded`, filters `wm_peppol_ubl_document` and `wm_peppol_rest_rate_limit`.
- Opt-in data removal setting `wm_peppol_delete_data_on_uninstall` (default `0`) on Peppol Gateway > Einstellungen; `uninstall.php` preserves options and the three Peppol/AS4 database tables by default.
- WordPress Playground Blueprint (`.wordpress-org/blueprints/blueprint.json`), CycloneDX 1.6 SBOM (`bom.json`), and complete `.po`/`.mo` + JED `.json` translations across 28 locales.

### Security & Fixes
- Replaced `__return_true` permission callback on `/wm-peppol/v1/invoice-status` with `check_public_invoice_lookup_access()` enforcing transient-based HMAC-SHA256 IP rate limiting (default 60 requests/min via `wm_peppol_rest_rate_limit`, never storing raw IP addresses, bypassed for `manage_options`).
- Wrapped legacy `$wpdb` queries in `includes/class-peppol-database.php` with `$wpdb->prepare(...)` and added CLI-aware `ABSPATH` / `WP_CLI` guards to `tools/make-as4-receipt.php` and `tools/en16931-compile-schematron.php`.
- Aligned WP-CLI documentation across `README.md`, `readme.txt`, `CLAUDE.md` and `docs/DEVELOPER-GUIDE.md` on `wp peppol list-invoices`.


### Added — the catalog freshness gate (2026-09-20)

- `tests/test-pot-freshness.php` and two CI steps on PHP 8.3: the gate extracts the translatable strings from the source and fails if any of them lacks an entry in the `.pot`. A string with no catalog entry cannot be translated by anyone into any language — the translator never sees it, nothing reports an error, and the interface simply stays German.
- **This catalog was already current** — 203 translatable strings, 204 entries, nothing missing and nothing stale — and that is the reason to add the gate rather than a reason to skip it. Fifteen of the fleet's twenty-four plugins had drifted, one of them down to a single entry still shared with the code, and not one of them had a check that could notice. Being correct today is not a mechanism.
- The gate takes its definition of "fresh" from `wp i18n make-pot` rather than a regex of its own, reads the text domain from the plugin header instead of holding a second copy, treats 0 extracted strings as a failure rather than a pass, ignores the plugin-header fields `make-pot` adds (nobody translates an author name or a URL), requires wp-cli with no escape hatch, and runs its own self-test before judging the plugin.
### Plugin Check errors in the distribution view (2026-09-17)
- **`.license-allowlist.yml` is out of the package.** Plugin Check reported it as `hidden_files` (an error) in the distribution view; it is repository tooling for the licence gate, and every other plugin of the fleet already excludes it. 1 error → 0.

### Delivery evidence: signed AS4 receipts and a chained register (2026-09-16)

#### Added
- **The receipt route verifies the signed AS4 receipt itself.** `AS4\ReceiptEvidence` canonicalises `ds:SignedInfo` by the method it names, checks the signature with OpenSSL against the X.509 certificate the receipt carries, and re-digests every `ds:Reference` against the content it points at, including the `enveloped-signature` transform. The HMAC over the request body says the call came from the configured sender; only this says the access point acknowledged anything.
- **And whether it acknowledges THIS invoice.** The `ds:Reference` digests inside `ebbpsig:NonRepudiationInformation` are compared with the XML this site stored and handed over. Three answers, never two: `yes`, `no`, and `unknown` when there was nothing to compare. A receipt that is genuinely signed and genuinely untampered but carries digests of another document answers `no` — and a single "valid" would have turned it into proof of a delivery that did not happen.
- **A receipt that does not verify does not mark an invoice delivered.** It is refused with 422 and recorded as rejected, because a rejected receipt is evidence of what arrived, not something to forget.
- **`wm_peppol_audit_log` is written for the first time.** The table has existed since the first version and nothing ever wrote to it. `AS4\DeliveryRegister` appends one row per receipt, each carrying the fingerprint of the row before it (HMAC-SHA256 keyed with the site's `AUTH_KEY`, folding in the SHA-256 of the detail). Editing or removing a row breaks every fingerprint after it. `wp peppol receipts` reads it, `wp peppol receipts --verify` recomputes the chain and names the first row that does not hold.
- The schema is brought forward on `plugins_loaded` when the stored version differs. A plugin updated through the dashboard or WP-CLI is never re-activated, and a table without the new columns would make every register insert fail silently — which reads exactly like "no receipts arrived".
- `tools/make-as4-receipt.php` builds genuinely signed receipts with a fresh RSA key, in both shapes access points use (detached over the `eb:Messaging` header, and enveloped over the whole envelope). CI regenerates and verifies them, so an OpenSSL or libxml upgrade that changes canonicalisation is caught instead of passing on committed bytes.

#### Standard
Quoted from the OASIS AS4 Profile of ebMS 3.0 v1.0 CS02, checked 2026-09-16: *"the content of the eb:Receipt element MUST be a valid ebbpsig:NonRepudiationInformation element"*, *"the sender of the Receipt ... MUST use ds:Reference elements containing digests of the original message parts for which NRR is required"*, and *"An AS4 message that has been digitally signed MUST be acknowledged with a message containing an eb:Receipt signal that itself is digitally signed."*

#### Not claimed
The signing certificate is not checked against the Peppol PKI and not checked for revocation: `signature_verified` means OpenSSL accepted the signature over the canonicalised SignedInfo, no more. The register is not a timestamp from a trusted third party and not a qualified seal; it shows the rows have not changed since this site wrote them, and a database restored onto a site with different salts makes the chain unverifiable, which the verifier reports rather than hides. The older `WM_Peppol_Database` ledger class is untouched and still unwired: it writes a different table layout, and it was left alone rather than repurposed.

### EN 16931 rule set (2026-09-16)

#### Added
- **The official EN 16931 Schematron for UBL runs inside the plugin.** `Validation\SchematronValidator` evaluates 979 assertions of validation artefact **1.3.16 (2026-04-13)** — 223 EN 16931 business rules and 756 UBL syntax rules, 281 of them fatal. `wp peppol schematron <file>`, `wp peppol schematron --coverage` and `POST /wm-peppol/v1/schematron`.
- **The rule table is compiled, not hand-written.** `tools/en16931-compile-schematron.php` reads the artefact and translates its XPath 2.0 into the XPath 1.0 libxml evaluates. A hand-copied rule list drifts silently and becomes a conformance claim nobody can trace; this one carries the artefact version, and CI re-downloads the release to confirm the committed table still matches it.
- **Measured against the standard's own corpus**, not against assertions this repository wrote: **1129 cases over 201 rules, all matching the expectation the artefact declares for each**, plus the 18 shipped example invoices with no fatal finding. Both run in CI and in the suite.
- A rule the compiler cannot translate stays in the table marked unsupported and is named by `--coverage`; an expression the engine cannot evaluate is reported in `unevaluated`. Neither counts as satisfied — a rule that disappears reads as a rule that passed.
- On a WooCommerce dispatch the rule set runs and fatal findings go on the order. It refuses the dispatch only when `wm_peppol_schematron_gate` is `1`: a site that dispatches today must not start failing on a rule its access point tolerates.

#### Seven XPath 2.0 constructs the artefact uses and libxml does not have
Each is translated by the compiler, documented in its header and pinned on its own in `tests/groups/12-schematron.php`. Four of them were defects found by measurement, not foreseen:
- A **function call as a path step** (`cac:TaxScheme/normalize-space(upper-case(cbc:ID))`). libxml refuses the whole test, so 52 rules silently stopped firing.
- A **rule context is a match pattern**, not a path from the root element. Read as a path, all 23 BR-CL code-list rules never fired.
- A **comparison with a boolean literal**: XPath 1.0 takes the effective boolean value of the node-set, so `cbc:ChargeIndicator = false()` was true exactly when the element was **absent** — the opposite of the document. And `xs:boolean` has four lexical forms: comparing only `true`/`false` passed all 1129 official cases and still misread `ubl-tc434-example2.xml`, which writes `0`.
- A **date cast**: XPath 1.0 relational operators convert to number, so two ISO dates compared as NaN and the rule fired on every document.

#### Not implemented
- XSD schema validation. This is the Schematron rule set, not the schema, and not the reference implementation: a document with no finding here can still be refused by an access point. The documentation says so in every place it reports a result.

### Receiver discovery, SML to SMP (2026-09-16)

#### Added
- **`Discovery\SmpClient` does the lookup it is named after.** Until 2026-09-15 it returned a fixed access point with `cert_valid => true` for any identifier, including `0000:NOBODY-AT-ALL`, and the REST route, the CLI and the admin page presented that as a result; no DNS or SMP request was ever made. From 09-15 to 09-16 it answered an honest 501. It now asks the production SML over DNS for the SMP of a participant, asks that SMP for the access point of a document type, and reports what came back.
- **The SML naming scheme this file carried was wrong and is corrected.** It built md5 of `scheme::value` behind a `b-` prefix under `edelivery.tech.ec.europa.eu`. The SML answers to Base32 (RFC 4648, no padding) of the SHA-256 of the lower-cased identifier value, then the identifier scheme, then `participant.sml.prod.tech.peppol.org`, with no prefix. Verified live on 2026-09-16 with `dig +short NAPTR` against the production zone and `curl` against the SMP it named; the commands are in the file header.
- **Each failing step has its own error code**, so "not registered in this zone" (`wm_peppol_smp_dns_failed`), "registered but does not accept this document type" (`wm_peppol_smp_document_not_offered`), "the SMP did not answer" (`wm_peppol_smp_http_failed`) and "the identifier has the wrong shape" (`wm_peppol_smp_bad_identifier`) are four answers, not one. `wp peppol lookup` prints the code; `POST /wm-peppol/v1/lookup` carries it with 400, 404 or 502 instead of the previous unconditional 501.
- NAPTR records are ordered by order then preference (RFC 3403) and only a `Meta:SMP` service with an http(s) replacement is taken; the SMP path is percent-encoded; a successful lookup is cached six hours, a failure never. `wp option update wm_peppol_sml_zone test` asks the test SML (SMK). The resolver is injectable, so no test touches DNS.
- Certificate subject and validity dates are read from the certificate the SMP publishes. `expired` compares its `notAfter` with now, in `SmpClient::is_expired()`; whether the certificate chains to the Peppol PKI is not checked and is not claimed.

#### Fixed
- `wp peppol status` printed `SMP discovery: not implemented` with discovery live. It reports the SML zone it would ask.
- `uninstall.php` left `wm_peppol_sml_zone` behind. The option is deleted with the others; the lookup cache was already covered by the transient sweep, and the uninstall assertion now names it.
- `process_ids` came back empty against a live SMP: the parser looked for a `ProcessType` parent, which the document does not have. It walks to the `Process` ancestor.

#### Tests
- 355 assertions, 101 mutations, all red when undone. The SMP group drives the whole path through an injected resolver and an inline SignedServiceMetadata fixture. The documentation guard was split: ten phrases of the earlier version stay banned, three that discovery made true are now *backed* (a document may say them only while the code that makes them true is there), and four sentences that call discovery missing are banned in the other direction.

### Family sheets (2026-09-16)
- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Reader pass (2026-09-15)

Measured on a local WordPress install and the demo Box before the change (identical state); every item has an assertion in `tests/groups/` and an entry in `tests/mutations.php`.

#### Fixed
- **Admin page:** the invoice form called `generate_invoice_xml()` and the lookup form `lookup_endpoint()`, neither of which exists: both ended in a fatal error. The page is German now, stores the invoice it builds (GMT timestamps), has no SMP form, and its settings write the `wm_peppol_seller_*` options the generator reads instead of an array nothing read. The prefilled Leitweg-ID `04011000-12345-67`, which the plugin's own validator rejects, is gone.
- **Generator:** no more invented data. It filled missing parties with "Musterstrasse 1", VAT id DE123456789, "Kunde", "Unbekannt", DE000000000, a payable amount of 100.00 and the invoice number as buyer reference; it now raises `InvoiceDataException` naming what is missing. `EndpointID` carried the scheme inside its value (`9930:DE…`); the syntax page shows the identifier with the scheme in `@schemeID`. A seller `PartyIdentification` with scheme 9930 (not an ICD code, BR-CL-10) is no longer derived. Lines at several VAT rates got one breakdown at the document rate; there is one per category and rate now. `rounding_amount` changed `PayableAmount` without emitting `PayableRoundingAmount`; a gross input came out one cent off for about 16 % of amounts; both are carried as BT-114 now. Type 381 was emitted in the Invoice syntax although Peppol lists it only for CreditNote; it is refused.
- **Validator:** a document without TaxTotal, totals or lines was valid (a missing amount became NaN); every CreditNote failed on `InvoiceTypeCode`; type code "banana" passed; a missing buyer reference carried the label BR-10, the buyer postal address rule; BR-CO-15 allowed 0.02. Added BR-08 to BR-16, BR-CO-10, -13, -16, -18, P0100/P0101, R003. `wp peppol validate` no longer calls a file "EN 16931 conformant".
- **WooCommerce:** two flows built two different documents for the same order, and the one sent (placeholder IBAN, supplier `DE999999999`, no TaxTotal, fixed 19 %, `&` dropped from names) was not the one stored. One flow now builds the invoice from the order lines, rates, shipping and fees and the billing address, stores it once per order, and dispatches it only when KoSIT and Phase4 URLs are configured, as a scheduled event, with the stored XML. The receiver id was built as `0204:` plus the endpoint field (`0204:` alone for Leitweg-only orders); a Phase4 answer without messageId counted as sent; a zero-quantity line threw DivisionByZeroError; order meta mixed post meta with the order API (HPOS). All fixed; a transmission row is written.
- **REST:** `/invoices/generate` threw a TypeError on a non-JSON body, stored 100.00 next to an XML of 595.00 and answered 201 when the insert failed; routes declared no arguments; `/lookup` returned a fixed access point with `cert_valid: true`; the public status route reported the demo rows as delivered.
- **Receipt webhook:** any status other than SUCCESS, "PENDING" included, failed the order; notes were Spanish. It declares `messageId` and `status` (SUCCESS, FAILURE), updates the transmission and invoice rows, and answers 404 for an unknown messageId.
- **Leitweg-ID:** checked against KoSIT specification 2.0.2 (structure and check digits); computed without bcmath, which made every ID a checksum error on hosts without it.
- **Hub adapter:** health was HEALTHY without tables and telemetry carried `EN_16931_COMPLIANT` and `OASIS_EBMS3_READY` literals; health, telemetry and self-test are measured now.
- **Dashboard widget and portal:** "konform" and "Pflicht bereit" on every install, no stylesheet on the dashboard, 44 inline hex colours and fixed ids in the portal, "UBL-Download" and "kryptografisch signiert" claims. Portal CSS and JS are separate files, ids are unique per instance, script strings are no longer HTML-escaped before `textContent`.
- **Demo data** has status `demo`, is not reported publicly and can be removed, including the three stub rows of earlier versions.
- **Uninstall** also removes the receipt secret, the service URLs, rate-limit transients, scheduled dispatches and the empty `uploads/wm-peppol` directory.
- Strict types and direct-access guards in every shipped file; catalogs rebuilt from the code (the de_DE catalog was rejected by WordPress); `docker-compose.yml` without literal keystore passwords; `composer.json` requires PHP 8.1 like the header.

#### Removed
- `Security\RequestShield` (called on every request, result ignored, logged client addresses), `Security\DirectoryProtector` (created a directory on every request that nothing used), `Security\UploadShield`, `RateLimiter`, `ErrorSanitizer` (no callers), `class-peppol-cli.php` and `class-peppol-spoke-adapter.php` (never loaded), `class-peppol-ubl-builder.php` (the second order flow).

#### Documentation
- README, readme.txt, CLAUDE.md and docs/ rewritten against the code. Removed claims: validation "against UBL 2.1 and CII standards" with BR-DE-01 to BR-DE-28, Schematron, SBDH parsing, envelope signing, WS-Security, SMP discovery, a ledger sealing every invoice, CSV injection protection of exports, "fails the build". The legal statements of docs/LEGAL-COMPLIANCE.md were removed; it is a standards reference now.

#### Tests
- `tests/test-suite.php` loads the real entry file and runs its SQL on SQLite with the plugin's tables; mutation runner; PHPUnit in CI; `tests/wp-runtime-peppol.php` for a live site.

### Fixed
- **The plugin could not be activated** (measured on a local WordPress install, WordPress 7.1, 2026-09-14): `WM_Peppol_Webhook_Receiver` extended `WP_REST_Controller` and declared `register_routes()` static, a fatal error at load time. The class used nothing from the parent and no longer extends it.
- **The entry file loaded one of three layers.** The rewrite of 2026-08-28 dropped the boot of `WMCyber\Peppol\Core\Plugin` (REST API `wm-peppol/v1`, admin page, verification portal, `wp peppol`, `Database\Schema`) and the rewrite of 2026-08-29 dropped the validation and ledger classes on top, so nothing described in README, `docs/` and `tests/` was running. The entry file now carries a PSR-4 autoloader for `WMCyber\Peppol\`, loads the validation layer and the WooCommerce flow, creates the schema on activation and boots both runtime layers on `plugins_loaded`. The ledger's own `create_tables()` is deliberately not run: it describes a different `wm_peppol_invoices` than `Database\Schema`, and dbDelta would graft its NOT NULL columns onto the table the REST layer writes.
- `wp peppol status`, `wp peppol validate <file>` and `wp peppol list` exist now. They were promised in README, CLAUDE.md and this changelog since 1.0.0; the class that carried `status`/`validate` was never loaded and `list` was never written.
- The WooCommerce invoice hook no longer invents a buyer endpoint (`9930:DE000000<order id>`) for orders without one and no longer stores rows as `delivered` before anything was transmitted; it accepts `_peppol_endpoint_id` as well as `_peppol_buyer_endpoint`. The spoke adapter's settings link points at the page that exists (`wm-peppol-gateway`).
- Plugin Check (dist view): `parse_url` → `wp_parse_url`, the SOAP envelope is assembled without a heredoc, the upload polyglot check reads through `file_get_contents`, `UblInvoiceGenerator.php` carries the standard direct-access guard, `docker-compose.yml` stays out of the ZIP. `Requires PHP` is 8.1 in header and readme: the code uses readonly properties.

### Security
- **Receipt webhook fails closed.** `POST /wp-json/peppol/v1/receipt` completes or fails WooCommerce orders by `messageId` and was open to anyone (`permission_callback` `__return_true`). It now refuses every request until `WM_PEPPOL_RECEIPT_SECRET` or the option `wm_peppol_receipt_secret` is set (401 `wm_peppol_receipt_unconfigured`), requires `X-WM-Peppol-Signature` = base64 HMAC-SHA256 of the raw body (401 `wm_peppol_receipt_unsigned`, 403 `wm_peppol_receipt_bad_signature`). The signature scheme of the real access point is still a pending decision; this is the baseline until then.

### Design family (hackaton phase 3, 2026-09-14)
- The admin screen renders from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) instead of the plugin's own `admin-obsidian.css`; same handles as every WM plugin, versioned by file time. Native `.wrap`, family badge with the hub/standalone state, gettext menu titles, family menu icon, visible focus rings on every control. Synced from the canonical WM Suite design-family stylesheets.

### Known, not fixed here
- The HMAC ledger (`WM_Peppol_Database`) is loaded but not wired to the tables the plugin writes; `wm_peppol_audit_log` exists and nothing writes to it.
- Orders paid online are invoiced as an open amount payable by transfer; a completed order whose receipt reports FAILURE is set to `failed`. Both are decisions pending.
- Licence headers disagree across the repo (`Proprietary`, `LicenseRef-Proprietary-WM-Suite`, `GPL-2.0-or-later` in README). Decision pending.

## [1.0.0] - 2026-08-28

As released. Several of these lines described code that did not run or did not exist; the reader pass above lists what the code did.

### Added
- Initial release of **WM Peppol AS4 Gateway**.
- Core EN 16931 Schematron XML validator (UBL 2.1 & CII / ZUGFeRD 2.3 / XRechnung 3.0.2).
- German national business rules (BR-DE) validation (BuyerReference / Leitweg-ID syntax checks).
- SBDH (Standard Business Document Header) packaging for Peppol AS4 transmission.
- Linear HMAC-SHA256 tamper-evident invoice receipt ledger (GoBD revisionssicher).
- WP-CLI command suite (`wp peppol status`, `wp peppol validate`, `wp peppol list`).
- WM Suite Hub Spoke Adapter with CycloneDX v1.6 SBOM.
- Standalone zero-dependency test suite (`tests/test-suite.php`).
