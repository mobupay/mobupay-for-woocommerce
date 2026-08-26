=== Mobupay for WooCommerce ===
Contributors: mobulia
Tags: payments, payment gateway, credit card, woocommerce, new caledonia
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 1.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card payments in France, New Caledonia and the Pacific with Mobupay: hosted payment page, signed webhooks, refunds from WooCommerce.

== Description ==

Mobupay for WooCommerce lets your store accept card payments through Mobupay, a payment solution for France, New Caledonia and the Pacific, operating as an agent of eZyness, a licensed French e-money institution.

**How it works**

1. At checkout, the customer selects "Carte bancaire (Mobupay)".
2. They are redirected to a secure payment page hosted by Mobupay. **Card data never touches your server** (no PCI burden on your store).
3. Once the payment is confirmed, Mobupay notifies your store through a **cryptographically signed webhook** (HMAC, anti-replay). The order status is driven by this webhook, never by the browser redirect.
4. Full and partial refunds can be issued directly from the WooCommerce order screen.

**Features**

* Redirect / hosted payment page model: no card data on your server
* Signed webhooks (HMAC SHA-256 with anti-replay timestamp) drive order statuses
* Idempotent payment creation: a network retry never charges the customer twice
* Full and partial refunds from the WooCommerce back office
* Supports EUR and XPF (CFP franc)
* Test mode (sandbox) with separate test API keys
* Works with both the block checkout and the classic (shortcode) checkout
* Sends the full order detail: line items, per-line taxes, shipping and discounts
* Optional Mobupay invoicing: a compliant invoice per payment, with a credit note on refund
* Compatible with WooCommerce High-Performance Order Storage (HPOS)
* Interface and documentation in French, designed for merchants in New Caledonia

**A Mobupay merchant account is required.** Sign up at [mobupay.nc](https://mobupay.nc). The plugin works in production for any approved Mobupay merchant; a free test mode is available while your account is under review.

**External service disclosure**

This plugin communicates with the Mobupay API (`https://api.mobupay.nc`), operated by Mobulia Payment Solutions, in the following cases:

* When a customer places an order, the plugin creates a payment session and sends: order amount, currency, order number, order ID and the customer billing email (used for the payment receipt).
* If "Order detail" is enabled (default), it also sends the order lines: product name, quantity, unit price, discount and tax rate, plus shipping and fees.
* If "Customer details" is enabled (default), it also sends the customer first and last name, phone number, billing address and shipping address. These are required for an invoice to carry the mentions the law requires.
* If Mobupay invoicing is enabled (disabled by default), the same data is used to issue the invoice.
* When you issue a refund, the plugin sends the payment identifier and the refund amount.
* Mobupay calls back your store's webhook URL to confirm payment events.

Both "Order detail" and "Customer details" can be switched off in the gateway settings, in which case only the amount, currency, order number and billing email are sent. No data is transmitted beyond what is listed above. See the Mobupay [terms of service](https://api.mobupay.nc/legal/cgu), [terms of sale](https://api.mobupay.nc/legal/cgv) and [privacy policy](https://api.mobupay.nc/legal/privacy).

Documentation en francais : [mobupay.nc](https://mobupay.nc).

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to WooCommerce > Settings > Payments > Mobupay.
3. Paste your API key (`sk_test_*` for test mode, `sk_live_*` for production) from your Mobupay merchant dashboard (Developpeurs > Cles API).
4. Enable the gateway and save. That is all: the plugin verifies the key and retrieves the webhook signing secret by itself.
5. In test mode, use the Mobupay test cards to simulate payments.

Nothing has to be registered on the Mobupay side: the plugin sends its own notification URL with every payment.

== Frequently Asked Questions ==

= Do I need a Mobupay account? =

Yes. Mobupay is available to merchants in France and New Caledonia. Sign up at [mobupay.nc](https://mobupay.nc). A test mode is available immediately; live payments are enabled once your merchant file is approved.

= Is card data processed on my server? =

No. The customer pays on a payment page hosted by Mobupay. Your server only receives payment status notifications, signed with your webhook secret.

= Which currencies are supported? =

EUR and XPF (CFP franc).

= How are order statuses updated? =

Exclusively through signed webhooks (`payment.authorized`, `payment.captured`, `payment.failed`, `payment.cancelled`, `payment.expired`, `payment.refunded`). The plugin verifies the HMAC signature and rejects unsigned or replayed notifications. The browser redirect never changes the order status.

= Does the plugin support the WooCommerce block checkout? =

Yes, since version 1.1.0. The payment method appears on both the block checkout, which is the default on recent WooCommerce installations, and the classic shortcode checkout. Nothing to configure.

= What exactly is sent to Mobupay, and can I turn it off? =

Two independent settings, both on by default: "Order detail" (line items, taxes, shipping, discounts) and "Customer details" (name, phone, billing and shipping address). Everything is derived automatically from the WooCommerce order, so there is no field to fill in, and whatever your checkout does not collect is simply omitted. Turn either one off and the plugin falls back to sending only the amount, currency, order number and billing email.

= Do I need customer details to get an invoice? =

Yes. An invoice must carry the customer name and address, so "Customer details" has to stay on for Mobupay invoicing to work. If an invoice cannot be issued for a given order, the payment still goes through and the reason is recorded in the order notes: a payment is never refused for an invoicing reason.

== Screenshots ==

1. Gateway settings screen (API keys, webhook secret, test mode).
2. Checkout: the customer selects the Mobupay payment method.
3. Hosted payment page where the customer enters their card details.
4. Order updated automatically after the signed webhook confirmation.

== Changelog ==

= 1.2.0 =
* One field to set up instead of two. The webhook signing secret is no longer typed in: the plugin retrieves it by itself from your API key, which already grants access to it. Saving the settings now also verifies the key and tells you which environment you are connected to, test or production, so a store cannot silently go live with a test key.
* The signing secret is refreshed automatically if it is rotated on the Mobupay side, so signed confirmations keep being accepted without any action.
* A payment is never refused because invoicing data is missing. The payment goes through, the invoice is created as a draft carrying the full order detail, and it lists the mentions left to complete in your Mobupay merchant space.

= 1.1.0 =
* Block checkout support. The payment method now appears on the WooCommerce block checkout, which is the default on recent installations. Previously the gateway declared itself incompatible and simply did not show up, which was the most common reason a correctly configured store saw no payment method at all.
* Sends the full order detail: line items with quantity, unit price and per-line tax rate, shipping as its own line, fees, and discounts. The customer now sees the cart summary on the payment page, and Mobupay invoices detail every line instead of showing a single "Order X" line.
* Sends the customer details when enabled: first and last name, phone, billing address and shipping address. Everything is derived from the order, with no field to fill in.
* New setting to request a Mobupay invoice per payment, optionally emailed to the customer. Disabled by default.
* A payment is never refused for an invoicing reason: if the invoice cannot be requested, the payment goes through, the reason is recorded in the order notes, and a notice in the WordPress admin tells you what to fix. Order notes alone are not enough, nobody rereads the notes of a paid order.
* Amounts are reconciled to the cent before being sent, so rounding differences between WooCommerce tax settings can no longer cause a rejected payment.

= 1.0.1 =
* Declares incompatibility with the WooCommerce "blocks" checkout. The gateway is a classic gateway and never appeared on the blocks checkout; WooCommerce now warns the merchant instead of showing an empty payment section with no explanation.
* Tested with WooCommerce 11.0.

= 1.0.0 =
* Initial release: hosted payment page, signed webhooks (HMAC V2 anti-replay with V1 fallback), idempotent payment creation, full and partial refunds, EUR/XPF, test mode, HPOS compatibility.

== Upgrade Notice ==

= 1.2.0 =
Recommended for everyone. Setup is now a single field: the plugin retrieves the webhook signing secret from your API key, and saving the settings tells you whether you are connected to test or production. A payment is never refused because invoicing data is missing.

= 1.1.0 =
Recommended for everyone. Adds block checkout support, so the payment method now appears on recent WooCommerce installations without switching back to the classic checkout. Also sends the order detail and, optionally, issues invoices.

= 1.0.1 =
Recommended if your Commande page uses the "blocks" checkout: WooCommerce will now tell you the gateway is not compatible, instead of leaving the payment section empty. Use the classic checkout shortcode to accept payments.

= 1.0.0 =
Initial release.
