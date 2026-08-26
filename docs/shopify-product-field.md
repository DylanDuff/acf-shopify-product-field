# Shopify Product Field(s)

Reference for the two ACF field types this plugin registers: `shopify_product`
(single) and `shopify_products` (multiple, relationship-style).

## Credentials (shared by both field types)

Shop domain and Storefront API access token are configured once, globally, under
**Settings → Shopify Product Field** (`inc/class-plugin-settings.php`), stored in
the `aspf_settings` option. All Storefront API calls (`inc/class-shopify-client.php`)
read from this single option — there is no per-field-group credential override.

The Storefront API token only needs `unauthenticated_read_product_listings` scope.
Storefront tokens are meant for public/unauthenticated reads, so storing it in
`wp_options` carries the same trust level as embedding it in a theme.

## `shopify_product` — single product

`inc/class-acf-field-shopify-product.php`.

### What it does

Registers an ACF field of type `shopify_product`. In the field editor UI it renders
as a Select2 search box (`assets/js/shopify-product-field.js`) that queries the
Shopify Storefront API by product title via `wp_ajax_aspf_search_products`
(`inc/class-ajax-handlers.php`). Selecting a result stores the product's GID
(e.g. `gid://shopify/Product/1234567890`) as the raw field value — nothing else
is persisted in postmeta.

### Resolving a stored GID back to product data

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

### Admin UI notes

- The field's Select2 is initialized via ACF's JS field API
  (`acf.addAction('ready_field/type=shopify_product', ...)` and
  `append_field/...` for repeater/flexible-content rows), not on raw DOM ready —
  this is required for the field to work inside repeaters and clone fields.
- The currently-selected option's label is looked up server-side on render
  (`aspf_get_cached_product_label()`), which makes one Storefront API call per
  distinct GID per page load (memoized within the request). A post edit screen
  with many rows of this field will make that many API calls when the page renders.

## `shopify_products` — multiple products

`inc/class-acf-field-shopify-products.php`.

### What it does

Registers an ACF field of type `shopify_products`, modeled on ACF's own
Relationship field: a search panel on the left (same AJAX search endpoint as
the single field, `aspf_search_products`, but passing `exclude[]` for the
already-selected GIDs so they drop out of further results), and a sortable
list of selected products on the right
(`assets/js/shopify-products-field.js`, using `jquery-ui-sortable`). Field
settings: **Placeholder Text**, **Maximum Products** (0 = unlimited), and
**Restrict to Collection**.

There are two independent ways to scope search to a collection:

1. **Restrict to Collection** (field setting, set by whoever builds the field
   group) locks the field instance's search to one Shopify collection,
   chosen from a `<select>` populated at field-settings-render time via
   `aspf_get_cached_collections()` → `ASPF_Shopify_Client::get_collections()`
   (a live Storefront API call — same trade-off as the settings page needing
   credentials configured first; if they aren't, the choices list is empty
   and the setting shows only "— All products —"). When set, the picker's
   search input always carries this collection GID and no interactive
   filter dropdown is rendered — the content editor can't broaden the
   search back to the whole catalog.
2. **Collection filter dropdown** (in the field's own UI, rendered above the
   search box) is shown only when the field is *not* locked by the setting
   above. It lets the content editor narrow their own search to a collection
   on the fly, per search — selecting "All Collections" reverts to
   catalog-wide search. Changing it re-runs the current search term
   immediately if one is present.

Either path passes a collection GID to `wp_ajax_aspf_search_products`, which
routes to `ASPF_Shopify_Client::search_products_in_collection()` instead of
the top-level `search_products()`. That method fetches up to 250 products
from the collection and filters by title in PHP, because the Storefront
API's `Collection.products` connection has no title-search argument the way
the top-level `products(query:)` field does — **collections larger than 250
products only search their first page**, regardless of which UI triggered
the collection scoping. Revisit this if a store has larger collections; the
fix would be cursor-paginating through `Collection.products` server-side,
which isn't implemented.

### Storage

The value is a plain ordered PHP array of GID strings (drag order = stored
order), e.g. `['gid://shopify/Product/1', 'gid://shopify/Product/2']`. It's
submitted the same way ACF's own checkbox/relationship fields are: a base
`name="{$field_name}"` hidden input with an empty value (so deselecting
everything still submits something), followed by one
`name="{$field_name}[]"` hidden input per selected item, in DOM order. No
custom `load_value`/`update_value`/`format_value` overrides — WP's default
postmeta serialization handles the array.

### Resolving stored GIDs back to product data

```php
$products = aspf_get_products_field('linked_products'); // current post, by field name
// => [ ['gid' => ..., 'title' => ..., 'handle' => ..., 'image' => ...], ... ] in stored order

foreach ($products as $product) {
    echo esc_html($product['title']);
}

// Or resolve an arbitrary list of GIDs directly:
$products = aspf_get_products(['gid://shopify/Product/1', 'gid://shopify/Product/2']);
```

`aspf_get_products()` calls `ASPF_Shopify_Client::get_products()`, which uses
Shopify's `nodes(ids: [ID!])` query to fetch all of them in a single
Storefront API request (not one call per GID). It returns lightweight
summaries only (gid, title, handle, image) — not the full product shape
`get_product()`/`aspf_get_product()` returns (no variants, price range,
description, etc.). Call `aspf_get_product($gid)` per item if you need the
full shape for one of the selected products.

### Admin UI notes

- Same "hook ACF's JS field API, not raw DOM ready" requirement as the single
  field, via `ready_field/type=shopify_products` / `append_field/...`.
- The results panel runs a search with an empty term on init to pre-populate
  it with Shopify's default product listing (or the collection's, if scoped),
  rather than leaving it blank until the editor types something — the same
  `aspf_search_products` AJAX call handles an empty `term` fine. This is one
  more Storefront API call per field instance per page load, on top of the
  label-lookup call for already-selected products.
- The already-selected items' labels on render use
  `aspf_get_cached_products_summary()`, which batches all missing GIDs into
  one `get_products()` call (request-memoized) rather than looking up each
  GID individually — this is the main practical difference from the single
  field's per-GID `aspf_get_cached_product_label()`.
- If a stored GID can't be resolved (e.g. the product was deleted in
  Shopify), the admin UI falls back to displaying the raw GID as the label
  rather than silently dropping the item — the value isn't cleaned up by
  loading the field.

## GraphQL shape

`ASPF_Shopify_Client::search_products()` queries `products(query, first)` filtered
with a `title:*term*` Storefront search string, returning `{ gid, title, handle, image }`
per result (capped at 50).

`ASPF_Shopify_Client::get_products()` queries `nodes(ids: [ID!])` and returns the
same lightweight `{ gid, title, handle, image }` shape per GID, in the order requested
(missing/deleted/non-Product ids are silently dropped).

`ASPF_Shopify_Client::get_collections()` queries `collections(first, sortKey: TITLE)`,
returning `{ gid, title, handle }` per collection (capped at 250, no pagination).

`ASPF_Shopify_Client::search_products_in_collection()` queries
`collection(id: $id) { products(first: 250) { ... } }` and filters by title
client-side — see the collection-filter note above.

`ASPF_Shopify_Client::get_product()` queries a single `product(id: $gid)` and returns
title, handle, description/descriptionHtml, vendor, productType, tags,
availableForSale, onlineStoreUrl, featuredImage, up to 10 images, min/max price
range, and up to 50 variants (with price, availability, and selected options).

Extend the GraphQL query strings in `class-shopify-client.php` directly if a
template needs fields not currently fetched (e.g. metafields) — there's no
filter hook for the query shape yet.
