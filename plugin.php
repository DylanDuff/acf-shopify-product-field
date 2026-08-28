<?php
/**
 * Plugin Name: ACF Shopify Product Field
 * Plugin URI: https://github.com/DylanDuff/acf-shopify-product-field
 * Description: Adds an ACF field type that searches Shopify products via the Storefront API and stores the selected product's GID. Includes a helper to resolve stored GIDs back into live product data.
 * Version: 0.6
 * Requires PHP: 7.4
 * Author: Dylan Duff
 * License: GPL-2.0-or-later
 * Text Domain: acf-shopify-product-field
 */

if (!defined('ABSPATH')) {
	exit;
}

define('ASPF_PLUGIN_FILE', __FILE__);
define('ASPF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('ASPF_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once ASPF_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$aspfUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/DylanDuff/acf-shopify-product-field/',
	__FILE__,
	'acf-shopify-product-field'
);
$aspfUpdateChecker->setBranch('main');
$aspfUpdateChecker->getVcsApi()->enableReleaseAssets();

require_once ASPF_PLUGIN_DIR . 'inc/class-shopify-client.php';
require_once ASPF_PLUGIN_DIR . 'inc/class-plugin-settings.php';
require_once ASPF_PLUGIN_DIR . 'inc/class-ajax-handlers.php';
require_once ASPF_PLUGIN_DIR . 'inc/bricks-dynamic-data.php';
require_once ASPF_PLUGIN_DIR . 'inc/helpers.php';

add_action('plugins_loaded', 'aspf_bootstrap');

function aspf_bootstrap() {
	if (!class_exists('ACF')) {
		add_action('admin_notices', 'aspf_missing_acf_notice');
		return;
	}

	ASPF_Plugin_Settings::instance();
	ASPF_Ajax_Handlers::instance();

	add_action('acf/include_field_types', 'aspf_include_field_type');
}

function aspf_missing_acf_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__('ACF Shopify Product Field requires Advanced Custom Fields (or ACF PRO) to be installed and active.', 'acf-shopify-product-field');
	echo '</p></div>';
}

function aspf_include_field_type() {
	require_once ASPF_PLUGIN_DIR . 'inc/class-acf-field-shopify-product.php';
	acf_register_field_type('acf_field_shopify_product');

	require_once ASPF_PLUGIN_DIR . 'inc/class-acf-field-shopify-products.php';
	acf_register_field_type('acf_field_shopify_products');
}
