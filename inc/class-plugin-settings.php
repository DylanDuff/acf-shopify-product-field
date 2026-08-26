<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Settings > Shopify Product Field: shop domain + Storefront API access token.
 * The token is stored as plain text in wp_options (Storefront tokens are
 * public-read scoped by design, same trust level as embedding them in a theme).
 */
class ASPF_Plugin_Settings {

	const OPTION_NAME = 'aspf_settings';

	private static $instance = null;

	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action('admin_menu', array($this, 'register_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
	}

	public static function get_credentials() {
		$defaults = array(
			'shop_domain' => '',
			'storefront_token' => '',
		);
		return wp_parse_args(get_option(self::OPTION_NAME, array()), $defaults);
	}

	public function register_settings_page() {
		add_options_page(
			__('Shopify Product Field', 'acf-shopify-product-field'),
			__('Shopify Product Field', 'acf-shopify-product-field'),
			'manage_options',
			'aspf-settings',
			array($this, 'render_settings_page')
		);
	}

	public function register_settings() {
		register_setting('aspf_settings_group', self::OPTION_NAME, array($this, 'sanitize_settings'));

		add_settings_section(
			'aspf_main_section',
			__('Shopify Storefront API Credentials', 'acf-shopify-product-field'),
			function () {
				echo '<p>' . esc_html__('Used by the ACF Shopify Product field to search products and resolve stored product GIDs.', 'acf-shopify-product-field') . '</p>';
			},
			'aspf-settings'
		);

		add_settings_field(
			'shop_domain',
			__('Shop Domain', 'acf-shopify-product-field'),
			array($this, 'render_shop_domain_field'),
			'aspf-settings',
			'aspf_main_section'
		);

		add_settings_field(
			'storefront_token',
			__('Storefront API Access Token', 'acf-shopify-product-field'),
			array($this, 'render_storefront_token_field'),
			'aspf-settings',
			'aspf_main_section'
		);
	}

	public function sanitize_settings($input) {
		$existing = self::get_credentials();

		$sanitized = array();
		$sanitized['shop_domain'] = isset($input['shop_domain'])
			? preg_replace('#^https?://#', '', trim(sanitize_text_field($input['shop_domain'])))
			: $existing['shop_domain'];
		$sanitized['shop_domain'] = untrailingslashit($sanitized['shop_domain']);

		$sanitized['storefront_token'] = isset($input['storefront_token']) && $input['storefront_token'] !== ''
			? sanitize_text_field($input['storefront_token'])
			: $existing['storefront_token'];

		return $sanitized;
	}

	public function render_settings_page() {
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Shopify Product Field Settings', 'acf-shopify-product-field'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('aspf_settings_group');
				do_settings_sections('aspf-settings');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public function render_shop_domain_field() {
		$credentials = self::get_credentials();
		?>
		<input type="text" class="regular-text" name="<?php echo esc_attr(self::OPTION_NAME); ?>[shop_domain]"
			value="<?php echo esc_attr($credentials['shop_domain']); ?>" placeholder="your-shop.myshopify.com" />
		<p class="description"><?php esc_html_e('The .myshopify.com domain, without protocol or trailing slash.', 'acf-shopify-product-field'); ?></p>
		<?php
	}

	public function render_storefront_token_field() {
		$credentials = self::get_credentials();
		$has_token = !empty($credentials['storefront_token']);
		?>
		<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr(self::OPTION_NAME); ?>[storefront_token]"
			value="" placeholder="<?php echo $has_token ? esc_attr__('Leave blank to keep the current token', 'acf-shopify-product-field') : ''; ?>" />
		<p class="description"><?php esc_html_e('A Storefront API access token from a Shopify custom app (unauthenticated_read_product_listings scope is sufficient).', 'acf-shopify-product-field'); ?></p>
		<?php
	}
}
