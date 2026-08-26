(function ($) {
	'use strict';

	function destroySelect2($select) {
		if ($select.data('select2')) {
			$select.select2('destroy');
		}
	}

	function formatProduct(product) {
		if (product.loading || !product.image) {
			return product.text;
		}
		var $result = $(
			'<span class="aspf-product-option"></span>'
		);
		$('<img>', { src: product.image, alt: '' }).appendTo($result);
		$('<span>', { text: product.text }).appendTo($result);
		return $result;
	}

	function initSelect2(field) {
		var $select = field.$el.find('select.aspf-shopify-product-select');
		if (!$select.length) {
			return;
		}

		destroySelect2($select);

		$select.select2({
			width: '100%',
			allowClear: true,
			placeholder: $select.data('placeholder') || '',
			minimumInputLength: 1,
			ajax: {
				url: $select.data('ajax-url'),
				dataType: 'json',
				delay: 300,
				data: function (params) {
					return {
						action: 'aspf_search_products',
						nonce: $select.data('nonce'),
						term: params.term || '',
					};
				},
				processResults: function (response) {
					if (!response || !response.success || !response.data || !response.data.results) {
						return { results: [] };
					}
					return {
						results: response.data.results.map(function (product) {
							return {
								id: product.gid,
								text: product.title,
								image: product.image,
							};
						}),
					};
				},
			},
			templateResult: formatProduct,
		});
	}

	if (window.acf) {
		acf.addAction('ready_field/type=shopify_product', initSelect2);
		acf.addAction('append_field/type=shopify_product', initSelect2);
	}
})(jQuery);
