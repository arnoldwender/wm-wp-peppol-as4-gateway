# EN 16931 conformance corpus, artefact 1.3.16

These files are **not** written by WM Suite. They are the official test corpus of the European
Commission's EN 16931 validation artefacts, copied verbatim so the gate can run without a network.

| | |
| --- | --- |
| Source | <https://github.com/ConnectingEurope/eInvoicing-EN16931/releases/tag/validation-1.3.16> |
| Version | 1.3.16, published 2026-04-13 |
| Licence | EUPL 1.2 (the Appendix of the EUPL lists the GPL among the compatible licences; this plugin is distributed under GPLv2 or later) |
| Copied on | 2026-09-16 |

## What is here

| Directory | Files | What each one is |
| --- | --- | --- |
| `Invoice-unit-UBL/` | 206 | One `<testSet>` per rule. Every `<test>` inside carries an invoice fragment and its own expectation: `<success>BR-xx</success>` means that rule must **not** fire, `<error>BR-xx</error>` means it must. |
| `CreditNote-unit-UBL/` | 71 | The same, for the CreditNote syntax. |
| `examples/` | 18 | Whole invoices the artefact ships as examples. None of them may produce a fatal finding. |

Together: **1129 cases over 201 rules**.

The expectations come from the standard, which is the point. A translation defect in
`tools/en16931-compile-schematron.php` cannot be argued away against a falsifier this repository
wrote itself.

## What is missing on purpose

`FT G2G_TD01 con Allegato, Bonifico e Split Payment.xml` (3.3 MB, an Italian example carrying an
embedded attachment) is left out: it is 94 % of the corpus by size and adds no rule the other files
do not reach. It passes — run the conformance tool against a full artefact checkout to see it:

```bash
php tools/en16931-schematron-conformance.php --corpus=<checkout of the artefact repository>
```

## Do not edit

Changing a file here changes what the gate measures. To move to a newer artefact, replace the whole
directory from the release, rename it after the new version, regenerate the rule table with
`php tools/en16931-compile-schematron.php --download`, and let the conformance run say whether the
translations still hold.
