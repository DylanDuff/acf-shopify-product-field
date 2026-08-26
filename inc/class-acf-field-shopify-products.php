<?php

if (!defined('ABSPATH')) {
	exit;
}

if (!class_exists('acf_field_shopify_products')) :

	/**
	 * ACF field type that searches Shopify products (via the Storefront API)
	 * and stores an ordered array of selected products' GIDs. Works like ACF's
	 * own Relationship field: a search panel on the left, a sortable list of
	 * selected products on the right.
	 */
	class acf_field_shopify_products extends acf_field {

		public function __construct() {
			$this->name = 'shopify_products';
			$this->label = __('Shopify Products', 'acf-shopify-product-field');
			$this->category = 'relational';
			$this->description = __('Search and select multiple Shopify products, reorderable like the ACF Relationship field. Stores an ordered array of product GIDs.', 'acf-shopify-product-field');
			$this->defaults = array(
				'placeholder' => '',
				'max' => 0,
				'collection_gid' => '',
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

			acf_render_field_setting($field, array(
				'label' => __('Maximum Products', 'acf-shopify-product-field'),
				'instructions' => __('Leave blank or 0 for no limit.', 'acf-shopify-product-field'),
				'type' => 'number',
				'name' => 'max',
				'min' => 0,
			));

			$choices = array('' => __('— All products —', 'acf-shopify-product-field'));
			foreach (aspf_get_cached_collections() as $collection) {
				$choices[$collection['gid']] = $collection['title'];
			}

			acf_render_field_setting($field, array(
				'label' => __('Restrict to Collection', 'acf-shopify-product-field'),
				'instructions' => __('Only allow selecting products from this collection. Requires Shopify credentials to be configured under Settings → Shopify Product Field.', 'acf-shopify-product-field'),
				'type' => 'select',
				'name' => 'collection_gid',
				'choices' => $choices,
				'allow_null' => false,
			));
		}

		public function render_field($field) {
			$value = is_array($field['value']) ? array_values(array_filter($field['value'])) : array();
			$selected_products = !empty($value) ? aspf_get_cached_products_summary($value) : array();
			$placeholder = !empty($field['placeholder']) ? $field['placeholder'] : __('Search Shopify products…', 'acf-shopify-product-field');
			$max = (int) $field['max'];
			?>
			<div class="aspf-shopify-products-field">
				<input type="hidden" name="<?php echo esc_attr($field['name']); ?>" value="" />
				<div class="aspf-relationship">
					<div class="aspf-relationship-left">
						<?php
						$is_locked_to_collection = !empty($field['collection_gid']);
						$collections = $is_locked_to_collection ? array() : aspf_get_cached_collections();
						?>
						<div class="aspf-products-search-row">
							<input
								type="text"
								class="aspf-products-search"
								placeholder="<?php echo esc_attr($placeholder); ?>"
								data-ajax-url="<?php echo esc_url(admin_url('admin-ajax.php')); ?>"
								data-nonce="<?php echo esc_attr(wp_create_nonce('aspf_search_products')); ?>"
								data-collection-gid="<?php echo esc_attr($field['collection_gid']); ?>"
							/>
							<?php if (!empty($collections)) : ?>
								<select class="aspf-collection-filter">
									<option value=""><?php esc_html_e('All Collections', 'acf-shopify-product-field'); ?></option>
									<?php foreach ($collections as $collection) : ?>
										<option value="<?php echo esc_attr($collection['gid']); ?>"><?php echo esc_html($collection['title']); ?></option>
									<?php endforeach; ?>
								</select>
							<?php endif; ?>
						</div>
						<ul class="aspf-products-results"></ul>
					</div>
					<div class="aspf-relationship-right">
						<?php if ($max > 0) : ?>
							<p class="aspf-products-max-notice">
								<?php
								printf(
									/* translators: %d: maximum number of products */
									esc_html(_n('Maximum of %d product.', 'Maximum of %d products.', $max, 'acf-shopify-product-field')),
									$max
								);
								?>
							</p>
						<?php endif; ?>
						<ul class="aspf-products-values" data-max="<?php echo esc_attr($max); ?>">
							<?php foreach ($selected_products as $product) : ?>
								<li data-gid="<?php echo esc_attr($product['gid']); ?>">
									<input type="hidden" name="<?php echo esc_attr($field['name']); ?>[]" value="<?php echo esc_attr($product['gid']); ?>" />
									<?php if (!empty($product['image'])) : ?>
										<img src="<?php echo esc_url($product['image']); ?>" alt="" />
									<?php endif; ?>
									<span class="aspf-product-title"><?php echo esc_html($product['title']); ?></span>
									<a href="#" class="aspf-remove-product" aria-label="<?php esc_attr_e('Remove', 'acf-shopify-product-field'); ?>">&times;</a>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			</div>
			<?php
		}

		public function input_admin_enqueue_scripts() {
			$version = get_file_data(ASPF_PLUGIN_FILE, array('Version' => 'Version'))['Version'];

			wp_enqueue_style(
				'aspf-shopify-products-field',
				ASPF_PLUGIN_URL . 'assets/css/shopify-products-field.css',
				array(),
				$version
			);

			wp_enqueue_script(
				'aspf-shopify-products-field',
				ASPF_PLUGIN_URL . 'assets/js/shopify-products-field.js',
				array('acf-input', 'jquery', 'jquery-ui-sortable'),
				$version,
				true
			);
		}
	}

endif;
