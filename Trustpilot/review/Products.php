<?php
/**
 * Trustpilot-reviews
 *
 *
 * @package   Trustpilot-reviews
 * @author    Trustpilot
 * @license   AFL-3.0
 * @link      https://trustpilot.com
 * @copyright 2018 Trustpilot
 */

namespace Trustpilot\Review;

/**
 * @subpackage Products
 */

class Products {
    /**
	 * Instance of this class.
	 */
	protected static $instance = null;

    /**
	 * Return an instance of this class.
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {
		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
        }
		return self::$instance;
    }
    
    private function trustpilot_get_products($limit, $page) {
        if (function_exists('wc_get_products')) {
            $product_args = array(
                'visibility' => 'visible',
                'status'     => 'publish',
                'limit'      => $limit,
                'orderby'    => 'id',
                'page'       => $page,
            );
            
            return wc_get_products($product_args);
        } else {
            $page = $page - 1;
            $product_args = array(
                'posts_per_page' => $limit,
                'orderby'        => 'published_at',
                'order'          => 'DESC',
                'post_type'      => 'product',
                'offset'         => $page * $limit,
            );

            $posts = get_posts($product_args);
            $products = array();
            if (!empty($posts)) {
                foreach ($posts as $post) {
                    $product = wc_get_product($post);
                    array_push($products, $product);
                }
            }
            return $products;
        }
    }

    private function trustpilot_get_product_type($product) {
        if ($product->is_type('variable')) {
            return 'variable';
        }
        if ($product->is_type('group')) {
            return 'group';
        }
        if ($product->is_type('virtual')) {
            return 'virtual';
        }
        if ($product->is_type('download')) {
            return 'download';
        }
        return 'simple';
    }

    private function buildResponseItem($product) {
        $productId = method_exists($product, 'get_id') ? $product->get_id() : $product->id;
        $item = array();
        $item['id'] = $productId;
        $item['name'] = method_exists($product, 'get_name') ? $product->get_name() : $product->get_title();
        $item['productAdminUrl'] = get_edit_post_link($productId);
        $item['productFrontendUrl'] = get_permalink($productId);
        return $item;
    }

    public function trustpilot_check_skus($skuSelector) {
        $data = array();
        $page_id = 1;
        $productCollection = $this->trustpilot_get_products(20, $page_id);
        while ($productCollection) {
            set_time_limit(30);
            foreach ($productCollection as $product) {
                $sku = trustpilot_get_inventory_attribute($skuSelector, $product, false);
                $childProducts = array();

                if (empty($sku)) {
                    $item = $this->buildResponseItem($product, $childProducts);
                    array_push($data, $item);
                }

                if ($product->is_type('variable')) {
                    $variation_ids = $product->get_children();
                    foreach ($variation_ids as $variation_id) {
                        $childProduct = wc_get_product($variation_id);
                        $productSku = trustpilot_get_inventory_attribute($skuSelector, $childProduct, false);
                        if (empty($productSku)) {
                            $childItem = $this->buildResponseItem($childProduct);
                            array_push($data, $childItem);
                        }
                    }
                }
            }
            wp_cache_flush();
            $page_id = $page_id + 1;
            $productCollection = $this->trustpilot_get_products(20, $page_id);
        }
        return array(
            'skuScannerResults' => $data
        );
    }
}
