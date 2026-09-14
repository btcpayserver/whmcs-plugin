# Using the BTCPay Server payment plugin for WHMCS

This guide covers plugin **v4.0.0**, which uses the BTCPay Server Greenfield API and signed webhooks.

> **Upgrading from the legacy API:** v3.3.0 was the latest legacy plugin release. Its [installation and configuration guide is preserved at the release commit](https://github.com/btcpayserver/whmcs-plugin/blob/f4f1c41d662afa0d12faf4a15308bcbc4a6d8896/GUIDE.md). [BTCPay Server 2.4.4](https://github.com/btcpayserver/btcpayserver/releases/tag/v2.4.4) removed the legacy BitPay Basic-auth API keys used by plugin 3.x as part of API-key security hardening. Plugin 3.x therefore cannot connect to BTCPay Server 2.4.4 or later. Greenfield also exists in earlier BTCPay Server releases: **2.4.4 is not a minimum requirement for plugin 4.0.0**. Use a maintained BTCPay Server release with the Greenfield invoice, API-key and webhook endpoints.

## Prerequisites

- PHP 8.1 or newer with bcmath, curl, json and mbstring.
- A WHMCS version compatible with your PHP version. The previous plugin was tested with WHMCS 9.0.6; v4 should be verified on your staging installation before production.
- A BTCPay Server account, a store, and a connected wallet or Lightning node.
- Outbound connectivity from WHMCS to BTCPay, and inbound connectivity from BTCPay to the WHMCS callback URL.
- The WHMCS database user needs CREATE and ALTER privileges for the plugin's invoice mapping table.

## Install the files

For an existing v3.x installation, follow the dedicated [v3.x to v4.x upgrade checklist](#upgrade-from-v3x-to-v4x) instead.

1. Back up your WHMCS database and existing gateway files.
2. Download `BTCPay-WHMCS-Plugin-v4.0.0.zip` from the [releases page](https://github.com/btcpayserver/whmcs-plugin/releases). Choose the attached plugin ZIP, not GitHub's automatically generated source archive.
3. Extract it and merge the archive's **`modules/` and `includes/` directories** into your existing WHMCS directories, overwriting matching BTCPay files only. Do not replace or delete the WHMCS directories themselves or unrelated modules/hooks. The plugin files are:
   - `modules/gateways/btcpay.php`
   - `modules/gateways/btcpay/`, including `vendor/`
   - `modules/gateways/callback/btcpay.php`
   - `includes/hooks/btcpay.php` — adds the administrator-side JavaScript for the explicit webhook setup button; it does not handle settings saves.
4. Check that `modules/gateways/btcpay/vendor/autoload.php` exists on the server. Composer is not required on the production server when installing the release ZIP.

Continue with [Configure BTCPay Server](#configure-btcpay-server) and [Configure WHMCS](#configure-whmcs).

## Upgrade from v3.x to v4.x

This is an **in-place file upgrade**, not a gateway reinstallation. Keep the existing `btcpay` gateway activated; do not deactivate, delete or recreate it. Existing WHMCS invoices and settings remain associated with the same gateway.

1. **Prepare and back up.** Rehearse the upgrade on staging before production. Check the [prerequisites](#prerequisites), back up the WHMCS database and existing BTCPay plugin files, and arrange a maintenance window without new BTCPay checkouts. Keep using the **same BTCPay server and store** so existing invoices can be reconciled.
2. **Download the packaged release.** Get the attached v4.x plugin ZIP from the [releases page](https://github.com/btcpayserver/whmcs-plugin/releases), for example `BTCPay-WHMCS-Plugin-v4.0.0.zip`. Do not use GitHub's automatically generated source ZIP: it does not contain the Composer dependencies.
3. **Copy the files over the existing installation.** Extract the ZIP locally. Your WHMCS root is the directory containing WHMCS's `init.php`; upload the following paths relative to that root:

   | Path inside the release ZIP | Action in your WHMCS installation |
   | --- | --- |
   | `modules/gateways/btcpay.php` | Overwrite the existing gateway module. |
   | `modules/gateways/btcpay/` | Merge the complete directory, overwriting matching files. Include every subdirectory, especially `vendor/`, and hidden files such as `.htaccess`. |
   | `modules/gateways/callback/btcpay.php` | Overwrite the existing callback handler. |
   | `includes/hooks/btcpay.php` | Add or overwrite this hook for the webhook setup button beside the gateway settings. Overwrite any hook from an earlier experimental v4 build. |

   You can upload the archive's `modules/` and `includes/` directories together if your file-transfer tool **merges directories and overwrites matching files**. Do not replace the entire WHMCS `modules/` or `includes/` directory, delete unrelated files, or create nested paths such as `modules/modules/`. Uploading only the gateway PHP file is not sufficient.

   **Linux command-line example:** after backing up, run this on the WHMCS server from the directory containing the downloaded plugin ZIP. Replace `/var/www/whmcs` with your actual WHMCS root and adjust the ZIP filename if needed. Use the WHMCS file owner or your deployment account with write access.

   ```bash
   (
     set -eu
     btcpay_whmcs_root="/var/www/whmcs"
     test -f "$btcpay_whmcs_root/init.php"

     btcpay_upgrade_dir="$(mktemp -d)"
     unzip -q "BTCPay-WHMCS-Plugin-v4.0.0.zip" -d "$btcpay_upgrade_dir"
     test -f "$btcpay_upgrade_dir/modules/gateways/btcpay/vendor/autoload.php"
     test -f "$btcpay_upgrade_dir/includes/hooks/btcpay.php"

     cp -Rv -- "$btcpay_upgrade_dir/modules" "$btcpay_upgrade_dir/includes" "$btcpay_whmcs_root/"
     echo "Temporary extracted files: $btcpay_upgrade_dir"
   )
   ```

   The checks stop the example if the destination has no WHMCS `init.php` or the archive lacks required plugin files. `cp -R` merges both directories, includes hidden files such as `.htaccess`, and overwrites matching files without deleting unrelated files or leftover legacy files. Check the command's output for errors and ensure the copied files are readable by WHMCS. The temporary extraction directory can be removed after verification. Continue with the checks and configuration changes below; copying alone does not finish the upgrade.

4. **Verify the upload.** Confirm that both `modules/gateways/btcpay/vendor/autoload.php` and `includes/hooks/btcpay.php` exist. All matching BTCPay files from the ZIP must be updated together, including any previous experimental automatic-save hook. You do not need Composer on the production server when using the packaged release. **Reload the gateway settings page after copying the files** to load the current webhook setup button and JavaScript.
5. **Optionally remove obsolete legacy files.** After the upload, you may delete only `modules/gateways/btcpay/bp_lib.php` and `modules/gateways/btcpay/bp_options.php`. Leaving them in place is also safe: v4 never loads them. Keep the other `bp_*.php` helpers supplied in the new ZIP.
6. **Update the existing gateway settings and set up the webhook.** Create a store-scoped Greenfield API key with the permissions in [Configure BTCPay Server](#configure-btcpay-server). Replace the legacy API key, enter the same store's **Store ID**, and normally leave **Manual Webhook Secret** blank. Existing server URL, Tor URL, redirect URL, transaction speed, display name and currency conversion settings can be retained. Save and wait for WHMCS to confirm success, then click **Set up / repair webhook** beside the manual-secret setting. Confirm **Webhook ready**. If the inline button is unavailable, open **Connection, webhook setup status and invoice recovery** and use **Set up / repair webhook** there. Saving settings alone does not register a webhook; see [Configure WHMCS](#configure-whmcs) for the complete steps.
7. **Preserve and reconcile existing payments.** Do not delete or recreate `mod_btcpay_invoice_contracts` if it exists. The plugin automatically adds its connection identifier when it accesses the table, preserving stored amounts, currencies, invoice IDs and processing state. Open the connection/recovery page, click **Check connection**, then run **Reconcile saved invoices** through every batch as described in [Reconcile payments during an upgrade](#reconcile-payments-during-an-upgrade). Older v3.x invoices without a persisted mapping require manual reconciliation. Unsigned legacy notifications are no longer accepted after the file upgrade.
8. **Test before reopening checkout.** Complete a small payment and confirm it is recorded as paid in WHMCS. Resume new BTCPay checkouts only after setup and payment verification succeed. Run reconciliation again to catch payments received during the changeover.

The [legacy v3.3.0 guide](https://github.com/btcpayserver/whmcs-plugin/blob/f4f1c41d662afa0d12faf4a15308bcbc4a6d8896/GUIDE.md) is available for reference. Restoring v3.x plugin files will not restore legacy API connectivity on BTCPay Server 2.4.4 or later.

## Configure BTCPay Server

1. Open **Account → API Keys** and generate an API key with:
   - `btcpay.store.cancreateinvoice` — Create an invoice.
   - `btcpay.store.canviewinvoices` — View invoices.
   - `btcpay.store.webhooks.canmodifywebhooks` — Modify stores webhooks, required for the plugin's webhook setup and repair actions.
   - Optional, for future refund support: `btcpay.store.cancreatenonapprovedpullpayments` — Create non-approved pull payments. The [current BTCPay integration guide](https://docs.btcpayserver.org/Development/ecommerce-integration-guide/#permissions) uses this narrower permission for refunds. **v4.0.0 does not implement refunds and does not require this permission.** You can add it now to avoid replacing the key later, or wait until refund support is implemented.
2. Restrict each selected permission to the store used by WHMCS. Copy the key secret when it is shown. An API-key ID (for example, `akid_...`) is not the secret, and a legacy store access token cannot be reused.
3. Copy the **Store ID** from **Store Settings → General**.
4. Continue with the WHMCS settings below. You do not need to create a webhook or copy its secret manually.

The plugin does not need unrestricted access, store-settings management, wallet spending, or API-key management permissions. API-key creation remains manual; the authorization-redirect flow can be added later.

## Configure WHMCS

1. For a new installation, open **Apps & Integrations**, find BTCPay Server, and activate it. For an upgrade, open the existing BTCPay gateway settings. Reload the page if it was already open when you uploaded the plugin files.
2. Enter the **API Key**, **Store ID**, and **BTCPay Server URL**. Normally leave **Manual Webhook Secret** blank.
3. Set the display name, for example “Bitcoin / Lightning Network”.
4. Retain or select **Transaction Speed**:
   - **Medium:** one block confirmation; the default and recommended setting.
   - **Low-Medium:** two confirmations.
   - **Low:** six confirmations.
   - **High:** zero confirmations; accepts unconfirmed on-chain payments.
   - **Store default:** use the speed policy configured in BTCPay.
5. Optional: enter a **BTCPay Server Tor URL**. It is used for browser checkout when WHMCS is accessed through its .onion hostname. Server-to-server API calls still use the main BTCPay URL.
6. Optional: set a **Redirect URL**. With it blank, the customer returns to their WHMCS invoice page. Returning to WHMCS does not itself mark an invoice paid.
7. Optional: enable **Send Customer Email** to provide the invoice owner's WHMCS email address as `buyerEmail` on new BTCPay invoices. This is **disabled by default**, including after an upgrade, and lets BTCPay store email rules use the address. Configure those rules in BTCPay if you want it to send buyer emails. **Warning:** enabling this sends the customer's email address to BTCPay Server and may expose this customer data if the BTCPay invoice ID or checkout link is leaked.
8. Click WHMCS's **Save Changes** button and **wait for WHMCS to confirm that the settings were saved successfully**. This saves the gateway settings only: it does not contact BTCPay or register a webhook. The setup action below uses saved settings, not unsaved values still in the form.
9. Click **Set up / repair webhook** beside **Manual Webhook Secret**, then wait for the green **Webhook ready** message below the button. The button sends a separate request to the plugin's authenticated WHMCS endpoint. The server reads the saved credentials, checks the three required permissions, and registers an enabled webhook with automatic redelivery. BTCPay generates the secret; the webhook ID, URL, server/store identity and secret are stored through WHMCS's encrypted gateway-settings model, separately from the editable form fields. Only the setup status is returned to the browser; **Manual Webhook Secret stays blank** for a managed webhook. The saved success message remains green after reloading and on the connection/recovery page. It reports the last setup result, not a live webhook-delivery check.
10. If JavaScript is unavailable or the inline button does not work, follow **Connection, webhook setup status and invoice recovery** beside the manual-secret setting and click **Set up / repair webhook** on that page. This performs the same operation without relying on the gateway form's JavaScript. Alternatively, while logged in as an administrator, open:

    ```text
    https://your-whmcs.example/modules/gateways/btcpay/manage.php
    ```

11. On the connection/recovery page, confirm **Webhook ready** and click **Check connection**. The connection check works even before webhook setup; it checks the saved key's permissions and access to the selected store without creating an invoice or webhook. It does not test inbound webhook delivery: complete a small payment to check that separately.

Both webhook setup entry points require a logged-in administrator with **Configure Payment Gateways** permission in their WHMCS administrator role. This is the [native WHMCS permission name](https://developers.whmcs.com/api-reference/getadmindetails/), separate from the BTCPay API-key permissions. Incomplete uploads still allow the gateway settings page to open; reinstall the complete ZIP if dependencies are missing. After changing the saved API key, server/store or WHMCS System URL, run **Set up / repair webhook** again. Never share an API key or webhook secret in screenshots or troubleshooting logs.

### Webhook setup and recovery

The callback URL is built from WHMCS's configured **System URL**, including any installation subdirectory:

```text
https://your-whmcs.example/modules/gateways/callback/btcpay.php
or
https://your-whmcs.example/whmcs/modules/gateways/callback/btcpay.php
```

The registered events are `InvoiceCreated`, `InvoiceReceivedPayment`, `InvoicePaymentSettled`, `InvoiceProcessing`, `InvoiceSettled`, `InvoiceExpired` and `InvoiceInvalid`. The browser return URL is separate.

- Clicking **Set up / repair webhook** again reuses the saved webhook ID and secret. It repairs event subscriptions, automatic redelivery and the enabled state. Updating the callback URL preserves the secret; a deleted webhook is recreated with a new BTCPay-generated secret. Ordinary settings saves do none of these operations.
- API failures do not delete or replace the existing webhook. Fix the credentials, permission, connectivity or upload problem, save any changed settings and wait for WHMCS's success confirmation, then click **Set up / repair webhook** again. The setup result is displayed beside the button or on the connection/recovery page.
- If you already created a manual webhook at this exact callback URL and the plugin has no internal webhook record yet, enter its secret in **Manual Webhook Secret**, save successfully, then click **Set up / repair webhook**. The plugin can adopt that webhook. The generated/managed secret is kept internally, so submitting an old settings form cannot overwrite it. Clearing the manual field does not reset a managed webhook.
- If a webhook already targets the callback URL but the plugin has lost its secret or retains a stale internal record, normal setup stops instead of creating a duplicate or silently changing its secret. Use the confirmed **Re-register webhook** action below. Multiple webhooks for the same callback URL need manual review.
- Webhooks for other callback URLs are left alone. Changing the BTCPay server or store never reuses the previous connection's secret. Do not change either while old invoices still need reconciliation.

**Separate from WHMCS Save:** webhook setup is an explicit administrator action. The plugin does not intercept WHMCS's AJAX settings save, rely on model save events, or register webhooks during page loads. `includes/hooks/btcpay.php` only adds the administrator-side JavaScript for the button through WHMCS's documented [AdminAreaFooterOutput hook](https://developers.whmcs.com/hooks-reference/output#adminareafooteroutput). The button calls the plugin's authenticated, CSRF-protected endpoint using saved credentials; it does not call BTCPay directly or insert a generated secret into the settings form. No core WHMCS files need changes.

After uploading a new build, **reload the gateway settings page** to load the new button and JavaScript. If the credentials were already saved correctly, there is no need to save them again: click **Set up / repair webhook**.

- **Button missing:** confirm that your WHMCS administrator role has **Configure Payment Gateways** permission and that all plugin files were overwritten together.
- **Button does nothing:** confirm that the updated `includes/hooks/btcpay.php` and `modules/gateways/btcpay/webhook-setup.js` were uploaded, then reload the page. Use the same action on the connection/recovery page if JavaScript remains unavailable.
- **Connection page reports an unconfigured admin directory:** an earlier experimental v4 build incorrectly bootstrapped this standalone endpoint as an admin-directory page. Upload the current complete ZIP, including `modules/gateways/btcpay/manage.php`. Do not rename your admin directory or change `$customadminpath` to work around this plugin error; the endpoint remains under `modules/gateways/btcpay/` and checks the existing admin login and permission itself.
- **Setup fails:** read the displayed message and inspect the PHP error log for `BTCPay`; do not expose credentials.

Verify settings persistence, explicit setup and a payment on your WHMCS installation before production use.

### Re-register a webhook or rotate its secret

Use this explicit action when the saved secret is lost, the internal webhook record is stale or damaged, or you deliberately want a fresh secret. **Set up / repair webhook** preserves a working secret instead; normal settings saves do not change the managed webhook.

1. Open **Connection, webhook setup status and invoice recovery** as an administrator with **Configure Payment Gateways** permission.
2. In **Re-register webhook**, review the displayed server, store and callback URL. Check the replacement confirmation box, then click **Re-register webhook**. Confirmation expires after 15 minutes and cannot be reused; changing the saved connection or webhook also invalidates it.
3. The plugin identifies the saved WHMCS webhook, or a single webhook at the exact callback URL if the record was lost. It creates an enabled replacement with automatic redelivery and a new BTCPay-generated secret, commits that secret through WHMCS's encrypted settings model, and only then deletes the previous webhook. No secret needs to be copied or displayed.
4. Confirm **Webhook ready**, run **Reconcile saved invoices** through all batches, and test a payment. Notifications signed with the old secret are no longer accepted, and the replacement does not replay all earlier settlements. Deleting the previous webhook also removes its delivery history.

The action leaves unrelated webhooks alone. If WHMCS's installation URL moved, it can replace the saved WHMCS webhook at the previous callback URL. It refuses ambiguous matches or a saved webhook whose remote URL was changed to an unrelated destination; inspect those in BTCPay first.

If creation or local persistence fails, the previous webhook is not deleted. Remote creation cannot be rolled back together with the WHMCS database: a timeout or failed save can leave an extra webhook in BTCPay. Inspect the store webhooks and remove only the unused replacement before retrying. If deletion of the previous webhook fails after the new secret was saved, the new configuration is retained and a warning identifies the previous webhook for manual inspection/removal. Do not delete the new active webhook. Reconcile saved invoices after recovery.

## Reconcile payments during an upgrade

For invoices created with v3.3.0's persisted mapping, the existing BTCPay invoice can be fetched through Greenfield and checked against its saved WHMCS amount and currency. Checkout reuse and signed webhooks continue to use that mapping.

A webhook registered today does not retroactively deliver every earlier settlement. On the connection/recovery page, click **Reconcile saved invoices**, then **Continue reconciliation** until the scan completes. Each submission checks up to five unprocessed mappings, including expired invoices that may since have been manually settled.

- A settled invoice is credited exactly once, in its original WHMCS currency and amount.
- A pending invoice remains pending.
- Changed amounts, currencies, payment methods or server/store connections require manual review.
- Invoices created by older plugins **without a persisted mapping** cannot be credited automatically. Reconcile those manually against BTCPay and the WHMCS transaction ledger; the plugin never infers a payment mapping from an order number alone.
- Resolve errors and start a new scan to retry. Run another scan after the switch to catch payments received while configuration was being updated.

Unsigned legacy IPNs are rejected after upgrading; existing mapped invoices are recovered through Greenfield or the new signed webhook. If BTCPay was already upgraded to 2.4.4, perform this reconciliation to recover payments whose legacy notifications failed.

After testing a new checkout and verifying that WHMCS records its settlement, enable BTCPay on the order form again.

## Payment behavior

The gateway creates an invoice for the amount determined by WHMCS, applying its configured currency conversion with decimal arithmetic. Repeated submissions reuse a matching active invoice. Buyer profile details are not sent to BTCPay by default. If **Send Customer Email** is enabled, only the invoice owner's saved WHMCS email address is included in new invoices, when non-empty; names, addresses and phone numbers are not sent.

Changing **Send Customer Email** affects newly created BTCPay invoices. Reusing an existing checkout does not add or remove its metadata. Disabling the setting does not remove email addresses already sent to BTCPay.

`Processing` means a full payment has been detected but has not yet settled under the configured speed policy. Only explicit `Settled` status credits WHMCS. Lightning normally settles immediately.

Partial and expired late payments are not automatically credited; inspect them in BTCPay. If an administrator explicitly marks an invoice settled there, the resulting settled state is accepted and logged. Overpayments credit only the original WHMCS amount; any excess must be handled separately. New invoices set payment tolerance to zero.

Before crediting, the plugin requires `Settled` with an explicitly reported `additionalStatus` of `None`, `PaidOver`, `Marked` or `PaidLate`. `PaidLate` alone is never sufficient. A missing, invalid, partial or unknown additional status cannot credit WHMCS and requires manual review. Expired or invalid invoices reporting an overpayment are also flagged for manual review.

If a credited invoice later becomes `Invalid` or `Expired`, the plugin records **MANUAL REVIEW REQUIRED** and never reverses accounting automatically.

## Troubleshooting webhooks

1. Temporarily enable **Callback Diagnostics** in the gateway settings.
2. In BTCPay, inspect **Store Settings → Webhooks → [Modify] → Recent deliveries** and the invoice's events. Retry a failed delivery after fixing the cause.
3. In WHMCS, inspect **Billing → Gateway Log**, the Activity Log and PHP error log. Look for the `BTCPay webhook` trace ID. Responses also include `X-BTCPay-WHMCS-Trace`.
4. Disable diagnostics after troubleshooting.

| Result | Meaning |
| --- | --- |
| No `request_received` trace | Check the callback URL, firewall, proxy, DNS and TLS. |
| HTTP 401 | Missing signature or a webhook-secret mismatch. |
| HTTP 400 / 413 / 415 | Malformed, oversized or non-JSON request. |
| HTTP 409 | Wrong store or an invoice no longer matches its saved payment contract. |
| HTTP 502 / 503 | An API, dependency, configuration or storage failure; retry after repair. A very early delivery may also be retried until its invoice mapping is saved. |
| `awaiting_confirmation` | Payment detected; the configured settlement threshold has not been reached. |
| `payment_applied` | WHMCS received the invoice payment. |
| `duplicate_callback` | That mapping was already credited. |
| `unmapped_invoice_ignored` | No saved WHMCS mapping; other integrations' invoices are ignored. Check old unmapped payments manually. |

Allow the exact callback path to receive BTCPay POST requests. The outbound address of BTCPay may differ from its hostname's DNS address. Authentication comes from the signed body plus the authenticated invoice fetch and saved mapping, not an IP allowlist.

The module's `.htaccess` protects internal files on Apache. On Nginx, deny direct web access to the contents of `modules/gateways/btcpay/` except `createinvoice.php` and `manage.php`. Keep the separate callback path reachable. The management page enforces administrator authentication itself.

## Development and releases

Source checkouts require Composer:

```sh
composer install
composer test
```

The PHP suite includes `tests/admin_footer_hook_test.php` for script loading and guards against automatic webhook setup during ordinary settings saves. The explicit setup button has JavaScript behavior tests using Node.js (22 or newer): run `node tests/webhook_setup_ui_test.js`. Node.js is only needed for these development tests, not on the WHMCS server.

The standalone invoice example in `tests/standalone_invoice_cli.php` uses environment variables and must be run explicitly; it creates a real BTCPay invoice and is not part of automated tests.

MySQL integration tests use a disposable database named `btcpay_test`, randomly prefixed tables, and the environment variables `BTCPAY_TEST_DB_HOST`, `BTCPAY_TEST_DB_PORT`, and `BTCPAY_TEST_DB_PASSWORD`. Run `composer test-integration` to exercise migration, reconciliation, concurrent settlement, webhook persistence and re-registration commit ordering/failure recovery. These tests substitute WHMCS's accounting and encryption boundaries. They do not replace testing on a real WHMCS installation: verify that settings save successfully, both explicit webhook setup entry points work, confirmed re-registration works, and a full WHMCS/BTCPay payment succeeds on staging before production deployment.

Build the uploadable ZIP with:

```sh
bash scripts/build-release.sh v4.0.0
```

The build installs only production dependencies from `composer.lock` and verifies the extracted ZIP, including leftover legacy files and missing dependencies. It produces `dist/BTCPay-WHMCS-Plugin-v4.0.0.zip`. Run `composer install` afterward to restore development dependencies if needed. Dependencies are never committed; the GitHub release workflow installs and packages them.
