# WooCommerce Stancer Gateway

WooCommerce Stancer Gateway adds Stancer as a payment method in WooCommerce.

## Requirements

- WordPress 6.2+
- WooCommerce 8.0+
- PHP 8.1+

## Development

Install dependencies:

```bash
composer install
```

Run code style checks:

```bash
composer lint
```

Run static analysis:

```bash
composer analyse
```

Run tests:

```bash
composer test
```

## Operations

- Webhook endpoint: `?wc-api=wc_gateway_stancer_webhook`
- Admin logs page: `WooCommerce > Stancer Logs`
