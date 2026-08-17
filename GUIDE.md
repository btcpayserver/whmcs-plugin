# Using the BTCPay Server payment plugin for WHMCS

## Prerequisites

- PHP version 8.1 or newer, lower versions may work but are not maintained
- The bcmath, curl, gd, intl, json and mbstring PHP extensions are available
- WHMCS ([Download and installation instructions](https://download.whmcs.com/))
- You have a BTCPay Server version 1.3.0 or later, either [self-hosted](https://docs.btcpayserver.org/Deployment/) or [hosted by a third-party](https://docs.btcpayserver.org/Deployment/ThirdPartyHosting/)
- [You've a registered account on the instance](https://docs.btcpayserver.org/RegisterAccount/)
- [You've a BTCPay store on the instance](https://docs.btcpayserver.org/CreateStore/)
- [You've a wallet connected to your store](https://docs.btcpayserver.org/WalletSetup/)


## Installation

1. Download the latest release from the [releases page](https://github.com/btcpayserver/whmcs-plugin/releases). e.g. BTCPay-WHMCS-Plugin-v3.1.0.zip
2. Extract the .zip file which will result in a `modules/gateways/btcpay` directory
3. Copy those files into your WHMCS root directory or copy only the `btcpay` directory so it ends up in the `modules/gateways/` directory
4. Double check that you now have files in `PATH_TO_WHMCS/modules/gateways/btcpay/` directory

### Security upgrade note

This version creates a `mod_btcpay_invoice_contracts` table in the WHMCS database before it creates the first new BTCPay invoice. The WHMCS database user must have permission to create this table.

Callbacks for BTCPay invoices created by older plugin versions are intentionally rejected because they have no persisted amount, currency, or invoice-ID contract. Deploy the update when no BTCPay checkout is active and allow the normal BTCPay invoice-expiration window to pass before replacing the old files.

Invoice creation now requires an authenticated WHMCS client session that owns the invoice. Callback and return URLs are loaded from WHMCS rather than accepted from the browser, and buyer profile data is not sent to BTCPay. Repeated or concurrent submissions reuse a matching active BTCPay invoice.

The callback accepts only bounded JSON POST requests. If BTCPay later reports an already-credited transaction as `invalid` or unexpectedly `expired`, the plugin does not reverse accounting automatically; it records a **MANUAL REVIEW REQUIRED** entry in the gateway transaction log, WHMCS activity log, and PHP error log. An administrator must reconcile that invoice and transaction.

## Configuration

### Step 1: On WHMCS
1. Navigate to **Apps & Integrations** in your admin dashboard.
2. Search for "BTCPay" and click on the result.
3. Click on "**Activate**" button to get to the configuration screen.
4. Head over to your BTCPay Server instance to create an API key.

### Step 2: On your BTCPay Server instance (open in a new tab/browser window)
1. Log in with your user.
2. Select the store you want to connect to. Make sure it has setup a wallet so you can receive payments.
3. Create a "Legacy API Key" on your BTCPay Server store account dashboard:
  * On the left side of the screen, choose **Settings**.
  * Select subnavigation entry **Access Tokens**.
  * Below the "Legacy API Keys" headline click on the **Generate** button to instantly create a new one.
  * Select and copy the entire string for the new API Key ID that you just created. It will look something like this: `43rp4rpa24d6Bz4BR44j8zL44PrU4npVv4DtJA4Kb8`.

### Step 3: Back on WHMCS
1. Make sure "**Show on Order Form" is checked.
2. Change "**Display Name**" to what you prefer e.g. "Bitcoin / Lightning Network payments"
3. Paste the api key that you created and copied from step 2 above in the field "**Legacy API Key**".
4. In "**BTCPay Server URL**" enter the domain from your own BTCPay Server instance (e.g. https://mainnet.demo.btcpayserver.org). 
5. (optional) In "**BTCPay Server Tor URL**" you can enter the BTCPay Server's .onion address. Note: this will only work if your WHMCS is also reachable over Tor and your users use Tor Browser.
6. (optional) In "**Redirect URL after invoice**" you can set a custom URL where the customer gets redirected after successful payment. If not it will redirect to the invoice page.
7. Set "**Transaction Speed**" field. This setting determines how quickly you will receive a payment confirmation from BTCPay Server after an invoice is paid by a customer.
  * High: A confirmation is sent instantly once the payment has been received by the gateway, means 0-conf, do not use.
  * Medium: A confirmation is sent after 1 block confirmation (~10 mins) by the bitcoin network (**<== recommended setting**).
  * Low: A confirmation is sent after the usual 6 block confirmations (~1 hour) by the bitcoin network.
8. (optional, while troubleshooting) Check **Callback Diagnostics**. This records safe callback stages in the WHMCS Gateway Log, Activity Log, and PHP error log. It does not record callback bodies, cookies, buyer details, or API credentials.
9. Click **Save Changes**.

Congrats, setup is done. Now test if the payment works.

## Usage

When a client chooses the BTCPay Server payment method, they will be presented the option to pay with Bitcoin via BTCPay Server. When clicking on "Complete order" button, they get redirected to a full-screen invoice page of your BTCPay Server where the client is presented with payment instructions.  Once payment is received, they can click "Back to store" to return to your website (by default they will be redirected to the order confirmation page).

**NOTE:** In case of on-chain payments that need to get included in a block your customer does not need to wait on the checkout page. The browser return does not mark the invoice paid. BTCPay sends a separate server-to-server legacy IPN when the configured confirmation threshold is reached; the plugin then marks the WHMCS invoice paid and triggers the corresponding WHMCS actions.

In your WHMCS control panel, you can see the information associated with each order made via BTCPay Server by choosing **Orders > Pending Orders**.  This screen will tell you whether payment has been received by the BTCPay Server instance. You can also view the details for any paid invoice inside your BTCPay store dashboard under the **Invoices** page.

**NOTE:** This extension does not provide a means of automatically pulling a current BTC exchange rate for presenting BTC prices for your products to shoppers. This plugin only provides the means to accept payments in BTC and Lightning Network payments.

## Callback troubleshooting

The payment return URL and payment notification URL serve different purposes. A successful return to `viewinvoice.php?id=...&paymentsuccess=true` only proves that the customer's browser can reach WHMCS. It does not prove that the BTCPay server can POST to:

```text
https://your-whmcs.example/modules/gateways/callback/btcpay.php
```

For an on-chain payment, a `paid` callback is recorded but does not credit WHMCS yet. With **medium** transaction speed the invoice is credited after one block confirmation; with **low**, after six. Lightning normally reaches the confirmation stage immediately.

To trace a payment:

1. Enable **Callback Diagnostics** in the BTCPay gateway configuration and save the settings.
2. Open the affected invoice in BTCPay Server and inspect its **Events** section. Legacy notifications appear as `IPN ... sent` or `Error while sending IPN ...`. An HTTP 403, timeout, DNS error, or TLS error means the request did not complete successfully.
3. In WHMCS, open **Billing > Gateway Log** and **Configuration > System Logs > Activity Log**, then search for `BTCPay callback` or the trace ID. The useful stages are `request_received`, `invoice_verified`, `awaiting_confirmation`, `payment_applied`, `duplicate_callback`, and `callback_rejected`.
4. Disable **Callback Diagnostics** after testing to avoid unnecessary log volume.

Interpret the result as follows:

| Last evidence | Meaning |
| --- | --- |
| BTCPay reports an IPN error and WHMCS has no `request_received` entry | A firewall, reverse proxy, IP allowlist, DNS, or TLS layer blocked the request before PHP. |
| `request_received`, followed by `callback_rejected` | PHP received the request. Use the trace ID and HTTP status in the WHMCS logs to locate the validation or upstream API error. |
| `invoice_verified` with `status: paid`, followed by `awaiting_confirmation` | The callback works; the selected confirmation threshold has not been reached yet. |
| `payment_applied` | `addInvoicePayment()` completed and WHMCS should contain an invoice transaction. |

In the current callback, HTTP 400 is reserved for a malformed request or invalid BTCPay invoice ID. HTTP 409 indicates that the authenticated invoice has no persisted mapping or does not match its WHMCS contract. The Gateway Log includes the specific verification error.

If the WHMCS installation is IP-restricted, allowing the DNS A record of the BTCPay hostname may not be sufficient: the server's outbound source address can differ because of NAT, a reverse proxy, IPv6, or hosting infrastructure. Determine the actual source address from the firewall/access log or BTCPay's IPN error details. The simplest reliable setup is to make only the exact callback path public (optionally rate-limited) while keeping the rest of WHMCS restricted. The callback does not trust the posted payment state: it re-fetches the invoice through the authenticated BTCPay API and validates its persisted invoice ID, amount, currency, and WHMCS mapping before applying payment.
