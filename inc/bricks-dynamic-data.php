<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bricks Builder integration for the ACF Shopify Products field.
 *
 * Loop source (enable "Use dynamic data as loop source" on the loop element):
 *
 *   {shopify_products:FIELD_NAME}
 *
 * Returns the field's product summaries as an array, one loop item per
 * product, in the field's stored order. FIELD_NAME is the ACF field name
 * (or field key).
 *
 * Tags for use on elements inside that loop:
 *
 *   {shopify_product_title}
 *   {shopify_product_image}   (returns an image URL; array in image contexts)
 *   {shopify_product_handle}
 *   {shopify_product_gid}
 *
 * Each reads the matching key from the current Bricks loop object.
 */

define('ASPF_BRICKS_SOURCE_TAG', 'shopify_products');
define('ASPF_BRICKS_TAG_GROUP', 'Shopify Products (ACF)');

add_filter('bricks/dynamic_tags_list', 'aspf_bricks_register_tags');
add_filter('bricks/dynamic_data/render_tag', 'aspf_bricks_render_tag', 20, 3);
// PHP_INT_MAX: aspf_bricks_render_content returns an array when the
// loop-source tag resolves, and other plugins hooked on these same filters
// (e.g. WPSR, BricksExtras) assume a string and fatal on strpos()/
// preg_match_all() if they run after us. Running last means our array goes
// straight back to Bricks instead of through anyone else's string logic.
add_filter('bricks/dynamic_data/render_content', 'aspf_bricks_render_content', PHP_INT_MAX, 3);
add_filter('bricks/frontend/render_data', 'aspf_bricks_render_content', PHP_INT_MAX, 2);

/**
 * Loop-item tag => product summary key.
 */
function aspf_bricks_loop_tags() {
	return array(
		'shopify_product_title' => array('key' => 'title', 'label' => 'Shopify Product Title'),
		'shopify_product_image' => array('key' => 'image', 'label' => 'Shopify Product Image'),
		'shopify_product_handle' => array('key' => 'handle', 'label' => 'Shopify Product Handle'),
		'shopify_product_gid' => array('key' => 'gid', 'label' => 'Shopify Product GID'),
	);
}

function aspf_bricks_register_tags($tags) {
	foreach (aspf_bricks_loop_tags() as $tag_name => $tag) {
		$tags[] = array(
			'name' => '{' . $tag_name . '}',
			'label' => $tag['label'],
			'group' => ASPF_BRICKS_TAG_GROUP,
		);
	}

	// One loop-source tag per Shopify Products field found in the field groups.
	if (function_exists('acf_get_field_groups') && function_exists('acf_get_fields')) {
		foreach (acf_get_field_groups() as $group) {
			$fields = acf_get_fields($group['key']);
			if (empty($fields)) {
				continue;
			}
			foreach ($fields as $field) {
				if ($field['type'] === 'shopify_products') {
					$tags[] = array(
						'name' => '{' . ASPF_BRICKS_SOURCE_TAG . ':' . $field['name'] . '}',
						'label' => $field['label'] . ' (loop source)',
						'group' => ASPF_BRICKS_TAG_GROUP,
					);
				}
			}
		}
	}

	return $tags;
}

/**
 * The array of product summaries for a loop-source tag.
 */
function aspf_bricks_get_source_array($field_name) {
	$products = aspf_get_products_field($field_name, get_the_ID());

	if (is_wp_error($products) || !is_array($products)) {
		return array();
	}

	return $products;
}

/**
 * Read one product summary key from the current Bricks loop object.
 */
function aspf_bricks_get_loop_value($key) {
	if (!class_exists('\Bricks\Query')) {
		return false;
	}

	$loop_object = \Bricks\Query::get_loop_object();

	if (!is_array($loop_object) || !array_key_exists($key, $loop_object)) {
		return false;
	}

	return $loop_object[$key];
}

function aspf_bricks_render_tag($tag, $post, $context = 'text') {
	if (!is_string($tag)) {
		return $tag;
	}

	$clean_tag = str_replace(array('{', '}'), '', $tag);

	// Loop source: {shopify_products:FIELD_NAME} returns the raw array.
	if (strpos($clean_tag, ASPF_BRICKS_SOURCE_TAG . ':') === 0) {
		$field_name = substr($clean_tag, strlen(ASPF_BRICKS_SOURCE_TAG) + 1);
		return aspf_bricks_get_source_array($field_name);
	}

	// Loop item tags.
	$loop_tags = aspf_bricks_loop_tags();

	if (!array_key_exists($clean_tag, $loop_tags)) {
		return $tag;
	}

	$value = aspf_bricks_get_loop_value($loop_tags[$clean_tag]['key']);

	if ($value === false) {
		return '';
	}

	if ($clean_tag === 'shopify_product_image' && $context === 'image') {
		return !empty($value) ? array($value) : array();
	}

	return (string) $value;
}

function aspf_bricks_render_content($content, $post, $context = 'text') {
	if (!is_string($content)) {
		return $content;
	}

	// Loop source tag used on its own: hand the array straight back to Bricks.
	if (strpos($content, '{' . ASPF_BRICKS_SOURCE_TAG . ':') !== false) {
		if (preg_match('/^\s*{' . ASPF_BRICKS_SOURCE_TAG . ':([^}]+)}\s*$/', $content, $m)) {
			return aspf_bricks_get_source_array($m[1]);
		}
	}

	// Loop item tags inside content strings.
	foreach (aspf_bricks_loop_tags() as $tag_name => $tag_config) {
		$tag = '{' . $tag_name . '}';

		if (strpos($content, $tag) === false) {
			continue;
		}

		$value = aspf_bricks_get_loop_value($tag_config['key']);

		if ($value === false) {
			$value = '';
		}

		$content = str_replace($tag, (string) $value, $content);
	}

	return $content;
}
