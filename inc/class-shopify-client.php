<?php

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Thin wrapper around the Shopify Storefront GraphQL API.
 *
 * Storefront API tokens are scoped to unauthenticated/public reads, so this
 * client is safe to call from the browser-facing side of a query, but here
 * it's only ever used server-side (admin-ajax search, and the aspf_get_product
 * helper) since the token still lives in wp_options.
 */
class ASPF_Shopify_Client {

	const API_VERSION = '2024-10';

	private $shop_domain;
	private $storefront_token;

	public function __construct($shop_domain, $storefront_token) {
		$this->shop_domain = $shop_domain;
		$this->storefront_token = $storefront_token;
	}

	public static function from_settings() {
		$settings = ASPF_Plugin_Settings::get_credentials();
		if (empty($settings['shop_domain']) || empty($settings['storefront_token'])) {
			return new WP_Error('aspf_missing_credentials', __('Shopify shop domain and Storefront API token must be configured before this field can be used.', 'acf-shopify-product-field'));
		}
		return new self($settings['shop_domain'], $settings['storefront_token']);
	}

	private function endpoint() {
		return sprintf('https://%s/api/%s/graphql.json', $this->shop_domain, self::API_VERSION);
	}

	private function request($query, $variables = array()) {
		$response = wp_remote_post($this->endpoint(), array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type' => 'application/json',
				'X-Shopify-Storefront-Access-Token' => $this->storefront_token,
			),
			'body' => wp_json_encode(array(
				'query' => $query,
				'variables' => $variables,
			)),
		));

		if (is_wp_error($response)) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code($response);
		$body = json_decode(wp_remote_retrieve_body($response), true);

		if ($status < 200 || $status >= 300) {
			return new WP_Error('aspf_http_error', sprintf(__('Shopify Storefront API returned HTTP %d.', 'acf-shopify-product-field'), $status));
		}

		if (!empty($body['errors'])) {
			$message = is_array($body['errors']) ? wp_json_encode($body['errors']) : (string) $body['errors'];
			return new WP_Error('aspf_graphql_error', $message);
		}

		return $body['data'] ?? array();
	}

	/**
	 * Search products by title. Returns a list of [ 'gid' => ..., 'title' => ..., 'image' => ... ].
	 */
	public function search_products($search_term, $limit = 20) {
		$query = <<<'GRAPHQL'
query SearchProducts($query: String!, $first: Int!) {
  products(query: $query, first: $first) {
    edges {
      node {
        id
        title
        handle
        featuredImage {
          url(transform: { maxWidth: 64, maxHeight: 64 })
        }
      }
    }
  }
}
GRAPHQL;

		$search_term = trim((string) $search_term);
		// Shopify's product search treats a literal empty string as "match
		// nothing", but a whitespace-only query as "no filter" (matches
		// everything) -- so a blank search term is sent as a single space,
		// not "", to get the default product listing instead of zero results.
		$shopify_query = $search_term === '' ? ' ' : sprintf('title:*%s*', $search_term);

		$data = $this->request($query, array(
			'query' => $shopify_query,
			'first' => max(1, min(50, (int) $limit)),
		));

		if (is_wp_error($data)) {
			return $data;
		}

		$results = array();
		foreach ($data['products']['edges'] ?? array() as $edge) {
			$node = $edge['node'];
			$results[] = array(
				'gid' => $node['id'],
				'title' => $node['title'],
				'handle' => $node['handle'],
				'image' => $node['featuredImage']['url'] ?? '',
			);
		}

		return $results;
	}

	/**
	 * Bulk-fetch lightweight summaries (gid, title, handle, image) for a list of
	 * GIDs in a single request, preserving the order of $gids. Used by the
	 * multi-select "Shopify Products" field to label already-selected items
	 * without one API call per product.
	 */
	public function get_products($gids) {
		$gids = array_values(array_unique(array_filter((array) $gids)));
		if (empty($gids)) {
			return array();
		}

		$query = <<<'GRAPHQL'
query GetProducts($ids: [ID!]!) {
  nodes(ids: $ids) {
    id
    ... on Product {
      title
      handle
      featuredImage {
        url(transform: { maxWidth: 64, maxHeight: 64 })
      }
    }
  }
}
GRAPHQL;

		$data = $this->request($query, array('ids' => $gids));
		if (is_wp_error($data)) {
			return $data;
		}

		$by_id = array();
		foreach ($data['nodes'] ?? array() as $node) {
			if (empty($node) || !isset($node['title'])) {
				continue;
			}
			$by_id[$node['id']] = array(
				'gid' => $node['id'],
				'title' => $node['title'],
				'handle' => $node['handle'] ?? '',
				'image' => $node['featuredImage']['url'] ?? '',
			);
		}

		$ordered = array();
		foreach ($gids as $gid) {
			if (isset($by_id[$gid])) {
				$ordered[] = $by_id[$gid];
			}
		}

		return $ordered;
	}

	/**
	 * List collections for the "Restrict to Collection" field setting.
	 */
	public function get_collections($limit = 250) {
		$query = <<<'GRAPHQL'
query GetCollections($first: Int!) {
  collections(first: $first, sortKey: TITLE) {
    edges {
      node {
        id
        title
        handle
      }
    }
  }
}
GRAPHQL;

		$data = $this->request($query, array('first' => max(1, min(250, (int) $limit))));
		if (is_wp_error($data)) {
			return $data;
		}

		$collections = array();
		foreach ($data['collections']['edges'] ?? array() as $edge) {
			$node = $edge['node'];
			$collections[] = array(
				'gid' => $node['id'],
				'title' => $node['title'],
				'handle' => $node['handle'],
			);
		}

		return $collections;
	}

	/**
	 * Search by product title within a single collection. The Storefront
	 * API's Collection.products connection doesn't take a title-search
	 * argument the way the top-level products() field does, so this fetches
	 * up to 250 products from the collection and filters by title in PHP —
	 * collections larger than 250 products only search their first page.
	 */
	public function search_products_in_collection($collection_gid, $search_term, $limit = 20) {
		$query = <<<'GRAPHQL'
query SearchCollectionProducts($id: ID!, $first: Int!) {
  collection(id: $id) {
    products(first: $first) {
      edges {
        node {
          id
          title
          handle
          featuredImage {
            url(transform: { maxWidth: 64, maxHeight: 64 })
          }
        }
      }
    }
  }
}
GRAPHQL;

		$data = $this->request($query, array(
			'id' => $collection_gid,
			'first' => 250,
		));

		if (is_wp_error($data)) {
			return $data;
		}

		if (empty($data['collection'])) {
			return new WP_Error('aspf_collection_not_found', __('The configured Shopify collection could not be found.', 'acf-shopify-product-field'));
		}

		$search_term = trim((string) $search_term);
		$limit = max(1, min(50, (int) $limit));
		$results = array();

		foreach ($data['collection']['products']['edges'] ?? array() as $edge) {
			$node = $edge['node'];
			if ($search_term !== '' && stripos($node['title'], $search_term) === false) {
				continue;
			}
			$results[] = array(
				'gid' => $node['id'],
				'title' => $node['title'],
				'handle' => $node['handle'],
				'image' => $node['featuredImage']['url'] ?? '',
			);
			if (count($results) >= $limit) {
				break;
			}
		}

		return $results;
	}

	/**
	 * Fetch full product data for a stored GID, e.g. gid://shopify/Product/1234567890.
	 */
	public function get_product($gid) {
		$query = <<<'GRAPHQL'
query GetProduct($id: ID!) {
  product(id: $id) {
    id
    title
    handle
    description
    descriptionHtml
    vendor
    productType
    tags
    availableForSale
    onlineStoreUrl
    featuredImage {
      url
      altText
    }
    images(first: 10) {
      edges {
        node {
          url
          altText
        }
      }
    }
    priceRange {
      minVariantPrice {
        amount
        currencyCode
      }
      maxVariantPrice {
        amount
        currencyCode
      }
    }
    variants(first: 50) {
      edges {
        node {
          id
          title
          availableForSale
          quantityAvailable
          price {
            amount
            currencyCode
          }
          selectedOptions {
            name
            value
          }
        }
      }
    }
  }
}
GRAPHQL;

		if (empty($gid)) {
			return new WP_Error('aspf_missing_gid', __('No Shopify product GID provided.', 'acf-shopify-product-field'));
		}

		$data = $this->request($query, array('id' => $gid));

		if (is_wp_error($data)) {
			return $data;
		}

		if (empty($data['product'])) {
			return new WP_Error('aspf_not_found', __('No Shopify product found for the stored GID.', 'acf-shopify-product-field'));
		}

		return $data['product'];
	}
}
