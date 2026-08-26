<?php

if (!defined('ABSPATH')) {
	exit;
}

class ASPF_Ajax_Handlers {

	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('wp_ajax_aspf_search_products', array($this, 'search_products'));
	}

	public function search_products() {
		check_ajax_referer('aspf_search_products', 'nonce');

		if (!current_user_can('edit_posts')) {
			wp_send_json_error(array('message' => __('Insufficient permissions.', 'acf-shopify-product-field')), 403);
		}

		$search_term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
		$exclude = isset($_GET['exclude']) ? array_map('sanitize_text_field', (array) wp_unslash($_GET['exclude'])) : array();
		$collection_gid = isset($_GET['collection']) ? sanitize_text_field(wp_unslash($_GET['collection'])) : '';

		$client = ASPF_Shopify_Client::from_settings();
		if (is_wp_error($client)) {
			wp_send_json_error(array('message' => $client->get_error_message()), 400);
		}

		$results = $collection_gid !== ''
			? $client->search_products_in_collection($collection_gid, $search_term)
			: $client->search_products($search_term);

		if (is_wp_error($results)) {
			wp_send_json_error(array('message' => $results->get_error_message()), 502);
		}

		if (!empty($exclude)) {
			$results = array_values(array_filter($results, function ($product) use ($exclude) {
				return !in_array($product['gid'], $exclude, true);
			}));
		}

		wp_send_json_success(array('results' => $results));
	}
}
