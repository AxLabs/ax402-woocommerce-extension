<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Maps WooCommerce products to UCP catalog Product / Variant shapes.
 */
final class Ax402_WC_Ucp_Catalog
{
    public const CAP_SEARCH = 'dev.ucp.shopping.catalog.search';
    public const CAP_LOOKUP = 'dev.ucp.shopping.catalog.lookup';

    /**
     * @param array<string, mixed> $body
     */
    public function search(array $body): WP_REST_Response
    {
        $query = trim((string) ($body['query'] ?? ''));
        $pagination = is_array($body['pagination'] ?? null) ? $body['pagination'] : [];
        $limit = min(50, max(1, (int) ($pagination['limit'] ?? 10)));
        $page = self::page_from_cursor((string) ($pagination['cursor'] ?? ''));
        $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];

        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'paged' => $page,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
        ];
        if ($query !== '') {
            $args['s'] = $query;
        }

        $categories = $filters['categories'] ?? $filters['category'] ?? [];
        if (is_string($categories)) {
            $categories = [$categories];
        }
        if (is_array($categories) && $categories !== []) {
            $slugs = [];
            foreach ($categories as $cat) {
                $slugs[] = sanitize_title((string) $cat);
            }
            $args['tax_query'] = [[
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => $slugs,
            ]];
        }

        $q = new WP_Query($args);
        $products = [];
        foreach ($q->posts as $product_id) {
            $mapped = $this->map_product((int) $product_id, false);
            if ($mapped !== null) {
                $products[] = $mapped;
            }
        }

        $has_next = $page * $limit < (int) $q->found_posts;
        $body_out = [
            'ucp' => Ax402_WC_Ucp_Response::ucp(self::CAP_SEARCH),
            'products' => $products,
            'pagination' => [
                'cursor' => $has_next ? self::cursor_for_page($page + 1) : '',
                'has_next_page' => $has_next,
                'total_count' => (int) $q->found_posts,
            ],
        ];

        return new WP_REST_Response($body_out, 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function lookup(array $body): WP_REST_Response
    {
        $ids = $body['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'ids is required', 'unrecoverable')],
                self::CAP_LOOKUP
            );
        }
        if (count($ids) > 50) {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'request_too_large', 'Lookup batch exceeds 50 ids.', 'unrecoverable')],
                self::CAP_LOOKUP
            );
        }

        $products = [];
        $messages = [];
        foreach ($ids as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            $mapped = $this->map_by_any_id($id, $id);
            if ($mapped === null) {
                $messages[] = Ax402_WC_Ucp_Response::message('info', 'not_found', $id, 'recoverable');
                continue;
            }
            $products[] = $mapped;
        }

        $out = [
            'ucp' => Ax402_WC_Ucp_Response::ucp(self::CAP_LOOKUP),
            'products' => $products,
        ];
        if ($messages !== []) {
            $out['messages'] = $messages;
        }

        return new WP_REST_Response($out, 200);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function get_product(array $body): WP_REST_Response
    {
        $id = trim((string) ($body['id'] ?? ''));
        if ($id === '') {
            return Ax402_WC_Ucp_Response::rest_error(
                400,
                [Ax402_WC_Ucp_Response::message('error', 'invalid', 'id is required', 'unrecoverable')],
                self::CAP_LOOKUP
            );
        }

        $mapped = $this->map_by_any_id($id, $id, true);
        if ($mapped === null) {
            return Ax402_WC_Ucp_Response::rest_error(
                200,
                [Ax402_WC_Ucp_Response::message(
                    'error',
                    'not_found',
                    'Product not found: ' . $id,
                    'unrecoverable'
                )],
                self::CAP_LOOKUP
            );
        }

        return new WP_REST_Response([
            'ucp' => Ax402_WC_Ucp_Response::ucp(self::CAP_LOOKUP),
            'product' => $mapped,
        ], 200);
    }

    /**
     * Resolve a catalog / checkout item id to a purchasable WC product.
     */
    public static function resolve_purchasable(string $id): ?WC_Product
    {
        $product = self::resolve_product($id);
        if (!$product instanceof WC_Product) {
            return null;
        }
        if ($product->is_type('variable')) {
            return null;
        }
        if (!$product->is_purchasable() || !$product->is_in_stock()) {
            return null;
        }
        $price = (string) $product->get_price();
        if (Ax402_WC_Ucp_Money::usd_to_cents_exact($price) === null) {
            return null;
        }

        return $product;
    }

    public static function resolve_product(string $id): ?WC_Product
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        if (ctype_digit($id)) {
            $product = wc_get_product((int) $id);
            if ($product instanceof WC_Product) {
                return $product;
            }
        }

        $sku_id = wc_get_product_id_by_sku($id);
        if ($sku_id) {
            $product = wc_get_product($sku_id);
            if ($product instanceof WC_Product) {
                return $product;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map_by_any_id(string $id, string $input_id, bool $detail = false): ?array
    {
        $product = self::resolve_product($id);
        if (!$product instanceof WC_Product) {
            return null;
        }

        if ($product->is_type('variation')) {
            $parent = wc_get_product($product->get_parent_id());
            if (!$parent instanceof WC_Product) {
                return null;
            }
            $mapped = $this->map_product($parent->get_id(), $detail, (string) $product->get_id(), $input_id);
            return $mapped;
        }

        return $this->map_product($product->get_id(), $detail, '', $input_id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map_product(
        int $product_id,
        bool $detail,
        string $featured_variant_id = '',
        string $input_id = ''
    ): ?array {
        $product = wc_get_product($product_id);
        if (!$product instanceof WC_Product || $product->get_status() !== 'publish') {
            return null;
        }

        $variants = [];
        if ($product->is_type('variable') && $product instanceof WC_Product_Variable) {
            foreach ($product->get_children() as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation instanceof WC_Product) {
                    continue;
                }
                $variant = $this->map_variant($variation, $product, $input_id);
                if ($variant !== null) {
                    $variants[] = $variant;
                }
            }
            if ($featured_variant_id !== '') {
                $variants = array_values(array_filter(
                    $variants,
                    static fn (array $v): bool => (string) $v['id'] === $featured_variant_id
                ));
            }
        } else {
            $variant = $this->map_variant($product, $product, $input_id);
            if ($variant !== null) {
                $variants[] = $variant;
            }
        }

        if ($variants === []) {
            return null;
        }

        $amounts = array_map(
            static fn (array $v): int => (int) ($v['price']['amount'] ?? 0),
            $variants
        );
        $min = min($amounts);
        $max = max($amounts);

        $out = [
            'id' => (string) $product->get_id(),
            'title' => $product->get_name(),
            'description' => self::description_object(
                (string) $product->get_short_description(),
                (string) $product->get_description(),
                $product->get_name()
            ),
            'url' => get_permalink($product->get_id()) ?: '',
            'categories' => $this->categories($product),
            'price_range' => [
                'min' => ['amount' => $min, 'currency' => Ax402_WC_Ucp_Money::CURRENCY],
                'max' => ['amount' => $max, 'currency' => Ax402_WC_Ucp_Money::CURRENCY],
            ],
            'variants' => $variants,
        ];

        $handle = $product->get_slug();
        if ($handle !== '') {
            $out['handle'] = $handle;
        }

        $image_id = $product->get_image_id();
        if ($image_id) {
            $url = wp_get_attachment_url((int) $image_id);
            if (is_string($url) && $url !== '') {
                $out['media'] = [[
                    'type' => 'image',
                    'url' => $url,
                    'alt_text' => $product->get_name(),
                ]];
            }
        }

        unset($detail);

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function map_variant(WC_Product $product, WC_Product $parent, string $input_id): ?array
    {
        if (!$product->is_purchasable()) {
            return null;
        }
        $cents = Ax402_WC_Ucp_Money::usd_to_cents_exact((string) $product->get_price());
        if ($cents === null) {
            return null;
        }

        $variant = [
            'id' => (string) $product->get_id(),
            'title' => $product->get_name(),
            'description' => self::description_object(
                (string) $product->get_description(),
                (string) $parent->get_short_description(),
                $product->get_name()
            ),
            'price' => [
                'amount' => $cents,
                'currency' => Ax402_WC_Ucp_Money::CURRENCY,
            ],
            'availability' => [
                'available' => $product->is_in_stock(),
                'status' => $product->is_in_stock() ? 'in_stock' : 'out_of_stock',
            ],
            'metadata' => [
                'woocommerce' => Ax402_WC_Ucp_Fulfillment::catalog_metadata($product),
            ],
        ];
        $tags = [];
        if (Ax402_WC_Ucp_Fulfillment::product_is_digital($product)) {
            $tags[] = 'digital';
            if ($product->is_downloadable()) {
                $tags[] = 'downloadable';
            }
            if ($product->is_virtual()) {
                $tags[] = 'virtual';
            }
        } else {
            $tags[] = 'shipping';
        }
        if ($tags !== []) {
            $variant['tags'] = $tags;
        }

        $sku = (string) $product->get_sku();
        if ($sku !== '') {
            $variant['sku'] = $sku;
        }
        if ($input_id !== '') {
            $variant['inputs'] = [[
                'id' => $input_id,
                'match' => $input_id === (string) $product->get_id() || $input_id === $sku
                    ? 'exact'
                    : 'featured',
            ]];
        }
        unset($parent);

        return $variant;
    }

    /**
     * @return list<array{value:string,taxonomy:string}>
     */
    private function categories(WC_Product $product): array
    {
        $terms = get_the_terms($product->get_id(), 'product_cat');
        if (!is_array($terms)) {
            return [];
        }
        $out = [];
        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }
            $out[] = [
                'value' => $term->name,
                'taxonomy' => 'merchant',
            ];
        }

        return $out;
    }

    /**
     * UCP description object. Woo already has product/short description;
     * variants inherit that text (title as last resort).
     *
     * @return array{plain: string, html?: string}
     */
    public static function description_object(string $primary, string $secondary, string $fallback): array
    {
        $html = $primary !== '' ? $primary : $secondary;
        $plain = trim(wp_strip_all_tags($html));
        if ($plain === '') {
            $plain = $fallback;
        }
        $out = ['plain' => $plain];
        if ($html !== '' && $html !== $plain) {
            $out['html'] = $html;
        }

        return $out;
    }

    public static function page_from_cursor(string $cursor): int
    {
        if ($cursor === '') {
            return 1;
        }
        $decoded = base64_decode($cursor, true);
        if (!is_string($decoded) || $decoded === '') {
            return 1;
        }
        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            return 1;
        }

        return max(1, (int) ($json['page'] ?? 1));
    }

    public static function cursor_for_page(int $page): string
    {
        $encoded = base64_encode((string) wp_json_encode(['page' => $page]));
        return is_string($encoded) ? $encoded : '';
    }
}
