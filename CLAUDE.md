# ACF Shopify Product Field

WordPress plugin: two ACF field types (`shopify_product` single,
`shopify_products` multiple) that search Shopify products via the Storefront
API and store the selected product GID(s) as the field value.

## Architecture

- `plugin.php` — bootstrap. Wires `plugin-update-checker` (GitHub releases,
  branch `main`, release-asset zip), requires `inc/`, registers both field
  types on `acf/include_field_types` (only if ACF is active).
- `inc/class-shopify-client.php` — `ASPF_Shopify_Client`, a thin GraphQL wrapper
  around the Storefront API (`ASPF_Shopify_Client::API_VERSION` pins the API
  version). `from_settings()` builds an instance from the global option;
  bump `API_VERSION` here when Shopify deprecates the current one.
  `get_products()` uses a single `nodes(ids:)` query for bulk lookups —
  prefer it over calling `get_product()` in a loop.
- `inc/class-plugin-settings.php` — Settings → Shopify Product Field. One
  global `aspf_settings` option (`shop_domain`, `storefront_token`) — no
  per-field-group credential override by design, shared by both field types.
- `inc/class-ajax-handlers.php` — `wp_ajax_aspf_search_products`, backs both
  fields' search UI. Nonce-gated (`aspf_search_products`), requires
  `edit_posts`. Accepts `exclude[]` (GIDs) so the multi-product field's
  search can omit already-selected items.
- `inc/class-acf-field-shopify-product.php` — single-product field. Renders a
  real `<select>` (not a separate hidden input) so ACF's normal save path
  picks up the GID directly as the field value; enhanced client-side with
  Select2.
- `inc/class-acf-field-shopify-products.php` — multi-product field. Storage
  mirrors ACF's own checkbox/relationship pattern: a base
  `name="{$field_name}"` hidden input with empty value (so clearing all
  selections still submits), followed by one `name="{$field_name}[]"` hidden
  input per selected GID in DOM order — order comes from `jquery-ui-sortable`
  drag-reordering, not a separate "order" field. No `load_value`/
  `update_value`/`format_value` overrides needed; WP's default postmeta
  serialization handles the array.
- `inc/helpers.php` — public API: `aspf_get_product()` /
  `aspf_get_product_field()` (single), `aspf_get_products()` /
  `aspf_get_products_field()` (bulk, order-preserving). The `_cached_*`
  variants are request-memoized only, not persisted — see caching note below.
- `assets/js/shopify-product-field.js` / `shopify-products-field.js` — both
  hook ACF's JS field API (`ready_field/type=...`, `append_field/type=...`),
  **not** raw `$(document).ready` — required to work inside
  repeater/flexible-content rows and clone fields.

## Non-obvious behavior

- Both fields store **only GID string(s)** — no title/image is persisted to
  postmeta. Every read of product data (including the admin edit-screen
  label for already-selected products) is a live Storefront API call. The
  single field makes one call per distinct GID per page load
  (request-memoized); the multi-product field batches all of a page's
  missing GIDs into one `nodes()` call instead, so it scales better per
  field instance — but a post edit screen with many *instances* of either
  field type still makes that many calls. There is no object-cache/transient
  layer. If this becomes a problem, add caching in
  `aspf_get_cached_product_label()` / `aspf_get_cached_products_summary()`
  and the corresponding `ASPF_Shopify_Client` methods, not at the call sites.
- Credentials are global, not per-field. If a site ever needs to query
  multiple shops, `ASPF_Shopify_Client::from_settings()` and both field
  settings UIs would need to change together.
- The multi-product field never removes a GID it can't resolve (e.g. a
  deleted Shopify product) — it just falls back to showing the raw GID as
  the label. Stale references silently persist until an editor manually
  removes them.
- The multi-product field's "Restrict to Collection" setting does **not**
  use the top-level `products(query:)` search DSL scoped to a collection —
  the Storefront API's `Collection.products` connection has no title-search
  argument. `search_products_in_collection()` fetches up to 250 products
  from the collection and filters by title in PHP, so collections over 250
  products only search their first page. No cursor pagination implemented.
- GraphQL query shape lives inline in `class-shopify-client.php` as heredoc
  strings — there's no filter hook to extend the fields fetched. Edit the
  query directly (e.g. to add metafields).

## Release process

Standard boilerplate — see root `CLAUDE.md` (WordPress Plugin Boilerplate
section) and `release.sh`. Only `plugin.php`, `assets/`, `inc/`, and
`plugin-update-checker/` ship in the release zip.
