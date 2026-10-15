# Changelog

## 2.3.0

After updating: deactivate and reactivate the module once, so `sql/update_003.sql` adds the `entity` column to `llx_peppol`.

Security (internal guideline for custom modules):

- Webhook (`public/hook.php`) refuses every call while no secret key is set (`PEPPOL_AP_WEBHOOK_KEY`, now configurable in the setup page), checks that the module is enabled, reads headers case-insensitively, no longer returns error details or logs the expected signature, and stores a new document in one transaction. The duplicate check queried a non-existing table and now uses `llx_peppol_peppolimport`, filtered on entity.
- Setting the Peppol ID of a third party (`search.php`, `peppol_search_directory.php`) requires a valid token, the module write right, the third party write right and access to that third party.
- Sending an invoice through Peppol requires the module write right and the invoice write right; the hook only acts on the invoice card.
- Hooks `afterPDFCreation`, `afterODTCreation` and `addMoreActionsButtons` return 0 when there is nothing to do or the Peppol file cannot be made, so PDF generation and surrounding actions no longer fail.
- API keys are no longer shown on the setup page; an empty field keeps the current key. The test key is stored as `PEPPOL_AP_DEV_API_KEY` (encrypted); the old `PEPPOL_AP_API_KEY_DEV` is read until the key is saved again.
- Output from the access point is escaped in the Peppol tab and on the search page.
- Connections to Peppyrus and the Peppol directory are limited to HTTPS.
- Pages check that the module is enabled and the read right (`index.php`, `peppol_tab.php`, `peppolimport_card.php`); `analyze` and `refresh` require the write right and a token.
- Removed `myobject_contact.php` (template leftover that referred to a non-existing class).

Fixes:

- No TypeError when a buyer without VAT number and Peppol ID has no email address; a warning is shown instead.
- `peppolAddLog()` produced invalid SQL, so nothing was logged; fixed, with an `entity` column.
- Reference numbering of imports used an undefined variable and never filtered on entity.
- `$conf->global` replaced by `getDolGlobalString()` / `getDolGlobalInt()`.

Requirements: Dolibarr 24, PHP 8.1.
