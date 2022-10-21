# Using the BTCPay Server payment plugin for WHMCS

## Prerequisites

- PHP version 8.1 or newer, lower versions may work but are not maintained
- The curl, gd, intl, json, gmp (or bcmath) and mbstring PHP extensions are available
- WHMCS ([Download and installation instructions](https://download.whmcs.com/))
- You have a BTCPay Server version 1.3.0 or later, either [self-hosted](https://docs.btcpayserver.org/Deployment/) or [hosted by a third-party](https://docs.btcpayserver.org/Deployment/ThirdPartyHosting/)
- [You've a registered account on the instance](https://docs.btcpayserver.org/RegisterAccount/)
- [You've a BTCPay store on the instance](https://docs.btcpayserver.org/CreateStore/)
- [You've a wallet connected to your store](https://docs.btcpayserver.org/WalletSetup/)


## Installation

Extract these files into the WHMCS directory on your webserver (parent directory of
modules/folder).

## Configuration

1. Take a moment to ensure that you have set your store's domain and the WHMCS System URL under **whmcs/admin > Settings Menu > Apps & Integrations**.
2. Create a "Legacy API Key" on your BTCPay Server store account dashboard:
  * Log into your BTCPay Server store with username/password.
  * On the left side of the screen, choose **Settings**.
  * Select the tab **Access Tokens**.
  * Below the "Legacy API Keys" headline click on the  **Generate** button to instantly create a new one.
  * Select and copy the entire string for the new API Key ID that you just created. It will look something like this: 43rp4rpa24d6Bz4BR44j8zL44PrU4npVv4DtJA4Kb8.
3. In the admin control panel, go to **Settings > Apps & Integrations**, on the top right searchbar, search for **BTCPay**. In the list of modules you will see "BTCPay Server (legacy API)".
  * If you can't find the BTCPay Server plugin in the list of payment gateways -or- in the WHMCS app store, then you may clone this repo and copy modules/gateways into your <whmcs root>/modules/gateways/.
4. Paste the API Key ID string that you created and copied from step 2. 
5. Choose a transaction speed. This setting determines how quickly you will receive a payment confirmation from BTCPay Server after an invoice is paid by a customer.
  * High: A confirmation is sent instantly once the payment has been received by the gateway.
  * Medium: A confirmation is sent after 1 block confirmation (~10 mins) by the bitcoin network (recommended).
  * Low: A confirmation is sent after the usual 6 block confirmations (~1 hour) by the bitcoin network.
6. Click **Save Changes**.

You're done!

## Usage

When a client chooses the BTCPay Server payment method, they will be presented with an invoice showing a button they will have to click on in order to pay their order.  Upon requesting to pay their order, the system takes the client to a full-screen invoice page of your BTCPay Server where the client is presented with payment instructions.  Once payment is received, a link is presented to the shopper that will return them to your website.

**NOTE:** Don't worry!  A payment will automatically update your WHMCS store whether or not the customer returns to your website after they've paid the invoice.

In your WHMCS control panel, you can see the information associated with each order made via BTCPay Server by choosing **Orders > Pending Orders**.  This screen will tell you whether payment has been received by the BTCPay Server instance. You can also view the details for any paid invoice inside your BTCPay store dashboard under the **Invoices** page.

**NOTE:** This extension does not provide a means of automatically pulling a current BTC exchange rate for presenting BTC prices to shoppers.  If you want to have a BTC currency in your installation, you must update the exchange rate manually.
