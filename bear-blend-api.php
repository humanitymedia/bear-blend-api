<?php
/**
 * Plugin Name: Bear Blend API
 * Plugin URI:  https://bearblend.com
 * Description: Read-only REST API endpoints for migrating Bear Blend site data to Laravel. Exposes customers, orders, products, herbs, and posts — all paginated and protected by a Bearer token.
 * Version:     1.0.3
 * Author:      Bear Blend
 * Requires PHP: 8.2
 * Requires at least: 6.0
 * License:     Proprietary
 */

defined('ABSPATH') || exit;

// ──────────────────────────────────────────────
// 1. ADMIN SETTINGS PAGE
// ──────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page(
        'Bear Blend API',
        'Bear Blend API',
        'manage_options',
        'bear-blend-api',
        'bb_api_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('bb_api_settings', 'bb_api_key');

    add_settings_section('bb_api_main', 'API Authentication', function () {
        echo '<p>Configure the Bearer token that protects the migration API endpoints.</p>';
    }, 'bear-blend-api');

    add_settings_field('bb_api_key', 'API Key', function () {
        $key = get_option('bb_api_key', '');
        echo '<input type="text" name="bb_api_key" value="' . esc_attr($key) . '" size="64" autocomplete="off" />';
        echo '<p class="description">Send this value in the <code>Authorization: Bearer &lt;key&gt;</code> header.</p>';
        if (empty($key)) {
            echo '<p class="description" style="margin-top:6px;">';
            echo '<button type="button" class="button" onclick="document.querySelector(\'input[name=bb_api_key]\').value=crypto.randomUUID().replaceAll(\'-\',\'\')">Generate Key</button>';
            echo '</p>';
        }
    }, 'bear-blend-api', 'bb_api_main');
});

function bb_api_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Bear Blend API Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('bb_api_settings');
            do_settings_sections('bear-blend-api');
            submit_button();
            ?>
        </form>
        <hr>
        <h2>Available Endpoints</h2>
        <table class="widefat fixed striped" style="max-width:700px">
            <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/customers</code></td><td>WP users with meta &amp; roles</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/orders</code></td><td>WooCommerce orders with line items</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/products</code></td><td>Products, variations, categories, reviews</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/herbs</code></td><td>Herb content pages with custom fields</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/posts</code></td><td>Blog posts with images &amp; categories</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/faqs</code></td><td>FAQ entries with categories &amp; ACF fields</td></tr>
            </tbody>
        </table>
        <p>All endpoints accept <code>?page=1&per_page=50</code> query params and return <code>X-WP-Total</code> / <code>X-WP-TotalPages</code> headers.</p>
    </div>
    <?php
}


// ──────────────────────────────────────────────
// 2. AUTH HELPER
// ──────────────────────────────────────────────

/**
 * Verify the Bearer token from the Authorization header.
 * Returns true or a WP_Error.
 */
function bb_api_check_auth(WP_REST_Request $request): bool|WP_Error {
    $stored_key = get_option('bb_api_key', '');

    if (empty($stored_key)) {
        return new WP_Error(
            'bb_api_not_configured',
            'API key has not been configured. Set it under Settings → Bear Blend API.',
            ['status' => 503]
        );
    }

    $header = $request->get_header('Authorization');
    if (empty($header)) {
        return new WP_Error('bb_api_no_auth', 'Missing Authorization header.', ['status' => 401]);
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return new WP_Error('bb_api_bad_auth', 'Authorization header must use Bearer scheme.', ['status' => 401]);
    }

    if (!hash_equals($stored_key, trim($m[1]))) {
        return new WP_Error('bb_api_invalid_key', 'Invalid API key.', ['status' => 403]);
    }

    return true;
}


// ──────────────────────────────────────────────
// 3. PAGINATION HELPER
// ──────────────────────────────────────────────

function bb_api_pagination_args(WP_REST_Request $request): array {
    $page     = max(1, (int) $request->get_param('page') ?: 1);
    $per_page = min(100, max(1, (int) $request->get_param('per_page') ?: 50));
    return ['page' => $page, 'per_page' => $per_page];
}

function bb_api_paginated_response(array $items, int $total, int $page, int $per_page): WP_REST_Response {
    $response = new WP_REST_Response($items);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', (int) ceil($total / $per_page));
    $response->header('X-WP-Page', $page);
    return $response;
}

/**
 * Common pagination args for endpoint schema.
 */
function bb_api_pagination_params(): array {
    return [
        'page' => [
            'description' => 'Page number.',
            'type'        => 'integer',
            'default'     => 1,
            'minimum'     => 1,
        ],
        'per_page' => [
            'description' => 'Items per page (max 100).',
            'type'        => 'integer',
            'default'     => 50,
            'minimum'     => 1,
            'maximum'     => 100,
        ],
    ];
}


// ──────────────────────────────────────────────
// 4. REGISTER ROUTES
// ──────────────────────────────────────────────

add_action('rest_api_init', function () {

    $ns = 'bb/v1';

    $endpoints = [
        'customers' => 'bb_api_get_customers',
        'orders'    => 'bb_api_get_orders',
        'products'  => 'bb_api_get_products',
        'herbs'     => 'bb_api_get_herbs',
        'posts'     => 'bb_api_get_posts',
        'faqs'      => 'bb_api_get_faqs',
    ];

    foreach ($endpoints as $route => $callback) {
        register_rest_route($ns, '/' . $route, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $callback,
            'permission_callback' => 'bb_api_check_auth',
            'args'                => bb_api_pagination_params(),
        ]);
    }
});


// ──────────────────────────────────────────────
// 5. ENDPOINT CALLBACKS
// ──────────────────────────────────────────────

// ── CUSTOMERS ────────────────────────────────

function bb_api_get_customers(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $user_query = new WP_User_Query([
        'number'  => $per_page,
        'paged'   => $page,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ]);

    $total = $user_query->get_total();
    $users = [];

    foreach ($user_query->get_results() as $user) {
        /** @var WP_User $user */
        $meta = get_user_meta($user->ID);

        // Flatten single-value meta
        $flat_meta = [];
        foreach ($meta as $key => $values) {
            $flat_meta[$key] = count($values) === 1 ? $values[0] : $values;
        }

        // Detect wholesale status from common wholesale plugins
        $wholesale_role = '';
        $wholesale_status = false;
        foreach ($user->roles as $role) {
            if (str_contains($role, 'wholesale')) {
                $wholesale_role   = $role;
                $wholesale_status = true;
                break;
            }
        }

        $users[] = [
            'id'               => $user->ID,
            'email'            => $user->user_email,
            'username'         => $user->user_login,
            'display_name'     => $user->display_name,
            'first_name'       => $flat_meta['first_name'] ?? '',
            'last_name'        => $flat_meta['last_name'] ?? '',
            'roles'            => $user->roles,
            'registered'       => $user->user_registered,
            'wholesale_status' => $wholesale_status,
            'wholesale_role'   => $wholesale_role,
            'billing'          => [
                'first_name' => $flat_meta['billing_first_name'] ?? '',
                'last_name'  => $flat_meta['billing_last_name'] ?? '',
                'company'    => $flat_meta['billing_company'] ?? '',
                'address_1'  => $flat_meta['billing_address_1'] ?? '',
                'address_2'  => $flat_meta['billing_address_2'] ?? '',
                'city'       => $flat_meta['billing_city'] ?? '',
                'state'      => $flat_meta['billing_state'] ?? '',
                'postcode'   => $flat_meta['billing_postcode'] ?? '',
                'country'    => $flat_meta['billing_country'] ?? '',
                'email'      => $flat_meta['billing_email'] ?? '',
                'phone'      => $flat_meta['billing_phone'] ?? '',
            ],
            'shipping'         => [
                'first_name' => $flat_meta['shipping_first_name'] ?? '',
                'last_name'  => $flat_meta['shipping_last_name'] ?? '',
                'company'    => $flat_meta['shipping_company'] ?? '',
                'address_1'  => $flat_meta['shipping_address_1'] ?? '',
                'address_2'  => $flat_meta['shipping_address_2'] ?? '',
                'city'       => $flat_meta['shipping_city'] ?? '',
                'state'      => $flat_meta['shipping_state'] ?? '',
                'postcode'   => $flat_meta['shipping_postcode'] ?? '',
                'country'    => $flat_meta['shipping_country'] ?? '',
            ],
            'meta'             => $flat_meta,
        ];
    }

    return bb_api_paginated_response($users, $total, $page, $per_page);
}


// ── ORDERS ───────────────────────────────────

function bb_api_get_orders(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    // Support both HPOS and legacy post-based orders
    if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil')
        && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
        // HPOS path
        $orders_query = new WC_Order_Query([
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'return'  => 'objects',
        ]);
        $orders = $orders_query->get_orders();

        // Total count
        $count_query = new WC_Order_Query([
            'limit'  => 1,
            'page'   => 1,
            'return' => 'ids',
        ]);
        $count_query->get_orders();
        // Fallback: count via wc_get_orders with paginate
        $count_result = wc_get_orders([
            'limit'    => 1,
            'page'     => 1,
            'paginate' => true,
        ]);
        $total = $count_result->total;
    } else {
        // Legacy post-based orders
        $count_result = wc_get_orders([
            'limit'    => 1,
            'page'     => 1,
            'paginate' => true,
        ]);
        $total = $count_result->total;

        $orders = wc_get_orders([
            'limit'   => $per_page,
            'page'    => $page,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ]);
    }

    $items = [];
    foreach ($orders as $order) {
        /** @var WC_Order $order */

        // Line items
        $line_items = [];
        foreach ($order->get_items() as $item_id => $item) {
            /** @var WC_Order_Item_Product $item */
            $line_items[] = [
                'id'           => $item_id,
                'product_id'   => $item->get_product_id(),
                'variation_id' => $item->get_variation_id(),
                'name'         => $item->get_name(),
                'quantity'     => $item->get_quantity(),
                'subtotal'     => $item->get_subtotal(),
                'total'        => $item->get_total(),
                'tax'          => $item->get_total_tax(),
                'sku'          => $item->get_product() ? $item->get_product()->get_sku() : '',
                'meta_data'    => $item->get_meta_data(),
            ];
        }

        // Fee lines
        $fee_lines = [];
        foreach ($order->get_fees() as $fee_id => $fee) {
            $fee_lines[] = [
                'id'    => $fee_id,
                'name'  => $fee->get_name(),
                'total' => $fee->get_total(),
                'tax'   => $fee->get_total_tax(),
            ];
        }

        // Shipping lines
        $shipping_lines = [];
        foreach ($order->get_shipping_methods() as $ship_id => $ship) {
            $shipping_lines[] = [
                'id'        => $ship_id,
                'method_id' => $ship->get_method_id(),
                'name'      => $ship->get_name(),
                'total'     => $ship->get_total(),
            ];
        }

        // Coupon lines
        $coupon_lines = [];
        foreach ($order->get_coupons() as $coupon_id => $coupon) {
            $coupon_lines[] = [
                'id'       => $coupon_id,
                'code'     => $coupon->get_code(),
                'discount' => $coupon->get_discount(),
            ];
        }

        // Refunds
        $refunds = [];
        foreach ($order->get_refunds() as $refund) {
            $refunds[] = [
                'id'     => $refund->get_id(),
                'amount' => $refund->get_amount(),
                'reason' => $refund->get_reason(),
                'date'   => $refund->get_date_created()?->format('c'),
            ];
        }

        $items[] = [
            'id'                  => $order->get_id(),
            'legacy_order_number' => $order->get_meta('_order_number') ?: $order->get_order_number(),
            'status'              => $order->get_status(),
            'currency'            => $order->get_currency(),
            'date_created'        => $order->get_date_created()?->format('c'),
            'date_modified'       => $order->get_date_modified()?->format('c'),
            'date_completed'      => $order->get_date_completed()?->format('c'),
            'date_paid'           => $order->get_date_paid()?->format('c'),
            'customer_id'         => $order->get_customer_id(),
            'customer_email'      => $order->get_billing_email(),
            'billing'             => [
                'first_name' => $order->get_billing_first_name(),
                'last_name'  => $order->get_billing_last_name(),
                'company'    => $order->get_billing_company(),
                'address_1'  => $order->get_billing_address_1(),
                'address_2'  => $order->get_billing_address_2(),
                'city'       => $order->get_billing_city(),
                'state'      => $order->get_billing_state(),
                'postcode'   => $order->get_billing_postcode(),
                'country'    => $order->get_billing_country(),
                'email'      => $order->get_billing_email(),
                'phone'      => $order->get_billing_phone(),
            ],
            'shipping'            => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name'  => $order->get_shipping_last_name(),
                'company'    => $order->get_shipping_company(),
                'address_1'  => $order->get_shipping_address_1(),
                'address_2'  => $order->get_shipping_address_2(),
                'city'       => $order->get_shipping_city(),
                'state'      => $order->get_shipping_state(),
                'postcode'   => $order->get_shipping_postcode(),
                'country'    => $order->get_shipping_country(),
            ],
            'payment_method'       => $order->get_payment_method(),
            'payment_method_title' => $order->get_payment_method_title(),
            'transaction_id'       => $order->get_transaction_id(),
            'subtotal'             => $order->get_subtotal(),
            'total'                => $order->get_total(),
            'total_tax'            => $order->get_total_tax(),
            'shipping_total'       => $order->get_shipping_total(),
            'discount_total'       => $order->get_discount_total(),
            'line_items'           => $line_items,
            'fee_lines'            => $fee_lines,
            'shipping_lines'       => $shipping_lines,
            'coupon_lines'         => $coupon_lines,
            'refunds'              => $refunds,
            'customer_note'        => $order->get_customer_note(),
            'meta_data'            => $order->get_meta_data(),
        ];
    }

    return bb_api_paginated_response($items, $total, $page, $per_page);
}


// ── PRODUCTS ─────────────────────────────────

function bb_api_get_products(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $query = new WP_Query([
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $total = $query->found_posts;
    $items = [];

    foreach ($query->posts as $post) {
        $product = wc_get_product($post->ID);
        if (!$product) continue;

        // Featured image
        $image_id  = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

        // Gallery images
        $gallery = array_map(function ($id) {
            return [
                'id'  => $id,
                'url' => wp_get_attachment_url($id),
            ];
        }, $product->get_gallery_image_ids());

        // Categories
        $categories = array_map(function ($term) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, wp_get_post_terms($post->ID, 'product_cat'));

        // Tags
        $tags = array_map(function ($term) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, wp_get_post_terms($post->ID, 'product_tag'));

        // Variations (for variable products)
        $variations = [];
        if ($product->is_type('variable')) {
            /** @var WC_Product_Variable $product */
            foreach ($product->get_available_variations() as $v) {
                $variation = wc_get_product($v['variation_id']);
                if (!$variation) continue;

                $variations[] = [
                    'id'             => $v['variation_id'],
                    'sku'            => $variation->get_sku(),
                    'price'          => $variation->get_price(),
                    'regular_price'  => $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price(),
                    'stock_status'   => $variation->get_stock_status(),
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'weight'         => $variation->get_weight(),
                    'manage_stock'   => $variation->get_manage_stock(),
                    'attributes'     => $v['attributes'],
                    'image'          => $v['image'] ?? null,
                ];
            }
        }

        // Reviews
        $reviews    = [];
        $comments   = get_comments([
            'post_id' => $post->ID,
            'type'    => 'review',
            'status'  => 'approve',
        ]);
        foreach ($comments as $comment) {
            $reviews[] = [
                'id'       => $comment->comment_ID,
                'author'   => $comment->comment_author,
                'email'    => $comment->comment_author_email,
                'rating'   => (int) get_comment_meta($comment->comment_ID, 'rating', true),
                'content'  => $comment->comment_content,
                'date'     => $comment->comment_date,
            ];
        }

        // Attributes
        $attributes = [];
        foreach ($product->get_attributes() as $attr_key => $attr) {
            if ($attr instanceof WC_Product_Attribute) {
                $attributes[] = [
                    'name'      => $attr->get_name(),
                    'options'   => $attr->get_options(),
                    'visible'   => $attr->get_visible(),
                    'variation' => $attr->get_variation(),
                ];
            }
        }

        $items[] = [
            'id'               => $product->get_id(),
            'name'             => $product->get_name(),
            'slug'             => $product->get_slug(),
            'type'             => $product->get_type(),
            'status'           => $product->get_status(),
            'sku'              => $product->get_sku(),
            'description'      => $product->get_description(),
            'short_description'=> $product->get_short_description(),
            'price'            => $product->get_price(),
            'regular_price'    => $product->get_regular_price(),
            'sale_price'       => $product->get_sale_price(),
            'stock_status'     => $product->get_stock_status(),
            'stock_quantity'   => $product->get_stock_quantity(),
            'manage_stock'     => $product->get_manage_stock(),
            'weight'           => $product->get_weight(),
            'length'           => $product->get_length(),
            'width'            => $product->get_width(),
            'height'           => $product->get_height(),
            'featured'         => $product->get_featured(),
            'virtual'          => $product->is_virtual(),
            'downloadable'     => $product->is_downloadable(),
            'tax_status'       => $product->get_tax_status(),
            'tax_class'        => $product->get_tax_class(),
            'date_created'     => $product->get_date_created()?->format('c'),
            'date_modified'    => $product->get_date_modified()?->format('c'),
            'image'            => ['id' => $image_id, 'url' => $image_url],
            'gallery'          => $gallery,
            'categories'       => $categories,
            'tags'             => $tags,
            'attributes'       => $attributes,
            'variations'       => $variations,
            'reviews'          => $reviews,
            'meta_data'        => get_post_meta($post->ID),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response($items, $total, $page, $per_page);
}


// ── HERBS ────────────────────────────────────

function bb_api_get_herbs(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    // Herbs may be stored as a custom post type or as pages — try both
    $post_types = ['herb', 'herbs'];
    $registered = get_post_types([], 'names');

    // Find the actual herb post type, fallback to page
    $herb_type = 'page';
    foreach ($post_types as $candidate) {
        if (in_array($candidate, $registered, true)) {
            $herb_type = $candidate;
            break;
        }
    }

    // Slugs that should never appear in herb results (site utility pages, etc.)
    $excluded_slugs = [
        'shop', 'cart', 'checkout', 'contact', 'reviews', 'thankyou',
        'disclaimer', 'admin-panel', 'bear-blog', 'shopping-cart',
        'ingredients', 'where-did-it-come-from',
    ];

    // If using pages, require the parent page whose slug is exactly "herbs".
    // If that page cannot be found, return nothing rather than dumping all pages.
    $parent_id = 0;
    if ($herb_type === 'page') {
        $herbs_page = get_page_by_path('herbs');
        if ($herbs_page && $herbs_page->post_name === 'herbs') {
            $parent_id = $herbs_page->ID;
        }

        if ($parent_id === 0) {
            return bb_api_paginated_response([], 0, $page, $per_page);
        }
    }

    // Resolve excluded slugs to post IDs so WP_Query can filter them out at query time.
    // This keeps pagination counts accurate.
    $excluded_ids = [];
    if (!empty($excluded_slugs)) {
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($excluded_slugs), '%s'));
        $excluded_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name IN ($placeholders) AND post_type = %s",
                ...[...$excluded_slugs, $herb_type]
            )
        );
        $excluded_ids = array_map('intval', $excluded_ids);
    }

    $query_args = [
        'post_type'      => $herb_type,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];

    if ($parent_id > 0) {
        $query_args['post_parent'] = $parent_id;
    }

    if (!empty($excluded_ids)) {
        $query_args['post__not_in'] = $excluded_ids;
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $items = [];

    foreach ($query->posts as $post) {
        $all_meta    = get_post_meta($post->ID);
        $flat_meta   = [];
        foreach ($all_meta as $key => $values) {
            $flat_meta[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        $featured_id  = get_post_thumbnail_id($post->ID);
        $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';

        // ACF fields if available
        $acf_fields = [];
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post->ID) ?: [];
        }

        // Taxonomies
        $taxonomies = [];
        $post_taxonomies = get_object_taxonomies($herb_type);
        foreach ($post_taxonomies as $tax) {
            $terms = wp_get_post_terms($post->ID, $tax);
            if (!is_wp_error($terms) && !empty($terms)) {
                $taxonomies[$tax] = array_map(function ($t) {
                    return ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug];
                }, $terms);
            }
        }

        $items[] = [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => $post->post_status,
            'content'         => $post->post_content,
            'excerpt'         => $post->post_excerpt,
            'date_created'    => $post->post_date,
            'date_modified'   => $post->post_modified,
            'featured_image'  => ['id' => $featured_id, 'url' => $featured_url],
            'acf_fields'      => $acf_fields,
            'taxonomies'      => $taxonomies,
            'meta'            => $flat_meta,
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response($items, $total, $page, $per_page);
}


// ── POSTS ────────────────────────────────────

function bb_api_get_posts(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $query = new WP_Query([
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $total = $query->found_posts;
    $items = [];

    foreach ($query->posts as $post) {
        $featured_id  = get_post_thumbnail_id($post->ID);
        $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';

        // Categories
        $categories = array_map(function ($term) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, wp_get_post_terms($post->ID, 'category'));

        // Tags
        $tags = array_map(function ($term) {
            return [
                'id'   => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }, wp_get_post_terms($post->ID, 'post_tag'));

        // Inline images from content
        $content_images = [];
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $post->post_content, $matches)) {
            $content_images = array_values(array_unique($matches[1]));
        }

        $items[] = [
            'id'              => $post->ID,
            'title'           => $post->post_title,
            'slug'            => $post->post_name,
            'status'          => $post->post_status,
            'content'         => $post->post_content,
            'excerpt'         => $post->post_excerpt,
            'author_id'       => (int) $post->post_author,
            'author_name'     => get_the_author_meta('display_name', $post->post_author),
            'date_created'    => $post->post_date,
            'date_modified'   => $post->post_modified,
            'featured_image'  => ['id' => $featured_id, 'url' => $featured_url],
            'categories'      => $categories,
            'tags'            => $tags,
            'content_images'  => $content_images,
            'comment_status'  => $post->comment_status,
            'comment_count'   => (int) $post->comment_count,
            'meta'            => get_post_meta($post->ID),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response($items, $total, $page, $per_page);
}


// ── FAQS ─────────────────────────────────────

function bb_api_get_faqs(WP_REST_Request $request): WP_REST_Response {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    // Auto-detect registered FAQ post type
    $candidates  = ['faq', 'faqs'];
    $registered  = get_post_types([], 'names');
    $faq_type    = null;

    foreach ($candidates as $candidate) {
        if (in_array($candidate, $registered, true)) {
            $faq_type = $candidate;
            break;
        }
    }

    if ($faq_type === null) {
        return new WP_REST_Response([
            'code'    => 'bb_api_no_faq_type',
            'message' => 'No FAQ custom post type (faq or faqs) is registered on this site.',
        ], 404);
    }

    $query = new WP_Query([
        'post_type'      => $faq_type,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ]);

    $total = $query->found_posts;
    $items = [];

    // Discover FAQ-related taxonomies (category-like) for this post type
    $faq_taxonomies = get_object_taxonomies($faq_type, 'objects');
    $cat_taxonomies = [];
    foreach ($faq_taxonomies as $tax_obj) {
        if ($tax_obj->hierarchical) {
            $cat_taxonomies[] = $tax_obj->name;
        }
    }
    // Fallback: also check common naming conventions
    foreach (['faq_category', 'faq_cat', 'faqs_category'] as $guess) {
        if (taxonomy_exists($guess) && !in_array($guess, $cat_taxonomies, true)) {
            $cat_taxonomies[] = $guess;
        }
    }

    foreach ($query->posts as $post) {
        // Categories from all discovered FAQ taxonomies
        $categories = [];
        foreach ($cat_taxonomies as $tax_name) {
            $terms = wp_get_post_terms($post->ID, $tax_name);
            if (!is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $categories[] = [
                        'term_id'  => $term->term_id,
                        'name'     => $term->name,
                        'slug'     => $term->slug,
                        'parent'   => $term->parent,
                        'taxonomy' => $tax_name,
                    ];
                }
            }
        }

        // Featured image
        $featured_id  = get_post_thumbnail_id($post->ID);
        $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';

        // ACF fields
        $acf_fields = [];
        if (function_exists('get_fields')) {
            $acf_fields = get_fields($post->ID) ?: [];
        }

        // All post meta
        $all_meta  = get_post_meta($post->ID);
        $flat_meta = [];
        foreach ($all_meta as $key => $values) {
            $flat_meta[$key] = count($values) === 1
                ? maybe_unserialize($values[0])
                : array_map('maybe_unserialize', $values);
        }

        $items[] = [
            'id'             => $post->ID,
            'question'       => $post->post_title,
            'answer'         => apply_filters('the_content', $post->post_content),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'categories'     => $categories,
            'meta'           => $flat_meta,
            'acf'            => $acf_fields,
            'featured_image' => $featured_url,
            'published_at'   => (new DateTimeImmutable($post->post_date))->format('c'),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response($items, $total, $page, $per_page);
}
