# Description

Bitcoin payment plugin for WHMCS using the BTCPay Server.

Version 4 uses the official BTCPay Server Greenfield PHP library and signed webhooks.
Download the attached release ZIP for a ready-to-upload installation; dependencies
are included. Source checkouts require `composer install`.

Upgrading from version 3 requires a Greenfield API key and store ID. Saving the
settings registers a webhook and securely stores its BTCPay-generated secret.
Upload both `modules/` and `includes/` from the release ZIP.
See the [installation and upgrade guide](GUIDE.md), including recovery of payments
whose legacy notifications were missed. The legacy integration no longer works
with BTCPay Server 2.4.4 or later.

## Quick Start Guide

To get up and running with our plugin quickly, see the GUIDE here: https://github.com/btcpayserver/whmcs-plugin/blob/master/GUIDE.md

## Support

* PHP 8.1+ with bcmath, curl, json and mbstring
* WHMCS 9.0.6 was tested with v3; validate v4 on your staging installation
* [GitHub Issues](https://github.com/btcpayserver/whmcs-plugin/issues)
* Open an issue if you are having troubles with this plugin

**WHMCS Support:**

* [Homepage](https://www.whmcs.com/)
* [Documentation](https://docs.whmcs.com/)
* [SupportForums](https://whmcs.community/)

## Troubleshooting

1. Ensure a valid SSL certificate is installed on your server. Also, ensure your root CA cert is updated. If your CA cert is not current, you will see curl SSL verification errors.
2. Verify that your web server is not blocking POST requests from servers it may not recognize. Double-check this on your firewall as well, if one is being used.
3. Check the version of this plugin against the official plugin repository to ensure you are using the latest version. Your issue might have been addressed in a newer version! See the [Releases](https://github.com/btcpayserver/whmcs-plugin/releases/latest) page for the latest.
4. If all else fails, contact us using one of the methods described in the Support section above.

**TIP**: When contacting support, it will help us if you provide:

* WHMCS and BTCPay Server Plugin version
* PHP version used
* WHMCS logs and Web server error logs
* Screenshots of error messages

## Contribute

Would you like to help with this project? Great! You don't have to be a developer, either. If you've found a bug or have an idea for an improvement, please open an [issue](https://github.com/btcpayserver/whmcs-plugin/issues) and tell us about it.

If you *are* a developer wanting to contribute an enhancement, bugfix or other patch to this project, please fork this repository and submit a pull request detailing your changes. We review all PRs!

This open source project is released under the [MIT license](http://opensource.org/licenses/MIT) which means if you would like to use this project's code in your own project you are free to do so. This plugin is forked from the BitPay WHMCS plugin.

## License

Please refer to the [LICENSE](https://github.com/btcpayserver/whmcs-plugin/blob/master/LICENSE) file that came with this project.
