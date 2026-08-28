<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Bricks Builder integration for the ACF Shopify Product fields.
 *
 * 1. Dynamic data tags (per the Bricks Academy "Create Your Own Dynamic Data Tag" guide):
 *
 *   {aspf_product:FIELD}                   Title of the single-product field
 *   {aspf_product:FIELD:PROPERTY}          One property (dot notation supported, eg priceRange.minVariantPrice.amount)
 *   {aspf_products:FIELD}                  Titles of all selected products, joined with ", "
 *   {aspf_products:FIELD:PROPERTY}         One property of every selected product, joined
 *   {aspf_products:FIELD:INDEX:PROPERTY}   One property of the product at INDEX (0-based)
 *   {aspf_products_array:FIELD}            Raw array of product summaries, for "use dynamic data as loop source"
 *
 * 2. Query loop type "Shopify Products (ACF)":
 *
 *   Select it as the Query type on a Container / Block / Div, enter the ACF
 *   field name, and each loop item is one product summary (gid, title,
 *   handle, image) in the field's stored order. Inside the loop use:
 *
 *   {aspf_loop:title}  {aspf_loop:handle}  {aspf_loop:image}  {aspf_loop:gid}
 *   {aspf_loop:PROPERTY} for any other key present on the loop object.
 *
 * In an image context tags return an array of image URLs so they work
 * directly on Image and Image Gallery elements.
 */

define('ASPF_BRICKS_TAG_SINGLE', 'aspf_product');
define('ASPF_BRICKS_TAG_MULTI', 'aspf_products');
define('ASPF_BRICKS_TAG_ARRAY', 'aspf_products_array');
define('ASPF_BRICKS_TAG_LOOP', 'aspf_loop');
define('ASPF_BRICKS_TAG_GROUP', 'Shopify Products (ACF)');
define('ASPF_BRICKS_QUERY_TYPE', 'aspf_products');

add_filter('bricks/dynamic_tags_list', 'aspf_bricks_register_tags');
add_filter('bricks/dynamic_data/render_tag', 'aspf_bricks_render_tag', 20, 3);
add_filter('bricks/dynamic_data/render_content', 'aspf_bricks_render_content', 20, 3);
add_filter('bricks/frontend/render_data', 'aspf_bricks_render_content', 20, 2);

add_filter('bricks/setup/control_options', 'aspf_bricks_register_query_type');
add_filter('bricks/query/run', 'aspf_bricks_run_query', 10, 2);
add_filter('bricks/query/loop_object', 'aspf_bricks_loop_object', 10, 3);
add_action('init', 'aspf_bricks_register_query_controls', 20);

/* -------------------------------------------------------------------------
 * Dynamic data tags
 * ---------------------------------------------------------------------- */

function aspf_bricks_register_tags($tags) {
	$loop_props = array(
		'title' => 'Title',
		'handle' => 'Handle',
		'image' => 'Image',
		'gid' => 'GID',
	);
	foreach ($loop_props as $prop => $label) {
		$tags[] = array(
			'name' => '{' . ASPF_BRICKS_TAG_LOOP . ':' . $prop . '}',
			'label' => 'Loop item: ' . $label,
			'group' => ASPF_BRICKS_TAG_GROUP,
		);
	}

	if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
		return $tags;
	}

	foreach (acf_get_field_groups() as $group) {
		$fields = acf_get_fields($group['key']);
		if (empty($fields)) {
			continue;
		}

		foreach (aspf_bricks_flatten_fields($fields) as $field) {
			if ($field['type'] === 'shopify_product') {
				$tags[] = array(
					'name' => '{' . ASPF_BRICKS_TAG_SINGLE . ':' . $field['name'] . '}',
					'label' => $field['label'] . ' (title)',
					'group' => ASPF_BRICKS_TAG_GROUP,
				);
				$tags[] = array(
					'name' => '{' . ASPF_BRICKS_TAG_SINGLE . ':' . $field['name'] . ':image}',
					'label' => $field['label'] . ' (image)',
					'group' => ASPF_BRICKS_TAG_GROUP,
				);
			} elseif ($field['type'] === 'shopify_products') {
				$tags[] = array(
					'name' => '{' . ASPF_BRICKS_TAG_MULTI . ':' . $field['name'] . '}',
					'label' => $field['label'] . ' (titles)',
					'group' => ASPF_BRICKS_TAG_GROUP,
				);
				$tags[] = array(
					'name' => '{' . ASPF_BRICKS_TAG_MULTI . ':' . $field['name'] . ':image}',
					'label' => $field['label'] . ' (images)',
					'group' => ASPF_BRICKS_TAG_GROUP,
				);
				$tags[] = array(
					'name' => '{' . ASPF_BRICKS_TAG_ARRAY . ':' . $field['name'] . '}',
					'label' => $field['label'] . ' (array, loop source)',
					'group' => ASPF_BRICKS_TAG_GROUP,
				);
			}
		}
	}

	return $tags;
}

function aspf_bricks_flatten_fields($fields) {
	$result = array();

	foreach ($fields as $field) {
		$result[] = $field;

		if (!empty($field['sub_fields'])) {
			$result = array_merge($result, aspf_bricks_flatten_fields($field['sub_fields']));
		}

		if (!empty($field['layouts'])) {
			foreach ($field['layouts'] as $layout) {
				if (!empty($layout['sub_fields'])) {
					$result = array_merge($result, aspf_bricks_flatten_fields($layout['sub_fields']));
				}
			}
		}
	}

	return $result;
}

function aspf_bricks_render_tag($tag, $post, $context = 'text') {
	if (!is_string($tag)) {
		return $tag;
	}

	$clean_tag = str_replace(array('{', '}'), '', $tag);
	$parts = explode(':', $clean_tag);
	$prefix = array_shift($parts);

	$known = array(ASPF_BRICKS_TAG_SINGLE, ASPF_BRICKS_TAG_MULTI, ASPF_BRICKS_TAG_ARRAY, ASPF_BRICKS_TAG_LOOP);
	if (!in_array($prefix, $known, true)) {
		return $tag;
	}

	if ($prefix === ASPF_BRICKS_TAG_LOOP) {
		$property = !empty($parts) ? implode(':', $parts) : 'title';
		return aspf_bricks_loop_value($property, $context);
	}

	if (empty($parts[0])) {
		return $context === 'image' ? array() : '';
	}

	$field_name = array_shift($parts);
	$post_id = aspf_bricks_get_post_id($post);

	if ($prefix === ASPF_BRICKS_TAG_ARRAY) {
		$products = aspf_get_products_field($field_name, $post_id);
		return (is_array($products) && !is_wp_error($products)) ? $products : array();
	}

	if ($prefix === ASPF_BRICKS_TAG_SINGLE) {
		return aspf_bricks_single_value($field_name, $parts, $post_id, $context);
	}

	return aspf_bricks_multi_value($field_name, $parts, $post_id, $context);
}

function aspf_bricks_render_content($content, $post, $context = 'text') {
	if (!is_string($content)) {
		return $content;
	}

	// Array tag used on its own as a loop source: return the array untouched.
	if (preg_match('/^\s*{' . ASPF_BRICKS_TAG_ARRAY . ':[^}]+}\s*$/', $content)) {
		return aspf_bricks_render_tag(trim($content), $post, $context);
	}

	$prefixes = ASPF_BRICKS_TAG_SINGLE . '|' . ASPF_BRICKS_TAG_MULTI . '|' . ASPF_BRICKS_TAG_ARRAY . '|' . ASPF_BRICKS_TAG_LOOP;

	if (!preg_match_all('/{((?:' . $prefixes . '):[^}]+)}/', $content, $matches)) {
		return $content;
	}

	foreach ($matches[1] as $key => $match) {
		$value = aspf_bricks_render_tag($match, $post, $context);

		if (is_array($value)) {
			$value = implode(', ', array_filter(array_map('aspf_bricks_to_string', $value)));
		}

		$content = str_replace($matches[0][$key], (string) $value, $content);
	}

	return $content;
}

function aspf_bricks_single_value($field_name, $parts, $post_id, $context) {
	$property = !empty($parts) ? implode(':', $parts) : 'title';

	$product = aspf_get_product_field($field_name, $post_id);
	if (empty($product) || is_wp_error($product)) {
		return $context === 'image' ? array() : '';
	}

	return aspf_bricks_format($product, $property, $context);
}

function aspf_bricks_multi_value($field_name, $parts, $post_id, $context) {
	$index = null;
	if (!empty($parts) && is_numeric($parts[0])) {
		$index = (int) array_shift($parts);
	}
	$property = !empty($parts) ? implode(':', $parts) : 'title';

	$products = aspf_get_products_field($field_name, $post_id);
	if (empty($products) || is_wp_error($products)) {
		return $context === 'image' ? array() : '';
	}

	if ($index !== null) {
		if (!isset($products[$index])) {
			return $context === 'image' ? array() : '';
		}
		return aspf_bricks_format($products[$index], $property, $context);
	}

	$values = array();
	foreach ($products as $product) {
		$value = aspf_bricks_get_property($product, $property);
		if ($value !== '' && $value !== null) {
			$values[] = $value;
		}
	}

	if ($context === 'image') {
		return array_values(array_filter(array_map('strval', $values)));
	}

	$separator = apply_filters('aspf_bricks_list_separator', ', ', $field_name, $property);

	return implode($separator, array_map('aspf_bricks_to_string', $values));
}

/**
 * Value of {aspf_loop:PROPERTY} read from the current Bricks loop object.
 * Works inside both the custom query type and an array-tag loop source.
 */
function aspf_bricks_loop_value($property, $context) {
	if (!class_exists('\Bricks\Query')) {
		return '';
	}

	$loop_object = \Bricks\Query::get_loop_object();
	if (!is_array($loop_object)) {
		return $context === 'image' ? array() : '';
	}

	return aspf_bricks_format($loop_object, $property, $context);
}

function aspf_bricks_format($product, $property, $context) {
	$value = aspf_bricks_get_property($product, $property);

	if ($context === 'image') {
		return ($value !== '' && $value !== null) ? array($value) : array();
	}

	return aspf_bricks_to_string($value);
}

function aspf_bricks_get_property($product, $property) {
	if (!is_array($product)) {
		return '';
	}

	$value = $product;
	foreach (explode('.', $property) as $segment) {
		if (is_array($value) && array_key_exists($segment, $value)) {
			$value = $value[$segment];
		} else {
			return '';
		}
	}

	return $value;
}

function aspf_bricks_to_string($value) {
	if (is_array($value)) {
		return wp_json_encode($value);
	}

	if (is_bool($value)) {
		return $value ? '1' : '';
	}

	return (string) $value;
}

/**
 * Post to read the ACF field from. Prefers the object Bricks passed in, then
 * the current loop object if it is a post, then the global post.
 */
function aspf_bricks_get_post_id($post = null) {
	if ($post instanceof WP_Post) {
		return $post->ID;
	}

	if (is_numeric($post)) {
		return (int) $post;
	}

	if (class_exists('\Bricks\Query') && \Bricks\Query::is_looping()) {
		$loop_type = \Bricks\Query::get_loop_object_type();
		if ($loop_type === 'post') {
			return \Bricks\Query::get_loop_object_id();
		}
	}

	return get_the_ID();
}

/* -------------------------------------------------------------------------
 * Query loop type
 * ---------------------------------------------------------------------- */

function aspf_bricks_register_query_type($control_options) {
	$control_options['queryTypes'][ASPF_BRICKS_QUERY_TYPE] = esc_html__('Shopify Products (ACF)', 'acf-shopify-product-field');
	return $control_options;
}

/**
 * Add the "ACF field name" control to the Query group of loop-capable
 * elements. Filter aspf_bricks_query_elements to add more element names.
 */
function aspf_bricks_register_query_controls() {
	$elements = apply_filters('aspf_bricks_query_elements', array('container', 'block', 'div'));

	foreach ($elements as $element) {
		add_filter("bricks/elements/{$element}/controls", 'aspf_bricks_add_query_controls');
	}
}

function aspf_bricks_add_query_controls($controls) {
	$new = array(
		'aspfFieldName' => array(
			'tab' => 'content',
			'group' => 'query',
			'label' => esc_html__('ACF field name', 'acf-shopify-product-field'),
			'type' => 'text',
			'placeholder' => 'featured_products',
			'description' => esc_html__('Name of the ACF Shopify Products field on the current post. Sub-fields inside repeaters are read from the current row.', 'acf-shopify-product-field'),
			'required' => array('query.objectType', '=', ASPF_BRICKS_QUERY_TYPE),
		),
		'aspfLimit' => array(
			'tab' => 'content',
			'group' => 'query',
			'label' => esc_html__('Limit', 'acf-shopify-product-field'),
			'type' => 'number',
			'min' => 0,
			'placeholder' => '0',
			'description' => esc_html__('0 or empty shows all selected products.', 'acf-shopify-product-field'),
			'required' => array('query.objectType', '=', ASPF_BRICKS_QUERY_TYPE),
		),
	);

	// Insert directly after the existing query control so it sits in the same panel.
	if (isset($controls['query'])) {
		$position = array_search('query', array_keys($controls), true) + 1;
		return array_slice($controls, 0, $position, true) + $new + array_slice($controls, $position, null, true);
	}

	return $controls + $new;
}

function aspf_bricks_run_query($results, $query_obj) {
	if ($query_obj->object_type !== ASPF_BRICKS_QUERY_TYPE) {
		return $results;
	}

	$settings = $query_obj->settings;
	$field_name = isset($settings['aspfFieldName']) ? trim((string) $settings['aspfFieldName']) : '';

	if ($field_name === '') {
		return array();
	}

	$post_id = aspf_bricks_get_post_id();

	// Inside an ACF repeater / flexible content row, get_field() cannot read a
	// sub-field by name; fall back to get_sub_field() when available.
	$gids = get_field($field_name, $post_id);
	if (empty($gids) && function_exists('get_sub_field')) {
		$sub = get_sub_field($field_name);
		if (!empty($sub)) {
			$gids = $sub;
		}
	}

	if (empty($gids) || !is_array($gids)) {
		return array();
	}

	$limit = isset($settings['aspfLimit']) ? (int) $settings['aspfLimit'] : 0;
	if ($limit > 0) {
		$gids = array_slice($gids, 0, $limit);
	}

	$products = aspf_get_products($gids);
	if (is_wp_error($products) || !is_array($products)) {
		return array();
	}

	/**
	 * Modify the product list before Bricks loops over it.
	 *
	 * @param array  $products   Product summaries (gid, title, handle, image).
	 * @param string $field_name ACF field name.
	 * @param int    $post_id    Post the field was read from.
	 * @param object $query_obj  Bricks query object.
	 */
	return apply_filters('aspf_bricks_query_products', $products, $field_name, $post_id, $query_obj);
}

function aspf_bricks_loop_object($loop_object, $loop_key, $query_obj) {
	if ($query_obj->object_type !== ASPF_BRICKS_QUERY_TYPE) {
		return $loop_object;
	}

	// Each result is already a product summary array; hand it through unchanged.
	return $loop_object;
}
