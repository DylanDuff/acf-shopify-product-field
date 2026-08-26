<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Resolve a Shopify product GID (as stored by the ACF Shopify Product field)
 * into live product data from the Storefront API.
 *
 * @param string $gid e.g. "gid://shopify/Product/1234567890"
 * @return array|WP_Error Product data on success, WP_Error on failure.
 */
function aspf_get_product($gid) {
	$client = ASPF_Shopify_Client::from_settings();
	if (is_wp_error($client)) {
		return $client;
	}

	return $client->get_product($gid);
}

/**
 * Convenience wrapper: read an ACF Shopify Product field value (a GID) and
 * resolve it to product data in one call.
 *
 * @param string $selector ACF field name/key, per get_field().
 * @param int|string|null $post_id Post ID, per get_field().
 * @return array|WP_Error|null Product data, WP_Error on failure, or null if the field is empty.
 */
function aspf_get_product_field($selector, $post_id = null) {
	if (!function_exists('get_field')) {
		return new WP_Error('aspf_acf_missing', __('Advanced Custom Fields is not active.', 'acf-shopify-product-field'));
	}

	$gid = get_field($selector, $post_id);
	if (empty($gid)) {
		return null;
	}

	return aspf_get_product($gid);
}

/**
 * Memoized product title lookup, used to label the currently-selected option
 * in the admin field UI without re-querying the Storefront API on every
 * render of the same GID within a request (e.g. repeater rows).
 */
function aspf_get_cached_product_label($gid) {
	static $cache = array();

	if (isset($cache[$gid])) {
		return $cache[$gid];
	}

	$product = aspf_get_product($gid);
	$label = is_wp_error($product) ? '' : ($product['title'] ?? '');
	$cache[$gid] = $label;

	return $label;
}

/**
 * Bulk-resolve GIDs (as stored by the ACF Shopify Products field) into
 * lightweight product summaries (gid, title, handle, image) in one
 * Storefront API call.
 *
 * @param array $gids
 * @return array|WP_Error
 */
function aspf_get_products($gids) {
	$client = ASPF_Shopify_Client::from_settings();
	if (is_wp_error($client)) {
		return $client;
	}

	return $client->get_products($gids);
}

/**
 * Convenience wrapper: read an ACF Shopify Products field value (an array of
 * GIDs) and resolve it to product summaries in one call, preserving the
 * field's stored order.
 *
 * @param string $selector ACF field name/key, per get_field().
 * @param int|string|null $post_id Post ID, per get_field().
 * @return array|WP_Error Product summaries (possibly empty array), or WP_Error on failure.
 */
function aspf_get_products_field($selector, $post_id = null) {
	if (!function_exists('get_field')) {
		return new WP_Error('aspf_acf_missing', __('Advanced Custom Fields is not active.', 'acf-shopify-product-field'));
	}

	$gids = get_field($selector, $post_id);
	if (empty($gids) || !is_array($gids)) {
		return array();
	}

	return aspf_get_products($gids);
}

/**
 * Memoized bulk product-summary lookup for admin rendering, used so a field
 * with many already-selected GIDs makes one Storefront API call (for the
 * cache misses) instead of one per GID. Falls back to the raw GID as the
 * label if a product can't be resolved (e.g. it was deleted in Shopify).
 *
 * @param array $gids
 * @return array List of ['gid' => ..., 'title' => ..., 'handle' => ..., 'image' => ...], one per input GID, in order.
 */
function aspf_get_cached_products_summary($gids) {
	static $cache = array();

	$missing = array();
	foreach ($gids as $gid) {
		if (!isset($cache[$gid])) {
			$missing[] = $gid;
		}
	}

	if (!empty($missing)) {
		$fetched = aspf_get_products($missing);
		if (!is_wp_error($fetched)) {
			foreach ($fetched as $product) {
				$cache[$product['gid']] = $product;
			}
		}
	}

	$result = array();
	foreach ($gids as $gid) {
		$result[] = $cache[$gid] ?? array(
			'gid' => $gid,
			'title' => $gid,
			'handle' => '',
			'image' => '',
		);
	}

	return $result;
}
