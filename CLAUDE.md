# ACF Shopify Product Field

WordPress plugin: an ACF field type (`shopify_product`) that searches Shopify
products via the Storefront API and stores the selected product's GID as the
field value.

## Architecture

- `plugin.php` — bootstrap. Wires `plugin-update-checker` (GitHub releases,
  branch `main`, release-asset zip), requires `inc/`, registers the field type
  on `acf/include_field_types` (only if ACF is active).
- `inc/class-shopify-client.php` — `ASPF_Shopify_Client`, a thin GraphQL wrapper
  around the Storefront API (`ASPF_Shopify_Client::API_VERSION` pins the API
  version). `from_settings()` builds an instance from the global option;
  bump `API_VERSION` here when Shopify deprecates the current one.
- `inc/class-plugin-settings.php` — Settings → Shopify Product Field. One
  global `aspf_settings` option (`shop_domain`, `storefront_token`) — no
  per-field-group credential override by design.
- `inc/class-ajax-handlers.php` — `wp_ajax_aspf_search_products`, backs the
  Select2 type-ahead. Nonce-gated (`aspf_search_products`), requires
  `edit_posts`.
- `inc/class-acf-field-shopify-product.php` — the ACF field type itself.
  Renders a real `<select>` (not a separate hidden input) so ACF's normal
  save path picks up the GID directly as the field value.
- `inc/helpers.php` — public API: `aspf_get_product()`,
  `aspf_get_product_field()`. `aspf_get_cached_product_label()` is
  request-memoized only, not persisted.
- `assets/js/shopify-product-field.js` — hooks ACF's JS field API
  (`ready_field/type=shopify_product`, `append_field/type=shopify_product`),
  **not** raw `$(document).ready` — required for the field to work inside
  repeater/flexible-content rows and clone fields.

## Non-obvious behavior

- The field stores **only the GID string** (e.g.
  `gid://shopify/Product/123`) as its ACF value — no title/cache is persisted
  to postmeta. Every read of product data (including the admin edit-screen
  label for an already-selected product) is a live Storefront API call.
  A post edit screen with many instances of this field (e.g. inside a
  repeater) makes that many API calls per page load — there is no
  object-cache/transient layer yet. If this becomes a problem, add caching
  in `aspf_get_cached_product_label()` and `ASPF_Shopify_Client::get_product()`,
  not at the call sites.
- Credentials are global, not per-field. If a site ever needs to query
  multiple shops, `ASPF_Shopify_Client::from_settings()` and the field
  settings UI both need to change together.
- GraphQL query shape lives inline in `class-shopify-client.php` as heredoc
  strings — there's no filter hook to extend the fields fetched. Edit the
  query directly (e.g. to add metafields).

## Release process

Standard boilerplate — see root `CLAUDE.md` (WordPress Plugin Boilerplate
section) and `release.sh`. Only `plugin.php`, `assets/`, `inc/`, and
`plugin-update-checker/` ship in the release zip.
