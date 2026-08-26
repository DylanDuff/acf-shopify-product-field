(function ($) {
	'use strict';

	function renderResultItem(product) {
		var $li = $('<li></li>', {
			'data-gid': product.gid,
			'data-title': product.title,
			'data-image': product.image || '',
			tabindex: 0,
		});
		if (product.image) {
			$('<img>', { src: product.image, alt: '' }).appendTo($li);
		}
		$('<span>', { text: product.title }).appendTo($li);
		return $li;
	}

	function renderValueItem(baseName, product) {
		var $li = $('<li></li>', { 'data-gid': product.gid });
		$('<input>', { type: 'hidden', name: baseName + '[]', value: product.gid }).appendTo($li);
		if (product.image) {
			$('<img>', { src: product.image, alt: '' }).appendTo($li);
		}
		$('<span>', { class: 'aspf-product-title', text: product.title }).appendTo($li);
		$('<a>', { href: '#', class: 'aspf-remove-product', 'aria-label': 'Remove' }).html('&times;').appendTo($li);
		return $li;
	}

	function currentGids($values) {
		return $values
			.find('> li')
			.map(function () {
				return $(this).data('gid');
			})
			.get();
	}

	function initField(field) {
		var $wrap = field.$el.find('.aspf-shopify-products-field');
		if (!$wrap.length || $wrap.data('aspf-inited')) {
			return;
		}
		$wrap.data('aspf-inited', true);

		var $search = $wrap.find('.aspf-products-search');
		var $results = $wrap.find('.aspf-products-results');
		var $values = $wrap.find('.aspf-products-values');
		var $collectionFilter = $wrap.find('.aspf-collection-filter');
		var baseName = $wrap.children('input[type="hidden"]').attr('name');
		var max = parseInt($values.data('max'), 10) || 0;
		var searchTimer = null;

		function activeCollectionGid() {
			return $collectionFilter.length ? $collectionFilter.val() || '' : $search.data('collection-gid') || '';
		}

		$values.sortable({
			items: '> li',
			cursor: 'move',
			scrollSensitivity: 40,
		});

		function isAtMax() {
			return max > 0 && $values.find('> li').length >= max;
		}

		function runSearch(term) {
			$.ajax({
				url: $search.data('ajax-url'),
				dataType: 'json',
				data: {
					action: 'aspf_search_products',
					nonce: $search.data('nonce'),
					term: term,
					exclude: currentGids($values),
					collection: activeCollectionGid(),
				},
			}).done(function (response) {
				$results.empty();
				if (!response || !response.success || !response.data || !response.data.results) {
					return;
				}
				response.data.results.forEach(function (product) {
					$results.append(renderResultItem(product));
				});
			});
		}

		$search.on('input', function () {
			var term = $(this).val();
			clearTimeout(searchTimer);
			if (term.length < 1) {
				$results.empty();
				return;
			}
			searchTimer = setTimeout(function () {
				runSearch(term);
			}, 300);
		});

		$collectionFilter.on('change', function () {
			var term = $search.val();
			clearTimeout(searchTimer);
			if (term.length >= 1) {
				runSearch(term);
			}
		});

		$results.on('click', '> li', function () {
			if (isAtMax()) {
				return;
			}
			var $li = $(this);
			$values.append(
				renderValueItem(baseName, {
					gid: $li.data('gid'),
					title: $li.data('title'),
					image: $li.data('image'),
				})
			);
			$li.remove();
		});

		$values.on('click', '.aspf-remove-product', function (e) {
			e.preventDefault();
			$(this).closest('li').remove();
		});
	}

	if (window.acf) {
		acf.addAction('ready_field/type=shopify_products', initField);
		acf.addAction('append_field/type=shopify_products', initField);
	}
})(jQuery);
