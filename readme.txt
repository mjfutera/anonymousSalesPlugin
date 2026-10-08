=== Anonymous Sales ===
Contributors: mjfutera
Tags: crypto, digital downloads, anonymous, sell files, nowpayments
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sell digital files for cryptocurrency without collecting any customer data. Orders are anonymous, payments go through NOWPayments.

== Description ==

Anonymous Sales lets you sell files (or access to links) from your WordPress site without asking buyers for a name, an e-mail address or an account. The buyer picks a cryptocurrency, pays, and keeps an order number plus a secret receipt token. That pair is the only thing that identifies an order.

**What you get**

* Offers with several files or links each, a price in USD and a description (Markdown supported).
* A "Buy" shortcode and an "Order status" shortcode for your pages, and a small script tag to open the buy dialog from any other site.
* Payment in the cryptocurrencies your NOWPayments account accepts. The plugin checks which coins are enabled and their minimum amounts.
* After payment the buyer downloads a PDF "access sheet" with the links and passwords of the files. No account, no e-mail.
* Promo codes, manual order status changes and a list of orders for the site owner.
* No customer data is collected by this plugin: no name, no e-mail, no address.

**Links**

* Author: https://michalfutera.pro
* Second creator: https://scalewithisaiah.com
* FAQ and guides: https://faq.michalfutera.pro
* MFPro Blog MCP, a separate service by the same author that connects WordPress to AI assistants (optional, this plugin works without it): https://blogmcp.michalfutera.pro
* Source code, issues and contributions: https://github.com/mjfutera/anonymousSalesPlugin

**You need your own NOWPayments account.** The plugin does not process payments itself and has no fee of its own. How to create the account and get the keys: https://faq.michalfutera.pro/nowpayments-create-account-and-get-keys . Note: that guide contains the author's affiliate (referral) link to NOWPayments; the author may receive a commission, you pay nothing extra.

**Privacy by design:** the receipt token is stored encrypted, the plugin logs nothing unless you switch the debug log on, and nothing is sent to the plugin author.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install it from the Plugins screen, then activate it.
2. Open **Anonymous Sales -> Settings** and paste the API key and the IPN secret key from your NOWPayments account.
3. Copy the webhook (IPN callback) address shown in Settings into your NOWPayments account settings.
4. Create an offer with **Anonymous Sales -> Offers**, add its files, and place the shortcode shown next to the offer on any page.

== Frequently Asked Questions ==

= Do I need a NOWPayments account? =

Yes. Payments are processed by NOWPayments (see "External services" below). You need an account, an API key and an IPN secret from them.

= What do buyers need to keep? =

The order number and the secret token shown after the order is created. There is no account and no e-mail, so an order cannot be recovered without them.

= Does the plugin send data to its author? =

No. It never contacts the author's servers. The only server it talks to is NOWPayments.

= Where are the logs? =

Debug logging is off by default. If you switch it on (Settings -> Lifecycle and logs), the log is written to `wp-content/uploads/anonymous-sales/`. It is capped at 1 MB and removed when you delete the plugin.

= What happens to my data when I deactivate or delete the plugin? =

Deactivating never deletes data. When you delete the plugin, offers, orders and settings are removed only if you ticked "Delete all plugin data when the plugin is deleted" in Settings.

== External services ==

This plugin connects to one external service, **NOWPayments** (https://nowpayments.io), to create and check cryptocurrency payments. Nothing is sent until you enter your API key in Settings.

* **What for:** creating an invoice or payment for an order, listing the cryptocurrencies your account accepts, reading minimum amounts, and checking the status of a payment (also from a WP-Cron job every 5 minutes while an order is waiting for payment).
* **What is sent:** your NOWPayments API key (as a request header), the order number, the price in USD, a short order description, the return address of your site and, if enabled, the address of your webhook. The buyer chooses the cryptocurrency and pays on the NOWPayments page; the plugin never sees wallet keys.
* **When:** when a buyer creates an order, when the buy dialog loads the list of coins and minimum amounts, when an order status is checked, and from the background job while orders are pending.
* **Incoming data:** NOWPayments calls a webhook address of your site with the payment status. The call is verified with an HMAC signature using your IPN secret.
* Service: https://nowpayments.io. Terms of service: https://nowpayments.io/doc/fd-tos.pdf. Privacy policy: https://nowpayments.io/doc/fd-privacy-policy.pdf.

== Privacy ==

* **Buyers:** the plugin stores the order number, an encrypted receipt token, the offer, a snapshot of the purchased files, the price and the payment id. It does not store names or e-mail addresses. For rate limiting, a short-lived counter keyed by a hash of the IP address is kept in WordPress transients. The buyer's browser keeps the order number and receipt token in its local storage so the order can be found again; the buyer can clear it at any time.
* **Debug log (off by default):** when switched on, it can contain order numbers, payment ids, IP addresses of throttled requests and short excerpts of the payment provider's answers. Secrets are masked.
* **Site owner:** the API key, the IPN secret and the resource passwords are stored encrypted in the database. Define `ANONYMOUS_SALES_ENCRYPTION_KEY` in `wp-config.php` to keep the encryption key outside the database.

== Source code ==

The full, human-readable source of this plugin is public: https://github.com/mjfutera/anonymousSalesPlugin. It is the same code as in the package. The release package is built from it with `node bin/build.mjs`.

== Third party libraries ==

* FPDF 1.86 by Olivier Plathey (http://www.fpdf.org), used to build the PDF access sheet. FPDF is released under a permissive license with no usage restrictions.

== Changelog ==

= 1.4.0 =
* Prepared for the WordPress.org directory: new name, readme, GPL license header.
* The PDF access sheet no longer contains promotional pages or logos.
* Logs and files moved to the uploads folder; the debug log is off by default and capped at 1 MB.
* Deactivating the plugin never deletes data; data is removed on deletion only if you chose so (new uninstall.php).
* Input handling and escaping hardened (unslash, escaping of output, WordPress file and date functions).
* Removed the environment snapshot from the log and the confirmation prompt on the Plugins screen.

= 1.3 =
* Payment confirmation fixes and security hardening.

== Upgrade Notice ==

= 1.4.0 =
The plugin was renamed to Anonymous Sales. Logs move to wp-content/uploads/anonymous-sales and the debug log is now off until you switch it on in Settings.
