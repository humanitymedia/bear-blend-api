<?php
/**
 * Plugin Name: Bear Blend API
 * Plugin URI:  https://bearblend.com
 * Description: Read-only REST API endpoints for migrating and syncing Bear Blend site data to Laravel. Exposes customers, orders, products, herbs, posts, pages, menus, media, terms, coupons, counts, and AIOSEO metadata — all paginated (where applicable), protected by a Bearer token, and supporting incremental sync via ?modified_after.
 * Version:     1.1.1
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
        <table class="widefat fixed striped" style="max-width:760px">
            <thead><tr><th>Method</th><th>Endpoint</th><th>Description</th></tr></thead>
            <tbody>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/counts</code></td><td>Record counts per resource type (sync completeness check)</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/customers</code></td><td>WP users with meta &amp; roles</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/orders</code></td><td>WooCommerce orders with line items (<code>?fields=minimal</code> for cheap mode)</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/products</code></td><td>Products, variations, categories, reviews, AIOSEO</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/herbs</code></td><td>Herb content pages with custom fields + AIOSEO</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/posts</code></td><td>Blog posts with images, categories, AIOSEO</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/pages</code></td><td>Static pages (About, Contact, etc.) with AIOSEO</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/faqs</code></td><td>FAQ entries with categories, ACF fields, AIOSEO</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/menus</code></td><td>All WP nav menus + menu locations (single request)</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/media</code></td><td>Media library with alt, caption, dimensions, filesize</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/terms</code></td><td>Taxonomy terms with descriptions &amp; AIOSEO (<code>?taxonomy=</code> required)</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/coupons</code></td><td>WooCommerce coupons with restrictions &amp; usage counts</td></tr>
                <tr><td>GET</td><td><code>/wp-json/bb/v1/aioseo/{post_type}/{id}</code></td><td>Direct AIOSEO row lookup for a single post (optional helper)</td></tr>
            </tbody>
        </table>
        <p>All list endpoints accept <code>?page=1&amp;per_page=50</code> and <code>?modified_after=&lt;ISO 8601 UTC&gt;</code> for incremental sync. Paginated responses return <code>X-WP-Total</code>, <code>X-WP-TotalPages</code>, <code>Last-Modified</code>, and <code>ETag</code> headers; send <code>If-None-Match</code> to get a <code>304 Not Modified</code>.</p>
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

function bb_api_paginated_response(
    array $items,
    int $total,
    int $page,
    int $per_page,
    ?WP_REST_Request $request = null,
    ?string $last_modified = null
): WP_REST_Response {
    $response = new WP_REST_Response($items);
    $response->header('X-WP-Total', $total);
    $response->header('X-WP-TotalPages', $per_page > 0 ? (int) ceil($total / $per_page) : 0);
    $response->header('X-WP-Page', $page);

    if ($request !== null && !empty($last_modified)) {
        $ts = strtotime($last_modified);
        if ($ts !== false) {
            $etag = md5($last_modified . '|' . $page . '|' . $per_page . '|' . $total);
            $response->header('Last-Modified', gmdate('D, d M Y H:i:s', $ts) . ' GMT');
            $response->header('ETag', '"' . $etag . '"');

            $client_etag = $request->get_header('If-None-Match');
            if ($client_etag !== null && trim($client_etag, " \t\"") === $etag) {
                $response->set_data(null);
                $response->set_status(304);
            }
        }
    }

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

/**
 * Pagination + modified_after schema. Used by every list endpoint.
 */
function bb_api_list_params(): array {
    return array_merge(bb_api_pagination_params(), [
        'modified_after' => [
            'description' => 'ISO 8601 datetime (interpreted as UTC). Returns only records modified strictly after this time.',
            'type'        => 'string',
        ],
    ]);
}


// ──────────────────────────────────────────────
// 3b. INCREMENTAL SYNC + AIOSEO HELPERS
// ──────────────────────────────────────────────

/**
 * Parse ?modified_after from the request. Returns a DateTimeImmutable (UTC), null if absent,
 * or WP_Error on malformed input.
 */
function bb_api_parse_modified_after(WP_REST_Request $request): DateTimeImmutable|WP_Error|null {
    $raw = $request->get_param('modified_after');
    if ($raw === null || $raw === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable((string) $raw, new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        return new WP_Error(
            'bb_api_invalid_modified_after',
            'modified_after must be an ISO 8601 datetime string. ' . $e->getMessage(),
            ['status' => 400]
        );
    }

    return $dt->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Bulk-fetch AIOSEO rows for a set of post IDs, returned as [post_id => formatted_row].
 * Null-safe: returns [] if AIOSEO is deactivated or the table doesn't exist.
 */
function bb_api_fetch_aioseo_for_posts(array $post_ids): array {
    $post_ids = array_values(array_unique(array_filter(array_map('intval', $post_ids))));
    if (empty($post_ids)) {
        return [];
    }

    global $wpdb;
    $table = $wpdb->prefix . 'aioseo_posts';

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table WHERE post_id IN ($placeholders)", ...$post_ids),
        ARRAY_A
    );

    $map = [];
    foreach ($rows ?: [] as $row) {
        $map[(int) $row['post_id']] = bb_api_format_aioseo_row($row);
    }
    return $map;
}

/**
 * Shape a raw wp_aioseo_posts row into the public response format. JSON columns are decoded.
 */
function bb_api_format_aioseo_row(array $row): array {
    $decode = function ($val) {
        if (!is_string($val) || $val === '') {
            return null;
        }
        $decoded = json_decode($val, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $val;
    };

    $keyphrases      = $decode($row['keyphrases'] ?? null);
    $focus_keyphrase = '';
    if (is_array($keyphrases) && isset($keyphrases['focus']['keyphrase'])) {
        $focus_keyphrase = (string) $keyphrases['focus']['keyphrase'];
    }

    return [
        'post_id'             => (int) ($row['post_id'] ?? 0),
        'post_type'           => $row['post_type'] ?? null,
        'title'               => $row['title'] ?? null,
        'description'         => $row['description'] ?? null,
        'canonical_url'       => $row['canonical_url'] ?? null,
        'og_title'            => $row['og_title'] ?? null,
        'og_description'      => $row['og_description'] ?? null,
        'og_image_url'        => $row['og_image_url'] ?? null,
        'twitter_title'       => $row['twitter_title'] ?? null,
        'twitter_description' => $row['twitter_description'] ?? null,
        'twitter_image_url'   => $row['twitter_image_url'] ?? null,
        'robots_noindex'      => (int) ($row['robots_noindex'] ?? 0),
        'robots_nofollow'     => (int) ($row['robots_nofollow'] ?? 0),
        'robots_default'      => (int) ($row['robots_default'] ?? 1),
        'schema_type'         => $row['schema_type'] ?? null,
        'schema_type_options' => $decode($row['schema_type_options'] ?? null),
        'keyphrases'          => $keyphrases,
        'focus_keyphrase'     => $focus_keyphrase,
    ];
}

/**
 * Extract the max value of $key across a list of items. Returns null if none found.
 * Used to compute Last-Modified / ETag values.
 */
function bb_api_max_modified(array $items, string $key): ?string {
    $max = '';
    foreach ($items as $item) {
        $v = $item[$key] ?? null;
        if (is_string($v) && $v > $max) {
            $max = $v;
        }
    }
    return $max === '' ? null : $max;
}

/**
 * Build a WP_Query date_query clause for post_modified_gmt > $after.
 */
function bb_api_date_query_post_modified(DateTimeImmutable $after): array {
    return [
        [
            'column'    => 'post_modified_gmt',
            'after'     => $after->format('Y-m-d H:i:s'),
            'inclusive' => false,
        ],
    ];
}

/**
 * Render post content into consumable HTML. Handles Gutenberg blocks, Divi shortcodes,
 * and the standard the_content filter chain (wpautop, wptexturize, do_shortcode, etc.).
 *
 * Divi only registers its shortcodes on the 'wp' action during normal page loads; in
 * REST context that hook doesn't fire, so et_pb_* shortcodes leak through as raw text
 * unless we force the theme's setup routine.
 */
function bb_api_render_post_content(?string $raw): string {
    if ($raw === null || $raw === '') return '';

    static $divi_initialized = false;
    if (!$divi_initialized) {
        $divi_initialized = true;
        if (function_exists('et_setup_theme')) {
            et_setup_theme();
        }
        if (function_exists('et_builder_init_global_settings')) {
            et_builder_init_global_settings();
        }
        if (function_exists('et_builder_add_main_elements')) {
            et_builder_add_main_elements();
        }
    }

    $content = do_blocks($raw);
    $content = apply_filters('the_content', $content);
    return str_replace(']]>', ']]&gt;', $content);
}


// ──────────────────────────────────────────────
// 4. REGISTER ROUTES
// ──────────────────────────────────────────────

add_action('rest_api_init', function () {

    $ns = 'bb/v1';

    // Simple list endpoints that take only pagination + modified_after.
    $list_endpoints = [
        'customers' => 'bb_api_get_customers',
        'products'  => 'bb_api_get_products',
        'herbs'     => 'bb_api_get_herbs',
        'posts'     => 'bb_api_get_posts',
        'faqs'      => 'bb_api_get_faqs',
    ];

    foreach ($list_endpoints as $route => $callback) {
        register_rest_route($ns, '/' . $route, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $callback,
            'permission_callback' => 'bb_api_check_auth',
            'args'                => bb_api_list_params(),
        ]);
    }

    // Orders — adds ?fields=minimal.
    register_rest_route($ns, '/orders', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_orders',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => array_merge(bb_api_list_params(), [
            'fields' => [
                'description' => 'Pass "minimal" to return only id/status/date_modified/total/customer_id.',
                'type'        => 'string',
                'enum'        => ['minimal'],
            ],
        ]),
    ]);

    // Counts — no pagination, no modified_after.
    register_rest_route($ns, '/counts', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_counts',
        'permission_callback' => 'bb_api_check_auth',
    ]);

    // Pages — adds include_drafts + exclude_slugs.
    register_rest_route($ns, '/pages', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_pages',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => array_merge(bb_api_list_params(), [
            'include_drafts' => [
                'description' => 'If true, include draft/pending/private/future pages alongside publish.',
                'type'        => 'boolean',
                'default'     => false,
            ],
            'exclude_slugs' => [
                'description' => 'Comma-separated list of page slugs to exclude (adds to built-in utility exclusions).',
                'type'        => 'string',
            ],
        ]),
    ]);

    // Menus — unpaginated.
    register_rest_route($ns, '/menus', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_menus',
        'permission_callback' => 'bb_api_check_auth',
    ]);

    // Media — adds mime_type.
    register_rest_route($ns, '/media', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_media',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => array_merge(bb_api_list_params(), [
            'mime_type' => [
                'description' => 'Filter by MIME type, e.g. "image/jpeg".',
                'type'        => 'string',
            ],
        ]),
    ]);

    // Terms — taxonomy required.
    register_rest_route($ns, '/terms', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_terms',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => array_merge(bb_api_list_params(), [
            'taxonomy' => [
                'description' => 'Taxonomy slug, e.g. "product_cat", "product_tag", "category", "post_tag".',
                'type'        => 'string',
                'required'    => true,
            ],
        ]),
    ]);

    // Coupons — adds status.
    register_rest_route($ns, '/coupons', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_coupons',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => array_merge(bb_api_list_params(), [
            'status' => [
                'description' => 'Post status filter. Defaults to "publish".',
                'type'        => 'string',
                'default'     => 'publish',
            ],
        ]),
    ]);

    // AIOSEO direct lookup for a single post.
    register_rest_route($ns, '/aioseo/(?P<post_type>[a-z0-9_-]+)/(?P<id>\d+)', [
        'methods'             => WP_REST_Server::READABLE,
        'callback'            => 'bb_api_get_aioseo_for_post',
        'permission_callback' => 'bb_api_check_auth',
        'args'                => [
            'post_type' => ['type' => 'string', 'required' => true],
            'id'        => ['type' => 'integer', 'required' => true, 'minimum' => 1],
        ],
    ]);
});


// ──────────────────────────────────────────────
// 5. ENDPOINT CALLBACKS
// ──────────────────────────────────────────────

// ── CUSTOMERS ────────────────────────────────

function bb_api_get_customers(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'number'  => $per_page,
        'paged'   => $page,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ];

    // WP_User_Query's date_query filters against user_registered. Per spec, users have no
    // native "last modified" timestamp; a later release may backfill _last_synced_at.
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = [
            [
                'column'    => 'user_registered',
                'after'     => $modified_after->format('Y-m-d H:i:s'),
                'inclusive' => false,
            ],
        ];
    }

    $user_query = new WP_User_Query($query_args);

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

    return bb_api_paginated_response(
        $users,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($users, 'registered')
    );
}


// ── ORDERS ───────────────────────────────────

function bb_api_get_orders(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    if ($request->get_param('fields') === 'minimal') {
        return bb_api_get_orders_minimal($request, $page, $per_page, $modified_after);
    }

    // Full-order responses eagerly load line items, fees, shipping, coupons, refunds, and
    // meta — cap at 50 to avoid OOM.
    $per_page = min($per_page, 50);

    $count_args = [
        'limit'    => 1,
        'page'     => 1,
        'paginate' => true,
        'orderby'  => 'ID',
        'order'    => 'ASC',
    ];
    $list_args = [
        'limit'   => $per_page,
        'page'    => $page,
        'orderby' => 'ID',
        'order'   => 'ASC',
    ];

    if ($modified_after instanceof DateTimeImmutable) {
        // wc_get_orders supports a `>` / `>=` prefix on date_modified. Passing '>' + ISO
        // string mirrors post_modified_gmt > modified_after across HPOS and legacy stores.
        $filter = '>' . $modified_after->format('Y-m-d\TH:i:s');
        $count_args['date_modified'] = $filter;
        $list_args['date_modified']  = $filter;
    }

    $count_result = wc_get_orders($count_args);
    $total        = (int) $count_result->total;

    $orders = wc_get_orders($list_args);

    $items = [];
    foreach ($orders as $order) {
        /** @var WC_Order $order */

        try {
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
        } catch (Throwable $e) {
            // A single malformed order should not crash the entire page.
            // Record the failure inline so the caller knows which ID to investigate.
            $items[] = [
                'id'    => method_exists($order, 'get_id') ? $order->get_id() : null,
                'error' => $e->getMessage(),
            ];
            error_log('bb_api_get_orders: skipped order ' . (method_exists($order, 'get_id') ? $order->get_id() : '?') . ' — ' . $e->getMessage());
        }

        // Release the order object immediately to keep per-page memory flat.
        unset($order, $line_items, $fee_lines, $shipping_lines, $coupon_lines, $refunds);
    }

    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}

/**
 * Fast path for ?fields=minimal. Queries the HPOS table (or wp_posts + postmeta fallback)
 * directly, skipping WC_Order hydration entirely. Target is 10x+ faster than full mode.
 */
function bb_api_get_orders_minimal(
    WP_REST_Request $request,
    int $page,
    int $per_page,
    DateTimeImmutable|WP_Error|null $modified_after
): WP_REST_Response {
    global $wpdb;

    $offset = ($page - 1) * $per_page;
    $hpos   = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

    $tz_site = wp_timezone();

    if ($hpos) {
        $table = $wpdb->prefix . 'wc_orders';

        $where = ['type = %s'];
        $args  = ['shop_order'];
        if ($modified_after instanceof DateTimeImmutable) {
            $where[] = 'date_updated_gmt > %s';
            $args[]  = $modified_after->format('Y-m-d H:i:s');
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where_sql}",
            ...$args
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, date_updated_gmt, total_amount, customer_id
             FROM {$table}
             {$where_sql}
             ORDER BY id ASC
             LIMIT %d OFFSET %d",
            ...[...$args, $per_page, $offset]
        ), ARRAY_A);

        $items = [];
        foreach ($rows ?: [] as $row) {
            $dt_modified = null;
            if (!empty($row['date_updated_gmt']) && $row['date_updated_gmt'] !== '0000-00-00 00:00:00') {
                $dt_modified = (new DateTimeImmutable($row['date_updated_gmt'], new DateTimeZone('UTC')))
                    ->setTimezone($tz_site)
                    ->format('c');
            }
            $items[] = [
                'id'            => (int) $row['id'],
                // HPOS stores statuses with or without the wc- prefix depending on version.
                'status'        => preg_replace('/^wc-/', '', (string) $row['status']),
                'date_modified' => $dt_modified,
                'total'         => $row['total_amount'] !== null ? (string) $row['total_amount'] : '0',
                'customer_id'   => (int) $row['customer_id'],
            ];
        }
    } else {
        // Legacy wp_posts storage. A single LEFT JOIN per meta key keeps this index-friendly.
        $where = ["p.post_type = 'shop_order'", "p.post_status NOT IN ('trash','auto-draft')"];
        $args  = [];
        if ($modified_after instanceof DateTimeImmutable) {
            $where[] = 'p.post_modified_gmt > %s';
            $args[]  = $modified_after->format('Y-m-d H:i:s');
        }
        $where_sql = 'WHERE ' . implode(' AND ', $where);

        $total_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p {$where_sql}";
        $total     = (int) (empty($args) ? $wpdb->get_var($total_sql) : $wpdb->get_var($wpdb->prepare($total_sql, ...$args)));

        $list_sql = "SELECT p.ID as id, p.post_status as status, p.post_modified_gmt as date_modified_gmt,
                            pm_total.meta_value as total, pm_cust.meta_value as customer_id
                     FROM {$wpdb->posts} p
                     LEFT JOIN {$wpdb->postmeta} pm_total ON pm_total.post_id = p.ID AND pm_total.meta_key = '_order_total'
                     LEFT JOIN {$wpdb->postmeta} pm_cust  ON pm_cust.post_id  = p.ID AND pm_cust.meta_key  = '_customer_user'
                     {$where_sql}
                     ORDER BY p.ID ASC
                     LIMIT %d OFFSET %d";
        $list_args = [...$args, $per_page, $offset];
        $rows = $wpdb->get_results($wpdb->prepare($list_sql, ...$list_args), ARRAY_A);

        $items = [];
        foreach ($rows ?: [] as $row) {
            $dt_modified = null;
            if (!empty($row['date_modified_gmt']) && $row['date_modified_gmt'] !== '0000-00-00 00:00:00') {
                $dt_modified = (new DateTimeImmutable($row['date_modified_gmt'], new DateTimeZone('UTC')))
                    ->setTimezone($tz_site)
                    ->format('c');
            }
            $items[] = [
                'id'            => (int) $row['id'],
                'status'        => preg_replace('/^wc-/', '', (string) $row['status']),
                'date_modified' => $dt_modified,
                'total'         => $row['total'] !== null ? (string) $row['total'] : '0',
                'customer_id'   => (int) $row['customer_id'],
            ];
        }
    }

    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── PRODUCTS ─────────────────────────────────

function bb_api_get_products(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'post_type'      => 'product',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);

    $total = $query->found_posts;
    $items = [];

    $aioseo_map = bb_api_fetch_aioseo_for_posts(wp_list_pluck($query->posts, 'ID'));

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
            'aioseo'           => $aioseo_map[$post->ID] ?? null,
            'meta_data'        => get_post_meta($post->ID),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── HERBS ────────────────────────────────────

function bb_api_get_herbs(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

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
        // get_page_by_path() only matches published pages with post_parent=0 by default.
        // On live, the herbs page was either nested, non-published, or the cache was cold —
        // so we fall back to a direct wpdb lookup that tolerates any non-trash status and
        // ranks 'publish' first when multiple candidates exist.
        $herbs_page = get_page_by_path('herbs');
        if ($herbs_page && $herbs_page->post_name === 'herbs') {
            $parent_id = $herbs_page->ID;
        }

        if ($parent_id === 0) {
            global $wpdb;
            $parent_id = (int) $wpdb->get_var(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_name = 'herbs'
                   AND post_type = 'page'
                   AND post_status NOT IN ('trash','auto-draft')
                 ORDER BY (post_status = 'publish') DESC, ID ASC
                 LIMIT 1"
            );
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

    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $items = [];

    $aioseo_map = bb_api_fetch_aioseo_for_posts(wp_list_pluck($query->posts, 'ID'));

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
            'content'         => bb_api_render_post_content($post->post_content),
            'excerpt'         => $post->post_excerpt,
            'date_created'    => $post->post_date,
            'date_modified'   => $post->post_modified,
            'featured_image'  => ['id' => $featured_id, 'url' => $featured_url],
            'acf_fields'      => $acf_fields,
            'taxonomies'      => $taxonomies,
            'aioseo'          => $aioseo_map[$post->ID] ?? null,
            'meta'            => $flat_meta,
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── POSTS ────────────────────────────────────

function bb_api_get_posts(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'post_type'      => 'post',
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);

    $total = $query->found_posts;
    $items = [];

    $aioseo_map = bb_api_fetch_aioseo_for_posts(wp_list_pluck($query->posts, 'ID'));

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
            'content'         => bb_api_render_post_content($post->post_content),
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
            'aioseo'          => $aioseo_map[$post->ID] ?? null,
            'meta'            => get_post_meta($post->ID),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── FAQS ─────────────────────────────────────

function bb_api_get_faqs(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

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

    $query_args = [
        'post_type'      => $faq_type,
        'post_status'    => 'any',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);

    $total = $query->found_posts;
    $items = [];

    $aioseo_map = bb_api_fetch_aioseo_for_posts(wp_list_pluck($query->posts, 'ID'));

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
            'answer'         => bb_api_render_post_content($post->post_content),
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'categories'     => $categories,
            'meta'           => $flat_meta,
            'acf'            => $acf_fields,
            'featured_image' => $featured_url,
            'aioseo'         => $aioseo_map[$post->ID] ?? null,
            'published_at'   => (new DateTimeImmutable($post->post_date))->format('c'),
            'date_modified'  => $post->post_modified,
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── COUNTS ───────────────────────────────────

function bb_api_get_counts(WP_REST_Request $request): WP_REST_Response {
    // Sum all non-trash/auto-draft statuses. Inherit is only meaningful for attachments —
    // wp_count_posts('attachment')->inherit is the published media count, included below.
    $sum_statuses = function (string $post_type, array $statuses): int {
        $counts = wp_count_posts($post_type);
        $total  = 0;
        foreach ($statuses as $s) {
            $total += (int) ($counts->$s ?? 0);
        }
        return $total;
    };

    $active_statuses = ['publish', 'draft', 'private', 'pending', 'future'];

    // Orders via wc_get_orders paginate — works for HPOS + legacy.
    $orders_total = 0;
    if (function_exists('wc_get_orders')) {
        $orders_total = (int) wc_get_orders([
            'limit'    => 1,
            'page'     => 1,
            'paginate' => true,
        ])->total;
    }

    $registered = get_post_types([], 'names');
    $faq_type   = in_array('faq', $registered, true) ? 'faq' : (in_array('faqs', $registered, true) ? 'faqs' : null);
    $herb_type  = in_array('herb', $registered, true) ? 'herb' : (in_array('herbs', $registered, true) ? 'herbs' : null);

    $users_total = (int) (count_users()['total_users'] ?? 0);

    $reviews_total = (int) get_comments([
        'type'   => 'review',
        'status' => 'approve',
        'count'  => true,
    ]);

    $subscriptions_total = in_array('shop_subscription', $registered, true)
        ? $sum_statuses('shop_subscription', $active_statuses)
        : 0;

    $count_terms = function (string $taxonomy): int {
        if (!taxonomy_exists($taxonomy)) return 0;
        $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
        return is_wp_error($count) ? 0 : (int) $count;
    };

    $data = [
        'customers'           => $users_total,
        'orders'              => $orders_total,
        'products'            => $sum_statuses('product', $active_statuses),
        'product_variations'  => $sum_statuses('product_variation', ['publish', 'private']),
        'posts'               => $sum_statuses('post', $active_statuses),
        'pages'               => $sum_statuses('page', $active_statuses),
        'faqs'                => $faq_type ? $sum_statuses($faq_type, $active_statuses) : 0,
        'herbs'               => $herb_type ? $sum_statuses($herb_type, $active_statuses) : 0,
        'coupons'             => $sum_statuses('shop_coupon', $active_statuses),
        'menus'               => $count_terms('nav_menu'),
        'media'               => (int) (wp_count_posts('attachment')->inherit ?? 0),
        'product_categories'  => $count_terms('product_cat'),
        'product_tags'        => $count_terms('product_tag'),
        'post_categories'     => $count_terms('category'),
        'post_tags'           => $count_terms('post_tag'),
        'reviews'             => $reviews_total,
        'subscriptions'       => $subscriptions_total,
    ];

    // ETag over the payload lets pollers cheaply detect "no change since last sync" —
    // key order is stable because we assemble the array literally.
    $etag     = md5((string) wp_json_encode($data));
    $response = new WP_REST_Response($data);
    $response->header('ETag', '"' . $etag . '"');
    $response->header('Cache-Control', 'private, max-age=0, must-revalidate');

    $client_etag = $request->get_header('If-None-Match');
    if ($client_etag !== null && trim($client_etag, " \t\"") === $etag) {
        $response->set_data(null);
        $response->set_status(304);
    }

    return $response;
}


// ── PAGES ────────────────────────────────────

function bb_api_get_pages(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $include_drafts = filter_var($request->get_param('include_drafts'), FILTER_VALIDATE_BOOLEAN);

    // Always-excluded utility slugs. Callers can extend via ?exclude_slugs=a,b,c.
    $excluded_slugs = ['shop', 'cart', 'checkout', 'my-account', 'thank-you'];
    $extra_exclude  = $request->get_param('exclude_slugs');
    if (!empty($extra_exclude)) {
        foreach (explode(',', (string) $extra_exclude) as $slug) {
            $slug = trim($slug);
            if ($slug !== '') $excluded_slugs[] = $slug;
        }
    }

    global $wpdb;
    // Resolve exact-slug exclusions + wildcard prefixes (wpforms-*, funnel-*) to IDs so
    // WP_Query filters them at query time and pagination totals stay accurate.
    $exclude_ids = [];
    if (!empty($excluded_slugs)) {
        $placeholders = implode(',', array_fill(0, count($excluded_slugs), '%s'));
        $exclude_ids  = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_name IN ($placeholders)",
            ...$excluded_slugs
        ));
    }
    $wildcard_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND (post_name LIKE 'wpforms-%' OR post_name LIKE 'funnel-%')"
    );
    $exclude_ids = array_map('intval', array_unique(array_merge($exclude_ids, $wildcard_ids)));

    $query_args = [
        'post_type'      => 'page',
        'post_status'    => $include_drafts ? ['publish', 'draft', 'pending', 'private', 'future'] : 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if (!empty($exclude_ids)) {
        $query_args['post__not_in'] = $exclude_ids;
    }
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $items = [];

    $aioseo_map = bb_api_fetch_aioseo_for_posts(wp_list_pluck($query->posts, 'ID'));

    foreach ($query->posts as $post) {
        $featured_id  = get_post_thumbnail_id($post->ID);
        $featured_url = $featured_id ? wp_get_attachment_url($featured_id) : '';

        $all_meta  = get_post_meta($post->ID);
        $flat_meta = [];
        foreach ($all_meta as $key => $values) {
            $flat_meta[$key] = count($values) === 1
                ? maybe_unserialize($values[0])
                : array_map('maybe_unserialize', $values);
        }

        $template = get_page_template_slug($post->ID) ?: 'default';

        $items[] = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'content'        => bb_api_render_post_content($post->post_content),
            'excerpt'        => $post->post_excerpt,
            'parent_id'      => (int) $post->post_parent,
            'menu_order'     => (int) $post->menu_order,
            'template'       => $template,
            'date_created'   => $post->post_date,
            'date_modified'  => $post->post_modified,
            'author_id'      => (int) $post->post_author,
            'featured_image' => ['id' => $featured_id, 'url' => $featured_url],
            'aioseo'         => $aioseo_map[$post->ID] ?? null,
            'meta'           => $flat_meta,
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── MENUS ────────────────────────────────────

function bb_api_get_menus(WP_REST_Request $request): WP_REST_Response {
    $locations_map = get_nav_menu_locations();
    // Normalize to [location => menu_id], skipping unassigned (id=0) slots.
    $locations = [];
    foreach ($locations_map as $loc => $menu_id) {
        if ((int) $menu_id > 0) $locations[$loc] = (int) $menu_id;
    }

    $menus = [];
    foreach (wp_get_nav_menus() as $menu) {
        /** @var WP_Term $menu */
        $raw_items = wp_get_nav_menu_items($menu->term_id, ['update_post_term_cache' => false]) ?: [];

        $items = [];
        foreach ($raw_items as $item) {
            $items[] = [
                'id'          => (int) $item->ID,
                'title'       => $item->title,
                'url'         => $item->url,
                'target'      => $item->target,
                'type'        => $item->type,
                'object'      => $item->object,
                'object_id'   => (int) $item->object_id,
                'parent'      => (int) $item->menu_item_parent,
                'order'       => (int) $item->menu_order,
                'classes'     => array_values(array_filter((array) $item->classes)),
                'description' => $item->description,
                'xfn'         => $item->xfn,
            ];
        }

        $menus[] = [
            'id'    => (int) $menu->term_id,
            'name'  => $menu->name,
            'slug'  => $menu->slug,
            'items' => $items,
        ];
    }

    return new WP_REST_Response([
        'locations' => (object) $locations,
        'menus'     => $menus,
    ]);
}


// ── MEDIA ────────────────────────────────────

function bb_api_get_media(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    $mime = $request->get_param('mime_type');
    if (!empty($mime)) {
        $query_args['post_mime_type'] = (string) $mime;
    }
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $items = [];

    foreach ($query->posts as $post) {
        $meta      = wp_get_attachment_metadata($post->ID) ?: [];
        // wp_get_attachment_url honors Jetpack / Cloudflare URL rewrites via the standard filter.
        $url       = wp_get_attachment_url($post->ID) ?: '';
        $file_path = get_attached_file($post->ID) ?: '';

        $filesize = 0;
        if (!empty($meta['filesize'])) {
            $filesize = (int) $meta['filesize'];
        } elseif ($file_path && is_readable($file_path)) {
            $filesize = (int) @filesize($file_path);
        }

        $items[] = [
            'id'             => $post->ID,
            'title'          => $post->post_title,
            'filename'       => $file_path ? basename($file_path) : '',
            'url'            => $url,
            'mime_type'      => $post->post_mime_type,
            // Spec: alt MUST come from _wp_attachment_image_alt meta.
            'alt'            => (string) get_post_meta($post->ID, '_wp_attachment_image_alt', true),
            'caption'        => $post->post_excerpt,
            'description'    => $post->post_content,
            'width'          => isset($meta['width']) ? (int) $meta['width'] : null,
            'height'         => isset($meta['height']) ? (int) $meta['height'] : null,
            'filesize'       => $filesize,
            'parent_post_id' => (int) $post->post_parent,
            'date_created'   => $post->post_date,
            'date_modified'  => $post->post_modified,
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── TERMS ────────────────────────────────────

function bb_api_get_terms(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $taxonomy = trim((string) $request->get_param('taxonomy'));
    if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
        return new WP_Error(
            'bb_api_invalid_taxonomy',
            'Unknown or missing taxonomy. Pass ?taxonomy=product_cat (or similar registered taxonomy).',
            ['status' => 400]
        );
    }

    $count = wp_count_terms(['taxonomy' => $taxonomy, 'hide_empty' => false]);
    $total = is_wp_error($count) ? 0 : (int) $count;

    $terms = get_terms([
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
        'number'     => $per_page,
        'offset'     => ($page - 1) * $per_page,
        'orderby'    => 'term_id',
        'order'      => 'ASC',
    ]);
    if (is_wp_error($terms)) {
        return $terms;
    }

    $term_ids   = array_map(static fn ($t) => (int) $t->term_id, $terms);
    $aioseo_map = bb_api_fetch_aioseo_for_terms($term_ids);

    $items = [];
    foreach ($terms as $term) {
        /** @var WP_Term $term */
        $all_meta  = get_term_meta($term->term_id);
        $flat_meta = [];
        foreach ($all_meta as $key => $values) {
            $flat_meta[$key] = count($values) === 1
                ? maybe_unserialize($values[0])
                : array_map('maybe_unserialize', $values);
        }

        // Featured image: Woo's product_cat uses `thumbnail_id`; Yoast/other taxonomies reuse the same key.
        $thumb_id  = (int) ($flat_meta['thumbnail_id'] ?? 0);
        $thumb_url = $thumb_id ? wp_get_attachment_url($thumb_id) : '';

        $items[] = [
            'id'             => (int) $term->term_id,
            'taxonomy'       => $term->taxonomy,
            'name'           => $term->name,
            'slug'           => $term->slug,
            'parent_id'      => (int) $term->parent,
            'description'    => $term->description,
            'count'          => (int) $term->count,
            'featured_image' => ['id' => $thumb_id, 'url' => $thumb_url ?: ''],
            'aioseo'         => $aioseo_map[(int) $term->term_id] ?? null,
            'meta'           => $flat_meta,
        ];
    }

    // Note: core taxonomy terms have no `term_modified` column, so modified_after is
    // accepted (for API symmetry) but cannot filter here. Last-Modified is also omitted.
    return bb_api_paginated_response($items, $total, $page, $per_page);
}

/**
 * Bulk-fetch AIOSEO term rows. Mirrors bb_api_fetch_aioseo_for_posts; null-safe.
 */
function bb_api_fetch_aioseo_for_terms(array $term_ids): array {
    $term_ids = array_values(array_unique(array_filter(array_map('intval', $term_ids))));
    if (empty($term_ids)) return [];

    global $wpdb;
    $table = $wpdb->prefix . 'aioseo_terms';

    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) return [];

    $placeholders = implode(',', array_fill(0, count($term_ids), '%d'));
    $rows = $wpdb->get_results(
        $wpdb->prepare("SELECT * FROM $table WHERE term_id IN ($placeholders)", ...$term_ids),
        ARRAY_A
    );

    $map = [];
    foreach ($rows ?: [] as $row) {
        // Reuse the post formatter: overlapping columns (title/description/og_*/schema_*) carry over;
        // non-applicable fields are simply null for terms.
        $row['post_id']   = (int) ($row['term_id'] ?? 0);
        $row['post_type'] = 'term';
        $map[(int) ($row['term_id'] ?? 0)] = bb_api_format_aioseo_row($row);
    }
    return $map;
}


// ── COUPONS ──────────────────────────────────

function bb_api_get_coupons(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    if (!function_exists('wc_get_coupon') && !class_exists('WC_Coupon')) {
        return new WP_Error('bb_api_no_woocommerce', 'WooCommerce is not active.', ['status' => 503]);
    }

    $status = (string) ($request->get_param('status') ?: 'publish');

    $query_args = [
        'post_type'      => 'shop_coupon',
        'post_status'    => $status,
        'posts_per_page' => $per_page,
        'paged'          => $page,
        'orderby'        => 'ID',
        'order'          => 'ASC',
    ];
    if ($modified_after instanceof DateTimeImmutable) {
        $query_args['date_query'] = bb_api_date_query_post_modified($modified_after);
    }

    $query = new WP_Query($query_args);
    $total = $query->found_posts;
    $items = [];

    foreach ($query->posts as $post) {
        $coupon = new WC_Coupon($post->ID);
        if (!$coupon || !$coupon->get_id()) continue;

        $items[] = [
            'id'                          => $coupon->get_id(),
            'code'                        => $coupon->get_code(),
            'description'                 => $coupon->get_description(),
            'discount_type'               => $coupon->get_discount_type(),
            'amount'                      => $coupon->get_amount(),
            'date_expires'                => $coupon->get_date_expires()?->format('c'),
            'date_created'                => $coupon->get_date_created()?->format('c'),
            'date_modified'               => $coupon->get_date_modified()?->format('c'),
            'usage_count'                 => (int) $coupon->get_usage_count(),
            'usage_limit'                 => $coupon->get_usage_limit() ?: null,
            'usage_limit_per_user'        => $coupon->get_usage_limit_per_user() ?: null,
            'individual_use'              => (bool) $coupon->get_individual_use(),
            'minimum_amount'              => $coupon->get_minimum_amount(),
            'maximum_amount'              => $coupon->get_maximum_amount(),
            'email_restrictions'          => $coupon->get_email_restrictions(),
            'product_ids'                 => array_map('intval', $coupon->get_product_ids()),
            'excluded_product_ids'        => array_map('intval', $coupon->get_excluded_product_ids()),
            'product_categories'          => array_map('intval', $coupon->get_product_categories()),
            'excluded_product_categories' => array_map('intval', $coupon->get_excluded_product_categories()),
            'exclude_sale_items'          => (bool) $coupon->get_exclude_sale_items(),
            'free_shipping'               => (bool) $coupon->get_free_shipping(),
            'meta_data'                   => $coupon->get_meta_data(),
        ];
    }

    wp_reset_postdata();
    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'date_modified')
    );
}


// ── AIOSEO single-row lookup ─────────────────

function bb_api_get_aioseo_for_post(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $post_type = (string) $request->get_param('post_type');
    $post_id   = (int) $request->get_param('id');

    if ($post_id <= 0) {
        return new WP_Error('bb_api_invalid_id', 'id must be a positive integer.', ['status' => 400]);
    }

    global $wpdb;
    $table  = $wpdb->prefix . 'aioseo_posts';
    $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    if ($exists !== $table) {
        return new WP_Error('bb_api_aioseo_missing', 'AIOSEO is not installed.', ['status' => 404]);
    }

    $row = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE post_id = %d AND post_type = %s LIMIT 1", $post_id, $post_type),
        ARRAY_A
    );
    if (!$row) {
        return new WP_Error('bb_api_aioseo_not_found', 'No AIOSEO row found for that post.', ['status' => 404]);
    }

    return new WP_REST_Response(bb_api_format_aioseo_row($row));
}
