=== Mobupay for WooCommerce ===
Contributors: mobupay
Tags: payments, payment gateway, credit card, woocommerce, new caledonia
Requires at least: 6.0
Tested up to: 7.0
Stable tag: 1.0.0
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
* Compatible with WooCommerce High-Performance Order Storage (HPOS)
* Interface and documentation in French, designed for merchants in New Caledonia

**A Mobupay merchant account is required.** Sign up at [mobupay.nc](https://mobupay.nc). The plugin works in production for any approved Mobupay merchant; a free test mode is available while your account is under review.

**External service disclosure**

This plugin communicates with the Mobupay API (`https://api.mobupay.nc`), operated by Mobulia Payment Solutions, in the following cases:

* When a customer places an order, the plugin creates a payment session and sends: order amount, currency, order number, order ID and the customer billing email (used for the payment receipt).
* When you issue a refund, the plugin sends the payment identifier and the refund amount.
* Mobupay calls back your store's webhook URL to confirm payment events.

No other personal data is transmitted. See the Mobupay [terms of service](https://api.mobupay.nc/legal/cgu), [terms of sale](https://api.mobupay.nc/legal/cgv) and [privacy policy](https://api.mobupay.nc/legal/privacy).

Documentation en francais : [mobupay.nc](https://mobupay.nc).

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to WooCommerce > Settings > Payments > Mobupay.
3. Paste your API keys (`sk_test_*` for test mode, `sk_live_*` for production) from your Mobupay merchant dashboard (Developpeurs > Cles API).
4. Paste your webhook signing secret (`whsec_*`) from the same screen.
5. Enable the gateway. In test mode, use the Mobupay test cards to simulate payments.

The webhook URL to configure on the Mobupay side is displayed in the plugin settings (format: `https://yourstore.example/?wc-api=mobupay`).

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

The current version supports the classic (shortcode) checkout. Block checkout support is planned.

== Screenshots ==

1. Gateway settings screen (API keys, webhook secret, test mode).
2. Checkout: the customer selects the Mobupay payment method.
3. Hosted payment page where the customer enters their card details.
4. Order updated automatically after the signed webhook confirmation.

== Changelog ==

= 1.0.0 =
* Initial release: hosted payment page, signed webhooks (HMAC V2 anti-replay with V1 fallback), idempotent payment creation, full and partial refunds, EUR/XPF, test mode, HPOS compatibility.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
