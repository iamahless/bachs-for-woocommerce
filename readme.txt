=== Bachs for WooCommerce ===
Contributors: iamahless
Tags: woocommerce, payment gateway, hosted checkout, bachs
Requires at least: 6.3
Tested up to: 6.7
Requires PHP: 8.2
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Secure hosted Bachs checkout for WooCommerce. This plugin never collects or transmits card details.

== Description ==

Customers are redirected to Bachs hosted checkout. The browser redirect is never treated as proof of payment: Bachs' signed `collection.succeeded` webhook is the only action that completes the WooCommerce order.

Bachs supports both the classic checkout and WooCommerce's Checkout block.

The store currency must be USD. Bachs only offers customer-local currency conversion for USD-priced checkouts. Do not use this gateway with NGN or another store currency, and do not expect it to convert existing WooCommerce prices. Change WooCommerce > Settings > General > Currency options to USD, then re-enter product prices in USD.

Optional locked local prices let the merchant set exact checkout prices for NGN, GHS, KES, MWK, RWF, TZS, UGX, XAF, XOF, and ZMW. Other customer currencies use Bachs live FX. Settlement and balance currencies are configured in the Bachs dashboard, not by this plugin.

== Installation ==

1. Upload the `bachs-for-woocommerce` directory to `/wp-content/plugins/` and activate it.
2. Set the WooCommerce store currency to USD and re-enter catalog prices in USD. The plugin will stay unavailable otherwise.
3. Open WooCommerce > Settings > Payments > Bachs.
4. Enable sandbox mode and add an `sk_sandbox_...` key, or disable it and add an `sk_live_...` key.
5. Copy the displayed webhook URL into a Bachs webhook destination for the matching environment.
6. Subscribe the endpoint to `collection.succeeded`, `refund.created`, `refund.paid`, `refund.failed`, `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `invoice.paid`, and `invoice.payment_failed`; save that destination's environment-specific signing secret in the matching settings field.
7. Test a sandbox payment before enabling live mode.

== Webhooks ==

The endpoint is `/wp-json/bachs/v1/webhook`. It validates `X-Bachs-Timestamp` and the HMAC-SHA256 hexadecimal `X-Bachs-Signature` over `{timestamp}.{raw_body}` before reading JSON, uses a five-minute replay window, and completes an order only once.

Keep sandbox and live webhook secrets separate. Keys and signing secrets are never written to WooCommerce logs. Enable debug logging only while troubleshooting; it records Bachs API failures and verified webhook payloads with sensitive headers and fields redacted.

== Refunds ==

WooCommerce refunds create Bachs refunds against the Bachs charge ID. Bachs refunds are asynchronous; the dashboard and subscribed `refund.*` events show the final outcome. Some payment methods are not refundable. When Bachs refuses a refund, use the Bachs dashboard to review the charge and appropriate customer remedy.

== Subscriptions ==

When WooCommerce Subscriptions is active, this gateway creates a Bachs recurring catalog product in USD for each WooCommerce subscription and opens a Bachs product-cart checkout. A billing email is required. Recurring billing is explicitly restricted to USD cards. Bachs `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, and `invoice.*` webhooks synchronize the Bachs subscription ID, recovery state, cancellation state, and renewal settlement; WooCommerce renewal orders remain on hold until the signed Bachs invoice webhook arrives. Cancelling a WooCommerce subscription is sent to Bachs. Do not change the store currency from USD.

== Troubleshooting ==

* Gateway unavailable: set WooCommerce currency to USD and configure the active mode's secret key.
* Pending payment after redirect: confirm the webhook URL is publicly reachable over HTTPS, its signing secret matches the selected environment, and `collection.succeeded` is subscribed.
* API validation failure: check WooCommerce > Status > Logs for the `bachs-for-woocommerce` source. Customer-facing checkout errors remain generic by design.
* Local amount unexpected: verify locked local price entries are positive decimal amounts and use only supported fiat codes.
