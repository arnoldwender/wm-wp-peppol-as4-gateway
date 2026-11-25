# Developer Guide — WM Peppol AS4 Gateway

Rewritten against the code on 2026-09-15. README.md has the REST table and the receipt webhook contract.

## 1. WP-CLI

```bash
wp peppol status                          # version, tables, row counts, seller data, dispatch, receipt secret, SML zone
wp peppol validate /path/to/invoice.xml   # structural checks (UBL Invoice/CreditNote or CII); exit 1 on a failed check
wp peppol list-invoices --limit=5 --format=json    # recent rows of wm_peppol_invoices
wp peppol generate-ubl INV-2026-001 --data=invoice.json --output=/tmp/invoice.xml   # not stored, not sent
wp peppol receipts --verify               # recompute the delivery register chain; exit 1 when a row changed
wp peppol schematron invoice.xml          # official EN 16931 rule set (artefact 1.3.16); exit 1 on a fatal finding
wp peppol schematron --coverage            # assertions in the table, how many are evaluated, which are not
wp peppol lookup 0007:5567321707          # SML then SMP: access point, process ids, certificate dates; exit 1 with the error code of the failing step
```

`--data` for `generate-ubl` is a JSON object with the generator's keys. The seller comes from the settings unless the file names one:

```json
{
  "buyer_reference": "04011000-1234512345-06",
  "buyer": { "endpoint": "0204:04011000-1234512345-06", "name": "Beispielamt", "city": "Halle (Saale)", "country": "DE" },
  "lines": [ { "name": "Beratung", "quantity": 2, "unit_price": 50, "vat_rate": 19 } ]
}
```

## 2. Generator input (`UblInvoiceGenerator::build()`)

| Key | Required | Meaning |
| --- | --- | --- |
| `invoice_number` | yes | BT-1 |
| `buyer_reference` | yes | BT-10 (Leitweg-ID for public buyers) |
| `buyer` | yes | `endpoint` (`scheme:id`), `name`, `country`; optional `legal_name`, `street`, `city`, `postal_code`, `vat_id`, `company_id`, `party_id`, `party_id_scheme` |
| `seller` | from settings | same keys plus `iban`; required: `endpoint`, `name`, `street`, `city`, `postal_code`, `country`, `vat_id`, and `iban` for payment means 30 or 58 |
| `seller_endpoint`, `buyer_endpoint` | | endpoint when the party array has none |
| `lines` | one of three | `name`, `quantity`, `unit_price` or `net`, optional `vat_rate`, `vat_category`, `unit_code` |
| `net_amount` + `line_description` | one of three | one line with that net amount |
| `payable_amount` + `line_description` | one of three | gross amount; the remainder of the tax calculation becomes BT-114 |
| `vat_rate`, `vat_category` | | document defaults, 19 and `S` |
| `invoice_type_code` | | a code of the Peppol invoice list, default 380; 381 is refused |
| `adjustments`, `discount_amount`, `shipping_amount` | | document allowances and charges |
| `prepaid_amount`, `rounding_amount` | | BT-113, BT-114 |
| `issue_date`, `due_date`, `payment_terms_days`, `delivery_date`, `period_start`, `period_end`, `preceding_invoice(s)`, `note`, `payment_terms`, `payment_means_code`, `currency` | | as named |

`build()` returns `xml`, `invoice_number`, `issue_date`, `currency`, `seller_endpoint`, `buyer_endpoint` and `totals` (lines, allowances, charges, net, tax, gross, prepaid, rounding, payable, groups). `InvoiceDataException::problems()` lists codes such as `seller.street`; `describe()` gives the German sentences.

## 3. Entry file

`wm-peppol-as4-gateway.php` registers the PSR-4 autoloader for `WMCyber\Peppol\`, loads the validator, the SBDH and ledger classes, the Leitweg-ID validator, the dispatch client and the receipt receiver, registers `Database\Schema::activate()` and `::deactivate()`, hooks `wp_initialize_site` to provision tables on new multisite blogs when network-activated, and boots `Core\Plugin` and `WM_Peppol_Gateway` on `plugins_loaded`. Extend the loader; do not replace it (CLAUDE.md).

## 4. Tests

| Command | What it proves | Writes |
| --- | --- | --- |
| `php tests/test-suite.php` | Every correction of 2026-09-15 and the existing behaviour, against a SQLite database built from `Schema.php` | temp files only |
| `bash tests/run-mutations.sh` | Undoing any entry of `tests/mutations.php` turns the suite red | temp copy |
| `php tests/test-ui-family.php` | Family sheets identical to the hub, no removed outline, no hex outside tokens in admin sheets | nothing |
| `vendor/bin/phpunit -c phpunit.xml` | UBL element presence, order and totals | nothing |
| `wp eval-file .../tests/wp-runtime-peppol.php --user=1` | The deployed plugin in a real WordPress; exits 1 if a plugin option or transient changed | nothing |
| `wp eval-file .../tests/test-invoice-status-portal.php` | The public status route through the REST stack | `PROBE-%` rows, deleted afterwards: disposable sites only |
| `php tools/en16931-schematron-conformance.php --corpus=tests/fixtures/en16931-1.3.16 --min=100` | Every one of the 1129 official EN 16931 cases matches the expectation the standard declares for it | nothing |
| `php tools/en16931-compile-schematron.php --download --check` | The committed rule table is still what the artefact compiles to | downloads to a temp dir |
| `php tools/make-as4-receipt.php --out=<dir> --self-check` | The receipt signer and the verifier agree on freshly generated keys, and a tampered receipt is refused | writes to `<dir>` |

## 5. Changing the EN 16931 rule set

`includes/Validation/En16931RuleSet.php` is generated. Do not edit it. To adopt a newer artefact:

1. Replace `tests/fixtures/en16931-<version>/` with the `test/` directories and the examples of the new release, and update its `README.md`.
2. Bump `ARTEFACT_VERSION`, `ARTEFACT_RELEASED` and `ARTEFACT_SOURCE` in `tools/en16931-compile-schematron.php`.
3. `php tools/en16931-compile-schematron.php --download`.
4. `php tools/en16931-schematron-conformance.php --corpus=tests/fixtures/en16931-<version>`. Any mismatch against the expected corpus count is a translation that no longer holds, named by rule. The compiler's translations from XPath 2.0 to XPath 1.0 are documented in its header; each one is pinned on its own in `tests/groups/12-schematron.php`.

## 6. Hooks, Gutenberg Block & Dynamic Bindings

### Actions & Filters
* `do_action( 'wm_peppol_ready', \WMCyber\Peppol\Core\Plugin $plugin )` — fired once `Plugin::boot()` has wired REST routes, admin screens, portal, dynamic bindings, WooCommerce hooks and WP-CLI.
* `do_action( 'wm_peppol_invoice_recorded', int $invoice_id, string $invoice_number, string $source )` — fired when a newly generated UBL invoice row is stored (`$source` is `'rest'`, `'admin'`, or `'woocommerce'`).
* `apply_filters( 'wm_peppol_ubl_document', array $result, array $data )` — filters the array returned by `UblInvoiceGenerator::build()`.
* `apply_filters( 'wm_peppol_rest_rate_limit', int $limit, \WP_REST_Request $request )` — filters the per-minute transient rate limit on `/wm-peppol/v1/invoice-status` (default `60`).

### Blocks & Dynamic Content
* Gutenberg block `wm/peppol-invoice-portal` (`blocks/invoice-portal/block.json`, editor script handle `wm-peppol-blocks-editor` in `build/index.js`) with `showHeading` and `showLead` toggle attributes.
* WordPress 6.5+ Block Bindings source `wm-peppol/status` (`key`: `summary`, `invoices`, `transmissions`, `delivered`, `dispatch`).
* GenerateBlocks 2.x dynamic tag `{{wm_peppol_status}}` (accepts optional `key` option in tag arguments).
