# ACF Shopify Product Field

A WordPress plugin that adds an [Advanced Custom Fields](https://www.advancedcustomfields.com/)
field type for searching and selecting Shopify products via the Storefront API.
The field stores the selected product's GID; a helper function resolves that
GID back into live product data on demand.

## Requirements

- WordPress with ACF (free or PRO) active
- A Shopify store with a [Storefront API access token](https://shopify.dev/docs/api/storefront#authentication)
  (a custom app with `unauthenticated_read_product_listings` scope is sufficient)

## Setup

1. Install and activate the plugin.
2. Go to **Settings → Shopify Product Field** and enter your shop's
   `.myshopify.com` domain and Storefront API access token.
3. Add a field of type **Shopify Product** to any field group.

## Usage

```php
$product = aspf_get_product_field('shopify_product'); // by field name, current post

if (!is_wp_error($product) && $product !== null) {
    echo esc_html($product['title']);
    echo esc_url($product['featuredImage']['url'] ?? '');
}
```

See [`docs/shopify-product-field.md`](docs/shopify-product-field.md) for the full
field reference, GraphQL shape, and admin UI implementation notes.

## Updates

This plugin ships updates via GitHub releases using
[plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker)
(vendored in `plugin-update-checker/`) — no WordPress.org listing.

## Releasing

```
./release.sh <new-version>
```

Bumps the `Version:` header, packages `plugin.php`, `assets/`, `inc/`, and
`plugin-update-checker/` into a zip, commits, pushes, and creates a GitHub
release with the zip attached.
