<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('acf_field_shopify_product')) :

	/**
	 * ACF field type that searches Shopify products (via the Storefront API)
	 * and stores the selected product's GID as the field value.
	 */
	class acf_field_shopify_product extends acf_field {

		public function __construct() {
			$this->name = 'shopify_product';
			$this->label = __('Shopify Product', 'acf-shopify-product-field');
			$this->category = 'relational';
			$this->description = __('Search and select a Shopify product. Stores the product GID; use aspf_get_product_field() to resolve it back to live product data.', 'acf-shopify-product-field');
			$this->defaults = array(
				'placeholder' => '',
			);

			parent::__construct();
		}

		public function render_field_settings($field) {
			acf_render_field_setting($field, array(
				'label' => __('Placeholder Text', 'acf-shopify-product-field'),
				'instructions' => __('Appears in the empty search input.', 'acf-shopify-product-field'),
				'type' => 'text',
				'name' => 'placeholder',
			));
		}

		public function render_field($field) {
			$value = $field['value'];
			$label = '';

			if (!empty($value)) {
				$label = aspf_get_cached_product_label($value);
			}

			$placeholder = !empty($field['placeholder']) ? $field['placeholder'] : __('Search Shopify products…', 'acf-shopify-product-field');
			?>
			<select
				id="<?php echo esc_attr($field['id']); ?>"
				class="aspf-shopify-product-select"
				style="width:100%"
				name="<?php echo esc_attr($field['name']); ?>"
				data-nonce="<?php echo esc_attr(wp_create_nonce('aspf_search_products')); ?>"
				data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
				data-placeholder="<?php echo esc_attr($placeholder); ?>"
			>
				<?php if (!empty($value)) : ?>
					<option value="<?php echo esc_attr($value); ?>" selected="selected"><?php echo esc_html($label !== '' ? $label : $value); ?></option>
				<?php endif; ?>
			</select>
			<?php
		}

		public function input_admin_enqueue_scripts() {
			$version = get_file_data(ASPF_PLUGIN_FILE, array('Version' => 'Version'))['Version'];

			wp_enqueue_style(
				'aspf-shopify-product-field',
				ASPF_PLUGIN_URL . 'assets/css/shopify-product-field.css',
				array(),
				$version
			);

			wp_enqueue_script(
				'aspf-shopify-product-field',
				ASPF_PLUGIN_URL . 'assets/js/shopify-product-field.js',
				array('acf-input', 'jquery', 'select2'),
				$version,
				true
			);
		}
	}

endif;
