<?php
/**
 * Plugin Name: Souin Cache Warmer (must-use companion)
 * Description: On WordPress content changes, PURGE the entire Souin response
 *              cache via its management API and push a marker to a Redis list
 *              so an external warmer can repopulate the cache by crawling the
 *              sitemap. Companion to the Souin Cache Purger plugin.
 * Version:     1.0.0
 * Author:      Code Agency
 * Author URI:  https://codeagency.be
 *
 * Installation: drop this file into wp-content/mu-plugins/. mu-plugins are
 * auto-loaded by WordPress and cannot be deactivated from the admin UI.
 *
 * --------------------------------------------------------------------------
 * Why a separate mu-plugin instead of putting this in the regular plugin?
 * --------------------------------------------------------------------------
 *
 * The regular souin-cache plugin does *surgical* invalidation: it deletes
 * Redis keys for the URLs related to the post that changed (permalink,
 * archives, feed, etc.). That works well when Souin's own TTL is short and
 * gradual cache miss is acceptable.
 *
 * This mu-plugin implements an alternative strategy: long Souin TTL (days /
 * weeks) and full-cache invalidation on every content change, paired with an
 * external warmer that re-populates the cache from the sitemap. The cache is
 * effectively "always-warm" — visitors never see a cold page after a cache
 * eviction, only after deliberate purges.
 *
 * The two strategies can also coexist (defense in depth). The regular plugin
 * deletes specific URL keys directly via Redis SCAN+DEL; this mu-plugin
 * additionally calls Souin's management API which knows about Souin-specific
 * key shapes (IDX_*, vary headers, response/request key pairs).
 *
 * --------------------------------------------------------------------------
 * Configuration (set in wp-config.php BEFORE wp-settings.php is included)
 * --------------------------------------------------------------------------
 *
 *   define( 'WP_REDIS_HOST',     'redis.example.com' );      // required
 *   define( 'WP_REDIS_PASSWORD', 'your-password-here' );     // required
 *   define( 'WP_REDIS_PORT',     6379 );                     // optional
 *   define( 'SOUIN_API_URL',
 *       'http://127.0.0.1/souin-api/souin/?path=.%2B' );     // optional, default shown
 *   define( 'SOUIN_WARMER_QUEUE', 'wp:cache-warm-queue' );   // optional, default shown
 *
 * --------------------------------------------------------------------------
 * Caddyfile prerequisites
 * --------------------------------------------------------------------------
 *
 *   - Souin's management API must be mounted (typically at /souin-api/*).
 *   - The API must be firewalled to 127.0.0.1 so it cannot be invoked from
 *     the public internet (anyone could PURGE your entire cache otherwise).
 *     This mu-plugin runs inside the same pod/host as Caddy, so a localhost
 *     call works.
 *
 * --------------------------------------------------------------------------
 * Notes
 * --------------------------------------------------------------------------
 *
 *   - wp_cache_flush is NOT hooked. The Redis object-cache drop-in (used by
 *     plugins like redis-cache) overrides wp_cache_flush() at the function
 *     level, which means the action hook never fires. Hooking it here would
 *     be a no-op.
 *   - The static $done guard deduplicates within a single request so bulk
 *     imports / mass updates only trigger one PURGE+RPUSH.
 *   - All errors are swallowed silently — cache warming should never break
 *     a visitor request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_REDIS_HOST' ) || ! defined( 'WP_REDIS_PASSWORD' ) ) {
	return;
}

if ( ! defined( 'SOUIN_API_URL' ) ) {
	define( 'SOUIN_API_URL', 'http://127.0.0.1/souin-api/souin/?path=.%2B' );
}

if ( ! defined( 'SOUIN_WARMER_QUEUE' ) ) {
	define( 'SOUIN_WARMER_QUEUE', 'wp:cache-warm-queue' );
}

function _souin_cache_purge_all(): void {
	$ch = curl_init( SOUIN_API_URL );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'PURGE' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_TIMEOUT,        2 );
	curl_setopt( $ch, CURLOPT_NOBODY,         true );
	curl_exec( $ch );
	curl_close( $ch );
}

function _souin_cache_warmer_enqueue(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	_souin_cache_purge_all();

	try {
		$r = new Redis();
		$r->connect( WP_REDIS_HOST, defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379 );
		$r->auth( WP_REDIS_PASSWORD );
		// Only enqueue if nothing is already pending — deduplicates bursts.
		if ( (int) $r->lLen( SOUIN_WARMER_QUEUE ) === 0 ) {
			$r->rPush( SOUIN_WARMER_QUEUE, (string) time() );
			$r->expire( SOUIN_WARMER_QUEUE, 3600 );
		}
	} catch ( \Throwable $e ) {
		// Never break a visitor request over cache warming.
	}
}

// WordPress core events for ANY post type (post, page, product, etc.)
add_action( 'save_post', static function ( int $id, \WP_Post $post ): void {
	if ( in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
		_souin_cache_warmer_enqueue();
	}
}, 10, 2 );
add_action( 'delete_post',          '_souin_cache_warmer_enqueue' );
add_action( 'wp_update_nav_menu',   '_souin_cache_warmer_enqueue' );
add_action( 'switch_theme',         '_souin_cache_warmer_enqueue' );
add_action( 'customize_save_after', '_souin_cache_warmer_enqueue' );

// WooCommerce stock and product changes.
add_action( 'woocommerce_update_product',            '_souin_cache_warmer_enqueue' );
add_action( 'woocommerce_product_set_stock',         '_souin_cache_warmer_enqueue' );
add_action( 'woocommerce_product_set_stock_status',  '_souin_cache_warmer_enqueue' );
add_action( 'woocommerce_variation_set_stock',       '_souin_cache_warmer_enqueue' );
