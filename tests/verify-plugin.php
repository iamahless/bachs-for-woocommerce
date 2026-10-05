<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root))), static fn($f) => $f->isFile() && 'php' === $f->getExtension() && ! str_contains($f->getPathname(), '/tests/') && ! str_contains($f->getPathname(), '/vendor/') );
foreach ($files as $file) {
    $output = shell_exec('php -l ' . escapeshellarg($file->getPathname()));
    if (! str_contains((string) $output, 'No syntax errors')) { fwrite(STDERR, "Syntax failure: {$file->getPathname()}\n"); exit(1); }
}
$source = '';
foreach ($files as $file) { $source .= file_get_contents($file->getPathname()) . "\n"; }
$composer = (string) file_get_contents($root . '/composer.json');
$phpcs = (string) file_get_contents($root . '/phpcs.xml.dist');
$mode = $argv[1] ?? '';
$checks = [
    'bootstrap' => ['Plugin Name: Bachs for WooCommerce', 'cart_checkout_blocks', 'custom_order_tables', 'woocommerce_payment_gateways', 'woocommerce_currency'],
    'gateway' => ['class WC_Gateway_Bachs', "'currency' => 'USD'", 'create_checkout_session', 'wc_reduce_stock_levels', 'currency_options', 'scheduled_subscription_payment', 'woocommerce_scheduled_subscription_payment_'],
    'api' => ['class Bachs_API', 'https://sandbox-api.bachs.io/v1', 'Authorization', "'/refunds'", "'/products'", 'cancel_subscription', 'wp_remote_post'],
    'webhook' => ['class Bachs_Webhook', 'hash_hmac', 'x-bachs-signature', 'payment_complete', '_bachs_charge_id', 'checkout_mismatch', 'amount_mismatch', 'handle_refund', 'handle_invoice_paid', 'subscription_order_mismatch', 'handle_subscription_state', 'same_bachs_period'],
    'integration' => ['locked_local_prices', 'order-status', 'bachs-copy-webhook', 'Bachs for WooCommerce requires a USD-priced store', 'customer.subscription.created', 'Bachs_Blocks_Support'],
    'quality' => ['defined( \'ABSPATH\' ) || exit;', 'bachs-for-woocommerce', 'acquire_checkout_lock', 'event_timestamp'],
];
if (! isset($checks[$mode])) { fwrite(STDERR, "Unknown verification mode\n"); exit(1); }
foreach ($checks[$mode] as $needle) { if (! str_contains($source, $needle)) { fwrite(STDERR, "Missing required implementation: {$needle}\n"); exit(1); } }
if ('quality' === $mode && preg_match('/\/\/\s*TODO|add logic here/i', $source)) { fwrite(STDERR, "Implementation placeholder found\n"); exit(1); }
if ('quality' === $mode && (str_contains($source, 'number_format( (float) $amount') || ! str_contains($composer, 'wp-coding-standards/wpcs') || ! str_contains($phpcs, 'WordPress-Extra'))) { fwrite(STDERR, "WordPress coding standards configuration or exact-decimal handling is missing\n"); exit(1); }
echo "{$mode} verification passed\n";
