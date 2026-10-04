# Bachs for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-6.3%2B-21759B?logo=wordpress)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588A?logo=woocommerce)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php)](https://www.php.net/)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

A secure, hosted Bachs payment gateway for WooCommerce. Customers are redirected to Bachs to pay; this plugin never collects, handles, or transmits card data.

## Requirements

- WordPress 6.3 or newer
- WooCommerce 7.0 or newer
- PHP 8.2 or newer
- A USD-priced WooCommerce store
- A Bachs account with API and webhook credentials

## How payments work

1. The customer selects **Pay with Bachs** at checkout.
2. WooCommerce creates a Bachs hosted checkout session priced in USD.
3. The customer is redirected to Bachs to complete payment.
4. Bachs redirects the customer back to the order-received page.
5. A signed Bachs webhook confirms payment and completes the WooCommerce order.

The redirect is never treated as proof of payment. Only a verified `collection.succeeded` webhook can mark an order as paid.

## USD store-currency requirement

Bachs performs local-currency conversion only for checkouts priced in USD. For that reason, this gateway requires your WooCommerce store currency to be **USD** and always sends `pricing.currency` as `USD`.

If your catalogue is currently priced in NGN or another currency:

1. Go to **WooCommerce → Settings → General → Currency options**.
2. Change the currency to USD.
3. Re-enter your product prices in USD.
4. Configure and enable Bachs.

The plugin does not convert product prices automatically. It remains unavailable until the store currency is USD.

## Installation

1. Download or clone this repository into `wp-content/plugins/bachs-for-woocommerce`.
2. If installing from source, install development dependencies only when you need linting:

   ```bash
   composer install
   ```

3. In WordPress, go to **Plugins** and activate **Bachs for WooCommerce**.
4. Go to **WooCommerce → Settings → Payments → Bachs**.
5. Enable sandbox mode and enter an `sk_sandbox_...` key, or disable it and enter an `sk_live_...` key.
6. Copy the displayed webhook URL into the corresponding Bachs webhook destination.
7. Save that destination's signing secret in the matching sandbox or live webhook-secret field.
8. Run a sandbox payment before accepting live payments.

## Webhook configuration

Use the stable endpoint displayed in the gateway settings, normally:

```text
https://example.com/wp-json/bachs/v1/webhook
```

Configure the destination in the matching Bachs environment and subscribe it to:

- `collection.succeeded`
- `refund.created`
- `refund.paid`
- `refund.failed`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `invoice.paid`
- `invoice.payment_failed`

The endpoint validates Bachs' timestamped HMAC signature before processing the body, rejects stale messages, and handles duplicate delivery safely. Keep sandbox and live webhook secrets separate.

## Locked local prices

You can optionally lock exact customer-facing amounts for selected currencies in the gateway settings. Supported currencies are:

`NGN`, `GHS`, `KES`, `MWK`, `RWF`, `TZS`, `UGX`, `XAF`, `XOF`, and `ZMW`.

When configured, Bachs uses the locked amount for that currency. For any other customer currency, Bachs uses live FX. USD cannot be added because it is always the base checkout currency.

Settlement and balance-currency configuration are managed in the Bachs dashboard, not by this plugin.

## Refunds

WooCommerce refunds create refunds against the stored Bachs charge ID. Bachs processes refunds asynchronously; the Bachs dashboard and subscribed `refund.*` webhooks show their final state. If Bachs rejects a refund, review the charge in the Bachs dashboard.

## WooCommerce Subscriptions

When WooCommerce Subscriptions is active, the gateway creates Bachs recurring catalogue products in USD and opens a Bachs product-cart checkout. A billing email is required.

Recurring billing is currently restricted to **USD cards**. Renewal orders remain on hold until a verified Bachs invoice webhook confirms collection. Subscription lifecycle and invoice events synchronize payment, recovery, and cancellation state between Bachs and WooCommerce.

## Security and privacy

- Hosted checkout only: no card fields are rendered or processed by this plugin.
- API keys and signing secrets are not written to logs.
- Debug logging redacts sensitive headers and values.
- Webhooks are signature-verified before JSON is processed.
- Payment confirmation is idempotent, so retried webhook deliveries cannot duplicate fulfilment.
- Compatible with WooCommerce High-Performance Order Storage (HPOS).

## Development

Install dependencies and run the coding-standard checks:

```bash
composer install
composer lint
```

The project uses WordPress Coding Standards through PHP_CodeSniffer. The rules are defined in [`phpcs.xml.dist`](phpcs.xml.dist).

## Troubleshooting

| Problem | What to check |
| --- | --- |
| Gateway is unavailable | Confirm WooCommerce currency is USD and a secret key exists for the active mode. |
| Order remains pending after return | Ensure the webhook endpoint is publicly reachable over HTTPS, the correct environment secret is saved, and `collection.succeeded` is subscribed. |
| Checkout cannot be created | Review **WooCommerce → Status → Logs** for the `bachs-for-woocommerce` source. Customers receive a generic error by design. |
| Local amount is unexpected | Check that each locked local price uses a supported fiat currency and a positive decimal amount. |

## License

Released under the [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) license.

## Author

Built by [Alexander Garuba](https://github.com/iamahless).
