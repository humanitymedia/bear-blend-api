<?php
/**
 * Bear Blend API — Inventory routes
 *
 * Adds the /inventory/* endpoints under the bb/v1 namespace. Reads from the
 * og.bearblend.com legacy inventory system:
 *   - `item` and `vendor` CPTs (master records, ACF fields)
 *   - Formidable Forms entries in {prefix}frm_items / {prefix}frm_item_metas
 *     (operational ledger: receiving, adjustments, assemblies, shipping, POs)
 *
 * Loaded from the main plugin via `require_once __DIR__ . '/inventory-routes.php';`.
 * Uses the same auth, pagination, and modified_after helpers as the rest of bb/v1.
 *
 * Schema reference: see the og-scan-2026-05-13 report. Formidable field IDs are
 * pinned below; they are stable within a Formidable install but won't survive a
 * form rebuild — if a form is recreated, update the FRM_FIELD_* constants.
 */

defined('ABSPATH') || exit;


// ──────────────────────────────────────────────
// Formidable field-ID maps (form_id → semantic_name → field_id)
// ──────────────────────────────────────────────

const BB_FRM_FORM_RECEIVING       = 3;
const BB_FRM_FORM_ADJUSTMENTS     = 4;
const BB_FRM_FORM_ASSEMBLIES      = 5;
const BB_FRM_FORM_INGREDIENTS     = 6;   // child entries of assemblies (parent_item_id)
const BB_FRM_FORM_SHIPPING_LOG    = 11;
const BB_FRM_FORM_PURCHASE_ORDERS = 16;
const BB_FRM_FORM_PO_LINE_ITEMS   = 17;  // child entries of POs (parent_item_id)

/** Form 3 — Receiving Logs */
const BB_FRM_RECEIVING = [
    'inventory_item_id'        => 11,
    'includes_lot_number'      => 60,
    'lot_number'               => 12,
    'vendor'                   => 61,
    'received_on'              => 13,
    'quantity'                 => 14,
    'current_quantity_before'  => 55,
    'updated_quantity_after'   => 56,
    'cost'                     => 21,
    'shipping_cost'            => 501,
    'cost_per_unit'            => 443,
    'packing_slip_attachments' => 481,
    'notes'                    => 62,
    'user_id'                  => 480,
];

/** Form 4 — Adjust Inventory */
const BB_FRM_ADJUSTMENTS = [
    'inventory_item_id'       => 24,
    'includes_lot_number'     => 58,
    'lot_number'              => 25,
    'expiration_date'         => 444,
    'vendor'                  => 59,
    'pounds'                  => 67,
    'ounces'                  => 68,
    'adjust_quantity'         => 26,
    'current_quantity_before' => 52,
    'new_quantity_after'      => 53,
    'adjustment_date'         => 66,
    'adjustment_reason'       => 57,
    'notes'                   => 27,
    'user_id'                 => 479,
];

/** Form 5 — Assemble Inventory */
const BB_FRM_ASSEMBLIES = [
    'inventory_item_id'             => 36,
    'quantity_made'                 => 45,
    'date'                          => 63,
    'lot_number'                    => 38,
    'expiration_date'               => 65,
    'made_by'                       => 502,
    'made_by_og'                    => 42,
    'total_cost_per_unit'           => 441,
    'time_to_make'                  => 440,
    'how_many_working'              => 462,
    'total_minutes'                 => 460,
    'cost_per_unit_including_time'  => 461,
    'notes'                         => 40,
    'user_id'                       => 478,
];

/** Form 6 — Ingredients (child of assemblies via parent_item_id) */
const BB_FRM_INGREDIENTS = [
    'ingredient_item_id' => 47,
    'quantity'           => 48,   // note: form labels this "Quanitity" (sic)
    'lot_number'         => 46,
    'expiration_date'    => 69,
    'cost'               => 439,
    'per_item_cost'      => 459,
];

/** Form 11 — Shipping Log Simple */
const BB_FRM_SHIPPING_LOG = [
    'order_sku'        => 458,
    'product_name'     => 474,
    'product_id'       => 475,  // WooCommerce product post ID
    'order_numbers'    => 449,
    'lot_number'       => 450,
    'expiration_date'  => 457,
    'quantity'         => 448,
    'total_price'      => 482,
    'cost_per_unit'    => 483,
    'total_profit'     => 484,
    'margin'           => 485,
    'user_id'          => 500,
];

/** Form 16 — Purchase Order header */
const BB_FRM_PURCHASE_ORDERS = [
    'vendor_id'       => 487,
    'send_to_contact' => 497,
    'contact'         => 496,
    'total_cost'      => 493,
    'pdf_label'       => 498,
    'user_id'         => 499,
];

/** Form 17 — PO line items (child of POs via parent_item_id) */
const BB_FRM_PO_LINE_ITEMS = [
    'item_id'         => 490,  // item CPT post ID
    'item_sku'        => 494,
    'amount'          => 491,
    'cost'            => 492,
    'calculated_cost' => 495,
];


// ──────────────────────────────────────────────
// Helpers — Formidable entry reads
// ──────────────────────────────────────────────

/**
 * Cast a raw Formidable meta_value to a sensible PHP scalar/array. Strings that
 * look like serialized PHP arrays (file fields, divider-grouped child IDs) get
 * unserialized; everything else is returned as-is.
 */
function bb_api_frm_decode_value($raw): mixed {
    if (!is_string($raw)) return $raw;
    if ($raw === '') return '';
    if (str_starts_with($raw, 'a:') || str_starts_with($raw, 's:') || str_starts_with($raw, 'i:')) {
        $decoded = @maybe_unserialize($raw);
        return $decoded === false && $raw !== 'b:0;' ? $raw : $decoded;
    }
    return $raw;
}

/**
 * Pull all field_id => decoded_value pairs for a single Formidable entry.
 * @return array<int, mixed>
 */
function bb_api_frm_entry_meta(int $entry_id): array {
    global $wpdb;
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d",
            $entry_id
        ),
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['field_id']] = bb_api_frm_decode_value($r['meta_value']);
    }
    return $out;
}

/**
 * Bulk variant: fetch meta for many entry IDs in one query.
 * @return array<int, array<int, mixed>> keyed by entry_id then field_id
 */
function bb_api_frm_entry_meta_bulk(array $entry_ids): array {
    if (empty($entry_ids)) return [];
    global $wpdb;
    $entry_ids   = array_map('intval', $entry_ids);
    $placeholders = implode(',', array_fill(0, count($entry_ids), '%d'));
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT item_id, field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id IN ($placeholders)",
            ...$entry_ids
        ),
        ARRAY_A
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['item_id']][(int) $r['field_id']] = bb_api_frm_decode_value($r['meta_value']);
    }
    return $out;
}

/**
 * Project a raw {field_id => value} map onto a semantic schema {name => field_id},
 * returning a clean {name => value} array.
 */
function bb_api_frm_project(array $meta, array $field_map): array {
    $out = [];
    foreach ($field_map as $name => $fid) {
        $out[$name] = $meta[$fid] ?? null;
    }
    return $out;
}

/**
 * Generic list endpoint for a Formidable form. Handles pagination, modified_after
 * (via `updated_at`), and projection to a semantic schema. Per-entry post-processing
 * (e.g. attaching children, resolving foreign keys) is done via $hydrate callback.
 *
 * $hydrate signature: function(array $base, array $raw_meta, object $entry_row): array
 */
function bb_api_frm_list_endpoint(
    WP_REST_Request $request,
    int $form_id,
    array $field_map,
    ?callable $hydrate = null
): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    global $wpdb;

    $where  = ['form_id = %d'];
    $params = [$form_id];
    if ($modified_after instanceof DateTimeImmutable) {
        $where[]  = 'updated_at > %s';
        $params[] = $modified_after->format('Y-m-d H:i:s');
    }
    $where_sql = 'WHERE ' . implode(' AND ', $where);

    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}frm_items $where_sql",
        ...$params
    ));

    $offset  = ($page - 1) * $per_page;
    $entries = $wpdb->get_results($wpdb->prepare(
        "SELECT id, item_key, name, created_at, updated_at, user_id, is_draft, parent_item_id
         FROM {$wpdb->prefix}frm_items
         $where_sql
         ORDER BY updated_at DESC, id DESC
         LIMIT %d OFFSET %d",
        ...[...$params, $per_page, $offset]
    ));

    if (empty($entries)) {
        return bb_api_paginated_response([], $total, $page, $per_page, $request, null);
    }

    $meta_bulk = bb_api_frm_entry_meta_bulk(array_map(fn($e) => (int) $e->id, $entries));

    $items = [];
    foreach ($entries as $e) {
        $raw  = $meta_bulk[(int) $e->id] ?? [];
        $base = [
            'id'             => (int) $e->id,
            'parent_item_id' => (int) $e->parent_item_id,
            'is_draft'       => (int) $e->is_draft,
            'created_at'     => $e->created_at,
            'updated_at'     => $e->updated_at,
            'user_id'        => (int) $e->user_id,
        ];
        $base = array_merge($base, bb_api_frm_project($raw, $field_map));
        if ($hydrate !== null) {
            $base = $hydrate($base, $raw, $e);
        }
        $items[] = $base;
    }

    return bb_api_paginated_response(
        $items,
        $total,
        $page,
        $per_page,
        $request,
        bb_api_max_modified($items, 'updated_at')
    );
}


// ──────────────────────────────────────────────
// /inventory/items — `item` CPT, ACF-flattened
// ──────────────────────────────────────────────

function bb_api_get_inventory_items(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'post_type'      => 'item',
        'post_status'    => ['publish', 'draft'],
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
        $acf = function_exists('get_fields') ? (get_fields($post->ID) ?: []) : [];
        $acf = bb_api_reduce_acf_post_refs($acf);

        // Taxonomies — item-category and itemstatus are ACF-controlled taxonomies.
        $taxonomies = [];
        foreach (['item-category', 'itemstatus'] as $tax) {
            $terms = wp_get_post_terms($post->ID, $tax);
            if (!is_wp_error($terms) && !empty($terms)) {
                $taxonomies[$tax] = array_map(
                    fn($t) => ['id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug],
                    $terms
                );
            }
        }

        $items[] = [
            'id'                    => $post->ID,
            'title'                 => $post->post_title,
            'slug'                  => $post->post_name,
            'status'                => $post->post_status,
            'date_created'          => $post->post_date,
            'date_modified'         => $post->post_modified,
            'main_sku'              => $acf['main_sku']               ?? null,
            'units_of_measure'      => $acf['units_of_measure']       ?? null,
            'item_cost'             => $acf['item_cost']              ?? null,
            'total_quantity_on_hand'=> $acf['total_quantity_on_hand'] ?? null,
            'bin_number'            => $acf['bin_number']             ?? null,
            'avg_time_per_unit_min' => $acf['average_time_required_per_unit'] ?? null,
            'track_lots'            => (bool) ($acf['track_lots']     ?? false),
            'lots_type'             => $acf['lots_type']              ?? null,
            'includes_recipe'       => (bool) ($acf['includes_recipe']?? false),
            'vendor_legacy'         => $acf['vendor']                 ?? null,
            'vendor_sku_legacy'     => $acf['vendor_sku']             ?? null,
            'vendor_upc_legacy'     => $acf['vendor_upc']             ?? null,
            'vendors'               => $acf['vendors']                ?? [],
            'skus'                  => $acf['skus']                   ?? [],
            'lots'                  => $acf['lots']                   ?? [],
            'recipe'                => $acf['recipe']                 ?? [],
            'taxonomies'            => $taxonomies,
        ];
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


// ──────────────────────────────────────────────
// /inventory/vendors — `vendor` CPT, ACF-flattened
// ──────────────────────────────────────────────

function bb_api_get_inventory_vendors(WP_REST_Request $request): WP_REST_Response|WP_Error {
    ['page' => $page, 'per_page' => $per_page] = bb_api_pagination_args($request);

    $modified_after = bb_api_parse_modified_after($request);
    if ($modified_after instanceof WP_Error) return $modified_after;

    $query_args = [
        'post_type'      => 'vendor',
        'post_status'    => ['publish', 'draft'],
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
        $acf = function_exists('get_fields') ? (get_fields($post->ID) ?: []) : [];
        $acf = bb_api_reduce_acf_post_refs($acf);

        $items[] = [
            'id'             => $post->ID,
            'name'           => $post->post_title,
            'slug'           => $post->post_name,
            'status'         => $post->post_status,
            'date_created'   => $post->post_date,
            'date_modified'  => $post->post_modified,
            'account_number' => $acf['account_number'] ?? null,
            'account_email'  => $acf['account_email']  ?? null,
            'address'        => $acf['address']        ?? null,
            'website'        => $acf['website']        ?? null,
            'terms'          => $acf['terms']          ?? null,
            'contacts'       => $acf['contacts']       ?? [],
            'invoices'       => $acf['invoices']       ?? [],
        ];
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


// ──────────────────────────────────────────────
// /inventory/receiving — Formidable form 3
// ──────────────────────────────────────────────

function bb_api_get_inventory_receiving(WP_REST_Request $request): WP_REST_Response|WP_Error {
    return bb_api_frm_list_endpoint(
        $request,
        BB_FRM_FORM_RECEIVING,
        BB_FRM_RECEIVING,
        function (array $base, array $raw, object $entry): array {
            // packing_slip_attachments is serialized like a:1:{i:1;i:1155;}
            // — coerce to a flat list of attachment IDs for consumers.
            $att = $base['packing_slip_attachments'] ?? null;
            $base['packing_slip_attachments'] = is_array($att)
                ? array_values(array_map('intval', $att))
                : [];
            $base['inventory_item_id'] = (int) ($base['inventory_item_id'] ?? 0);
            return $base;
        }
    );
}


// ──────────────────────────────────────────────
// /inventory/adjustments — Formidable form 4
// ──────────────────────────────────────────────

function bb_api_get_inventory_adjustments(WP_REST_Request $request): WP_REST_Response|WP_Error {
    return bb_api_frm_list_endpoint(
        $request,
        BB_FRM_FORM_ADJUSTMENTS,
        BB_FRM_ADJUSTMENTS,
        function (array $base, array $raw, object $entry): array {
            $base['inventory_item_id'] = (int) ($base['inventory_item_id'] ?? 0);
            return $base;
        }
    );
}


// ──────────────────────────────────────────────
// /inventory/assemblies — Formidable form 5 + nested form 6 children
// ──────────────────────────────────────────────

function bb_api_get_inventory_assemblies(WP_REST_Request $request): WP_REST_Response|WP_Error {
    // We need to attach Form 6 children for every assembly in the page. Do the
    // generic list first, then a single bulk query for the ingredients.
    $response = bb_api_frm_list_endpoint(
        $request,
        BB_FRM_FORM_ASSEMBLIES,
        BB_FRM_ASSEMBLIES,
        function (array $base, array $raw, object $entry): array {
            $base['inventory_item_id'] = (int) ($base['inventory_item_id'] ?? 0);
            return $base;
        }
    );

    if ($response instanceof WP_Error || $response->get_status() === 304) {
        return $response;
    }

    $assemblies = $response->get_data();
    if (empty($assemblies)) return $response;

    global $wpdb;
    $assembly_ids = array_map(fn($a) => (int) $a['id'], $assemblies);
    $placeholders = implode(',', array_fill(0, count($assembly_ids), '%d'));

    $child_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, parent_item_id, created_at, updated_at
         FROM {$wpdb->prefix}frm_items
         WHERE form_id = %d AND parent_item_id IN ($placeholders)
         ORDER BY id ASC",
        ...[BB_FRM_FORM_INGREDIENTS, ...$assembly_ids]
    ));

    $child_meta = bb_api_frm_entry_meta_bulk(array_map(fn($r) => (int) $r->id, $child_rows));

    $ingredients_by_parent = [];
    foreach ($child_rows as $r) {
        $proj = bb_api_frm_project($child_meta[(int) $r->id] ?? [], BB_FRM_INGREDIENTS);
        $proj['id']                  = (int) $r->id;
        $proj['created_at']          = $r->created_at;
        $proj['updated_at']          = $r->updated_at;
        $proj['ingredient_item_id']  = (int) ($proj['ingredient_item_id'] ?? 0);
        $ingredients_by_parent[(int) $r->parent_item_id][] = $proj;
    }

    foreach ($assemblies as &$a) {
        $a['ingredients'] = $ingredients_by_parent[$a['id']] ?? [];
    }

    $response->set_data($assemblies);
    return $response;
}


// ──────────────────────────────────────────────
// /inventory/shipping-logs — Formidable form 11
// ──────────────────────────────────────────────

function bb_api_get_inventory_shipping_logs(WP_REST_Request $request): WP_REST_Response|WP_Error {
    return bb_api_frm_list_endpoint(
        $request,
        BB_FRM_FORM_SHIPPING_LOG,
        BB_FRM_SHIPPING_LOG,
        function (array $base, array $raw, object $entry): array {
            $base['product_id'] = (int) ($base['product_id'] ?? 0);
            return $base;
        }
    );
}


// ──────────────────────────────────────────────
// /inventory/purchase-orders — Formidable form 16 + nested form 17 line items
// ──────────────────────────────────────────────

function bb_api_get_inventory_purchase_orders(WP_REST_Request $request): WP_REST_Response|WP_Error {
    $response = bb_api_frm_list_endpoint(
        $request,
        BB_FRM_FORM_PURCHASE_ORDERS,
        BB_FRM_PURCHASE_ORDERS,
        function (array $base, array $raw, object $entry): array {
            $base['vendor_id'] = (int) ($base['vendor_id'] ?? 0);
            return $base;
        }
    );

    if ($response instanceof WP_Error || $response->get_status() === 304) {
        return $response;
    }

    $pos = $response->get_data();
    if (empty($pos)) return $response;

    global $wpdb;
    $po_ids = array_map(fn($p) => (int) $p['id'], $pos);
    $placeholders = implode(',', array_fill(0, count($po_ids), '%d'));

    $child_rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, parent_item_id, created_at, updated_at
         FROM {$wpdb->prefix}frm_items
         WHERE form_id = %d AND parent_item_id IN ($placeholders)
         ORDER BY id ASC",
        ...[BB_FRM_FORM_PO_LINE_ITEMS, ...$po_ids]
    ));

    $child_meta = bb_api_frm_entry_meta_bulk(array_map(fn($r) => (int) $r->id, $child_rows));

    $lines_by_parent = [];
    foreach ($child_rows as $r) {
        $proj = bb_api_frm_project($child_meta[(int) $r->id] ?? [], BB_FRM_PO_LINE_ITEMS);
        $proj['id']         = (int) $r->id;
        $proj['created_at'] = $r->created_at;
        $proj['updated_at'] = $r->updated_at;
        $proj['item_id']    = (int) ($proj['item_id'] ?? 0);
        $lines_by_parent[(int) $r->parent_item_id][] = $proj;
    }

    foreach ($pos as &$p) {
        $p['line_items'] = $lines_by_parent[$p['id']] ?? [];
    }

    $response->set_data($pos);
    return $response;
}


// ──────────────────────────────────────────────
// REGISTER ROUTES
// ──────────────────────────────────────────────

add_action('rest_api_init', function () {
    $ns = 'bb/v1';

    $inventory_endpoints = [
        'inventory/items'           => 'bb_api_get_inventory_items',
        'inventory/vendors'         => 'bb_api_get_inventory_vendors',
        'inventory/receiving'       => 'bb_api_get_inventory_receiving',
        'inventory/adjustments'     => 'bb_api_get_inventory_adjustments',
        'inventory/assemblies'      => 'bb_api_get_inventory_assemblies',
        'inventory/shipping-logs'   => 'bb_api_get_inventory_shipping_logs',
        'inventory/purchase-orders' => 'bb_api_get_inventory_purchase_orders',
    ];

    foreach ($inventory_endpoints as $route => $callback) {
        register_rest_route($ns, '/' . $route, [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => $callback,
            'permission_callback' => 'bb_api_check_auth',
            'args'                => bb_api_list_params(),
        ]);
    }
});
