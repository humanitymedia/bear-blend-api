# Prompt for Claude Code — bear-blend-api v1.1 follow-ups

Paste this into a Claude Code session opened in `~/Sites/bearblend-wp/bear-blend-api`.

---

You are helping fix four issues flagged after smoke-testing the v1.1.0 release of the Bear Blend API plugin. Context: this plugin exposes read-only REST endpoints under `/wp-json/bb/v1/*` so a Laravel rebuild can sync content from WordPress/WooCommerce. The v1.1 spec is in `V1.1_SPEC.md` at the repo root — please read it first for shape references and acceptance criteria.

All four issues were found by hitting the endpoints on live production (`bearblend.com`) with the configured Bearer token. The plugin auto-deploys from `main` via `.github/workflows/deploy.yml`. Work on a branch, commit cleanly, and open a PR (or push to main if you prefer — existing repo workflow is trunk-based).

## Issue 1 — `/pages` returns raw Divi shortcodes (HIGH priority)

The `V1.1_SPEC.md` §2 requires page content to be returned post-shortcode-rendering so Laravel can consume it directly:

> Returns content post-shortcode-rendering via `apply_filters('the_content', $content)` so Divi/shortcode-heavy pages are consumable.

Current behavior: the `content` field contains raw shortcodes like `[et_pb_section bb_built="1" fullwidth="on"][et_pb_fullwidth_header title="100% ORGANIC HERBAL SMOKE BLEND"...]`. This blocks page migration entirely.

**Fix:** in the `/pages` endpoint callback, wrap `$post->post_content` with `apply_filters('the_content', $post->post_content)` before returning. Do the same for any other endpoint that returns post content (check `/posts`, `/faqs`, `/herbs`). Watch the `do_blocks` path too if pages use Gutenberg blocks.

**Verify:**
```bash
curl -H "Authorization: Bearer <KEY>" "https://bearblend.com/wp-json/bb/v1/pages?per_page=1" \
  | jq -r '.[0].content' | head -c 500
```
Output should be rendered HTML (`<div class="et_pb_section ..."`) not `[et_pb_section ...]`.

## Issue 2 — `/counts` missing ETag header (LOW priority)

Spec §11 adds `ETag` + `Last-Modified` to paginated responses. The `/counts` endpoint is a single JSON object (not paginated) but it still benefits from caching — consumers poll it to decide whether to run a full sync.

**Fix:** hash the counts array (sorted by key) as the ETag:
```php
$etag = md5(json_encode($counts));
$response->header('ETag', '"' . $etag . '"');
```
Also support the `If-None-Match` request header and return `304 Not Modified` when it matches.

**Verify:**
```bash
ETAG=$(curl -sI -H "Auth: Bearer <KEY>" .../counts | grep -i etag | awk '{print $2}')
curl -sI -H "Auth: Bearer <KEY>" -H "If-None-Match: $ETAG" .../counts | head -1
# expect: HTTP/2 304
```

## Issue 3 — `/orders?fields=minimal` is smaller but not faster (MEDIUM priority)

Spec §10 requires minimal mode to be **10×+ faster** than full mode (not just smaller payload). Current measurement: 1.09s minimal vs 1.11s full — only 2% faster. Payload correctly drops from 25.3KB → 1.1KB (23× smaller) but the wall-clock savings are minimal, suggesting the code still calls `wc_get_order()` / hydrates the full order object and then trims the response.

**Fix:** when `?fields=minimal` is present, skip the full order hydration entirely. Query the HPOS table directly (or use `wc_get_orders()` with `return => 'ids'` then a targeted secondary query for just id/status/date_modified/total/customer_id). Avoid calling any `get_*()` method that lazy-loads line items, refunds, or meta.

Example approach:
```php
if ($request->get_param('fields') === 'minimal') {
    global $wpdb;
    $hpos_table = $wpdb->prefix . 'wc_orders';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id, status, date_updated_gmt, total_amount, customer_id
         FROM {$hpos_table}
         WHERE type = 'shop_order'
         ORDER BY id ASC
         LIMIT %d OFFSET %d",
        $per_page, ($page - 1) * $per_page
    ), ARRAY_A);
    // format and return — no wc_get_order() calls
}
```
Check whether HPOS is enabled via `\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()` and fall back to `wp_posts`-based query if not.

**Verify:** minimal mode should return in under **150ms** (target: <1s for full mode, 10×+ faster for minimal). Run both and compare `time_total` in curl.

## Issue 4 — `/herbs` returns 0 records (LOW/EXTERNAL priority)

`/wp-json/bb/v1/herbs` returns empty. Root cause: the plugin tries post types `herb` and `herbs`, neither is registered on live WordPress. Content that represents herbs appears to be stored as pages under the `/herbs` parent page.

The spec doesn't explicitly require fixing this, but it's been broken since v1.0.3. Two options:

**Option A (preferred):** register a `herb` custom post type in this plugin (or verify it's registered by a theme function). Audit what post type herb pages actually use on live — look at `wp_posts.post_type` for posts whose permalink starts with `/herbs/`.

**Option B:** if pages-with-parent-slug-"herbs" is the source of truth, keep the current page-based fallback but audit why `get_page_by_path('herbs')` is returning null. The existing fallback is in place; it's the lookup that's failing.

Investigate on live by running (inside the `bearblend-wp-app` container):
```bash
wp eval 'var_dump(get_post_types([], "names"));' --path=/var/www/html
wp post list --post_type=any --meta_key=_wp_page_template --format=count --path=/var/www/html
wp post list --post_parent=$(wp post list --post_type=page --name=herbs --field=ID --path=/var/www/html) --post_type=page --format=table --fields=ID,post_title,post_status --path=/var/www/html
```
Then pick option A or B based on findings and implement.

**Verify:** `/wp-json/bb/v1/herbs` should return > 0 records with fields matching v1.0.3 shape (id, title, slug, content, featured_image, acf_fields, taxonomies, meta). There should be ~10-30 herbs based on the existing bearblend.com navigation.

## General guidelines

- Keep v1.0.3 behavior 100% backward compatible — no breaking changes.
- Bump plugin version header to `1.1.1` after shipping fixes.
- Update the admin settings page's endpoint table if endpoint shapes change.
- Don't add new endpoints — this is a fix-up pass.
- Run each endpoint through curl against live before marking done.
