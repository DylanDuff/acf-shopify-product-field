# Shopify Product Field

Reference for the `acf_field_shopify_product` field type (`inc/class-acf-field-shopify-product.php`).

## What it does

Registers an ACF field of type `shopify_product`. In the field editor UI it renders
as a Select2 search box (`assets/js/shopify-product-field.js`) that queries the
Shopify Storefront API by product title via `wp_ajax_aspf_search_products`
(`inc/class-ajax-handlers.php`). Selecting a result stores the product's GID
(e.g. `gid://shopify/Product/1234567890`) as the raw field value — nothing else
is persisted in postmeta.

## Credentials

Shop domain and Storefront API access token are configured once, globally, under
**Settings → Shopify Product Field** (`inc/class-plugin-settings.php`), stored in
the `aspf_settings` option. All Storefront API calls (`inc/class-shopify-client.php`)
read from this single option — there is no per-field-group credential override.

The Storefront API token only needs `unauthenticated_read_product_listings` scope.
Storefront tokens are meant for public/unauthenticated reads, so storing it in
`wp_options` carries the same trust level as embedding it in a theme.

## Resolving a stored GID back to product data

```php
// Full product data (title, images, price range, variants, ...)
$product = aspf_get_product_field('shopify_product'); // current post, by field name
$product = aspf_get_product_field('field_abc123', $post_id); // explicit post + field key

if (is_wp_error($product)) {
    // API/config error — see $product->get_error_message()
} elseif ($product === null) {
    // field was empty
} else {
    echo $product['title'];
}

// Or resolve an arbitrary GID directly (not tied to an ACF field):
$product = aspf_get_product('gid://shopify/Product/1234567890');
```

Both helpers live in `inc/helpers.php` and call `ASPF_Shopify_Client::get_product()`,
which is a live Storefront API GraphQL call on every invocation — there is no
caching layer. If a template calls this on every page load, consider wrapping it
in a transient.

## GraphQL shape

`ASPF_Shopify_Client::search_products()` queries `products(query, first)` filtered
with a `title:*term*` Storefront search string, returning `{ gid, title, handle, image }`
per result (capped at 50).

`ASPF_Shopify_Client::get_product()` queries a single `product(id: $gid)` and returns
title, handle, description/descriptionHtml, vendor, productType, tags,
availableForSale, onlineStoreUrl, featuredImage, up to 10 images, min/max price
range, and up to 50 variants (with price, availability, and selected options).

Extend the GraphQL query strings in `class-shopify-client.php` directly if a
template needs fields not currently fetched (e.g. metafields) — there's no
filter hook for the query shape yet.

## Admin UI notes

- The field's Select2 is initialized via ACF's JS field API
  (`acf.addAction('ready_field/type=shopify_product', ...)` and
  `append_field/...` for repeater/flexible-content rows), not on raw DOM ready —
  this is required for the field to work inside repeaters and clone fields.
- The currently-selected option's label is looked up server-side on render
  (`aspf_get_cached_product_label()`), which makes one Storefront API call per
  distinct GID per page load (memoized within the request). A post edit screen
  with many rows of this field will make that many API calls when the page renders.
