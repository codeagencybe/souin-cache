<?php
/**
 * Plugin Name: Bescards Cache Warm Notifier
 * Description: Purges Souin's response cache and triggers a sitemap re-warm
 *              on every WordPress content change so visitors always see
 *              fresh content with cached load times.
 * Version:     1.0.0
 * Author:      Code Agency / Bescards
 *
 * Installation: drop this file into wp-content/mu-plugins/.
 *               It auto-loads — no activation needed.
 *
 * Companion to the souin-cache plugin. Where souin-cache exposes Souin
 * configuration in the WP admin, this mu-plugin handles the runtime
 * cache invalidation lifecycle:
 *
 *   1. PURGE Souin's response cache via the management API on every
 *      content change. Without this, the warmer below would hit Souin's
 *      still-fresh cache and return OLD HTML — never refreshing.
 *   2. Push a marker to wp:cache-warm-queue on Redis (DragonflyDB).
 *      KEDA's wp-cache-warmer ScaledJob picks it up within ~15s and
 *      crawls the sitemap to repopulate the cache.
 *
 * Caddyfile assumptions:
 *   - Souin management API mounted at /souin-api/* and firewalled to
 *     localhost only (see @souin_api_external block in Caddyfile).
 *     This mu-plugin runs inside the same pod as Caddy/Souin, so the
 *     localhost call works.
 *   - Souin's `ttl` is set high (168h / 7d) so the cache is effectively
 *     immortal until this purge fires.
 *
 * Notes:
 *   - wp_cache_flush is NOT hooked — the Redis object-cache drop-in
 *     overrides wp_cache_flush() at the function level without firing
 *     the action hook, so it would be a no-op here.
 *   - The static $done guard deduplicates within a single request so
 *     bulk imports / mass updates only trigger one PURGE+RPUSH.
 */

if ( ! defined( 'WP_REDIS_HOST' ) || ! defined( 'WP_REDIS_PASSWORD' ) ) {
    return;
}

function _bescards_purge_souin_cache(): void {
    // Souin's management API is firewalled to localhost in the Caddyfile,
    // so we MUST call it from inside the same pod. The path=.+ regex
    // matches every cached entry, dumping all pages on every event.
    $ch = curl_init( 'http://127.0.0.1/souin-api/souin/?path=.%2B' );
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PURGE' );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT,        2 );
    curl_setopt( $ch, CURLOPT_NOBODY,         true );
    curl_exec( $ch );
    curl_close( $ch );
}

function _bescards_queue_cache_warm(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;

    // 1. Purge Souin's response cache so warmer hits backend.
    _bescards_purge_souin_cache();

    // 2. Tell the KEDA-driven warmer to crawl the sitemap.
    try {
        $r = new Redis();
        $r->connect( WP_REDIS_HOST, defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379 );
        $r->auth( WP_REDIS_PASSWORD );
        // Only enqueue if nothing is already pending — deduplicates bursts.
        if ( (int) $r->lLen( 'wp:cache-warm-queue' ) === 0 ) {
            $r->rPush( 'wp:cache-warm-queue', (string) time() );
            $r->expire( 'wp:cache-warm-queue', 3600 );
        }
    } catch ( \Throwable $e ) {
        // Never break a visitor request over cache warming.
    }
}

// WordPress core events for ANY post type (post, page, product, etc.)
add_action( 'save_post', static function ( int $id, \WP_Post $post ): void {
    if ( in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
        _bescards_queue_cache_warm();
    }
}, 10, 2 );
add_action( 'delete_post',          '_bescards_queue_cache_warm' );
add_action( 'wp_update_nav_menu',   '_bescards_queue_cache_warm' );
add_action( 'switch_theme',         '_bescards_queue_cache_warm' );
add_action( 'customize_save_after', '_bescards_queue_cache_warm' );

// WooCommerce stock and product changes.
add_action( 'woocommerce_update_product',            '_bescards_queue_cache_warm' );
add_action( 'woocommerce_product_set_stock',         '_bescards_queue_cache_warm' );
add_action( 'woocommerce_product_set_stock_status',  '_bescards_queue_cache_warm' );
add_action( 'woocommerce_variation_set_stock',       '_bescards_queue_cache_warm' );
