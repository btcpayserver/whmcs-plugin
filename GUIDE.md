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

1. Download the latest release from the [releases page](https://github.com/btcpayserver/whmcs-plugin/releases). e.g. BTCPay-WHMCS-Plugin-v3.1.0.zip
2. Extract the .zip file which will result in a `modules/gateways/btcpay` directory
3. Copy those files into your WHMCS root directory or copy only the `btcpay` directory so it ends up in the `modules/gateways/` directory
4. Double check that you now have files in `PATH_TO_WHMCS/modules/gateways/btcpay/` directory

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
8. Click **Save Changes**.

Congrats, setup is done. Now test if the payment works.

## Usage

When a client chooses the BTCPay Server payment method, they will be presented the option to pay with Bitcoin via BTCPay Server. When clicking on "Complete order" button, they get redirected to a full-screen invoice page of your BTCPay Server where the client is presented with payment instructions.  Once payment is received, they can click "Back to store" to return to your website (by default they will be redirected to the order confirmation page).

**NOTE:** In case of on-chain payments that need to get included in a block your customer does not need to wait, the payment status will get updated automatically over a webhook and mark the order as paid, which will trigger the corresponding email confirming the payment.

In your WHMCS control panel, you can see the information associated with each order made via BTCPay Server by choosing **Orders > Pending Orders**.  This screen will tell you whether payment has been received by the BTCPay Server instance. You can also view the details for any paid invoice inside your BTCPay store dashboard under the **Invoices** page.

**NOTE:** This extension does not provide a means of automatically pulling a current BTC exchange rate for presenting BTC prices for your products to shoppers. This plugin only provides the means to accept payments in BTC and Lightning Network payments. 