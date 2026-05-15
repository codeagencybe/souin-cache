<?php
/**
 * Plugin Name: Souin Cache Invalidator
 * Description: On WordPress/WooCommerce content changes, surgically DELETE the
 *              affected URLs from Souin's cache via its admin API (port 2019).
 *              Only the updated page + its archives/categories are cleared —
 *              the full cache is never wiped, so unrelated pages stay warm.
 *              Also purges Cloudflare edge cache when CF integration is enabled.
 * Version:     2.1.0
 * Author:      Code Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'SOUIN_ADMIN_URL' ) ) {
    define( 'SOUIN_ADMIN_URL', 'http://127.0.0.1:2019/souin-api/souin' );
}

if ( ! defined( 'SOUIN_WARMER_QUEUE' ) ) {
    define( 'SOUIN_WARMER_QUEUE', 'wp:cache-warm-queue' );
}

/**
 * DELETE one or more URL paths from Souin using a regex pattern.
 * Uses the Caddy admin API on port 2019 — never touches port 80.
 */
function _souin_delete_paths( array $urls ): void {
    if ( empty( $urls ) ) {
        return;
    }

    $paths = array_filter( array_map( static function ( $url ) {
        $p = parse_url( $url, PHP_URL_PATH );
        return $p ? preg_quote( $p, '/' ) : null;
    }, $urls ) );

    if ( empty( $paths ) ) {
        return;
    }

    $pattern = count( $paths ) === 1
        ? '^' . reset( $paths ) . '($|\?)'
        : '^(' . implode( '|', $paths ) . ')($|\?)';

    $ch = curl_init( SOUIN_ADMIN_URL . '?path=' . rawurlencode( $pattern ) );
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 3 );
    curl_exec( $ch );
    curl_close( $ch );
}

/**
 * Purge ALL cached entries from Souin. Used only for site-wide changes
 * (theme switch, plugin update, bulk import).
 */
function _souin_delete_all(): void {
    $ch = curl_init( SOUIN_ADMIN_URL . '?path=.%2B' );
    curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, 'DELETE' );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT, 3 );
    curl_exec( $ch );
    curl_close( $ch );
}

/**
 * Enqueue the cache warmer for a full site re-warm.
 * Only used after full purges (plugin/theme changes).
 */
function _souin_enqueue_warmer(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done = true;

    if ( ! defined( 'WP_REDIS_HOST' ) || ! defined( 'WP_REDIS_PASSWORD' ) ) {
        return;
    }

    try {
        $r = new Redis();
        $r->connect( WP_REDIS_HOST, defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : 6379 );
        $r->auth( WP_REDIS_PASSWORD );
        if ( (int) $r->lLen( SOUIN_WARMER_QUEUE ) === 0 ) {
            $r->rPush( SOUIN_WARMER_QUEUE, (string) time() );
            $r->expire( SOUIN_WARMER_QUEUE, 3600 );
        }
    } catch ( \Throwable $e ) {
        // Never break a visitor request over cache warming.
    }
}

// ── Cloudflare edge cache purge ───────────────────────────────────────────────

/**
 * Purge specific absolute URLs from Cloudflare's edge cache.
 * Reads souin_cf_enabled / souin_cf_zone_id / souin_cf_api_token from WP options.
 * No-ops when CF integration is disabled or credentials are missing.
 */
function _souin_cf_purge_urls( array $urls ): void {
    if ( ! function_exists( 'get_option' ) || ! get_option( 'souin_cf_enabled' ) ) {
        return;
    }

    $zone_id = (string) get_option( 'souin_cf_zone_id', '' );
    $token   = (string) get_option( 'souin_cf_api_token', '' );

    if ( $zone_id === '' || $token === '' ) {
        return;
    }

    $urls = array_values( array_filter( $urls, static fn( $u ) => str_starts_with( (string) $u, 'http' ) ) );
    if ( empty( $urls ) ) {
        return;
    }

    foreach ( array_chunk( $urls, 30 ) as $chunk ) {
        _souin_cf_api_request( $zone_id, $token, [ 'files' => $chunk ] );
    }
}

/**
 * Purge the entire Cloudflare edge cache for this zone.
 * Only for site-wide changes — never call on single product updates.
 */
function _souin_cf_purge_all(): void {
    if ( ! function_exists( 'get_option' ) || ! get_option( 'souin_cf_enabled' ) ) {
        return;
    }

    $zone_id = (string) get_option( 'souin_cf_zone_id', '' );
    $token   = (string) get_option( 'souin_cf_api_token', '' );

    if ( $zone_id === '' || $token === '' ) {
        return;
    }

    _souin_cf_api_request( $zone_id, $token, [ 'purge_everything' => true ] );
}

/**
 * Send a POST to the Cloudflare Cache Purge API.
 * 5-second timeout — never blocks a visitor request.
 */
function _souin_cf_api_request( string $zone_id, string $token, array $body ): void {
    $ch = curl_init( 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache' );
    curl_setopt_array( $ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode( $body ),
    ] );
    $response = curl_exec( $ch );
    $errno    = curl_errno( $ch );
    curl_close( $ch );

    if ( $errno ) {
        error_log( '[Souin Cache Warmer] CF purge curl error ' . $errno );
    } elseif ( $response ) {
        $decoded = json_decode( $response, true );
        if ( isset( $decoded['success'] ) && ! $decoded['success'] ) {
            error_log( '[Souin Cache Warmer] CF purge API error: ' . json_encode( $decoded['errors'] ?? [] ) );
        }
    }
}

// ── Per-post/product surgical invalidation ───────────────────────────────────

/**
 * Surgical cache invalidation for a single post/product.
 * Clears the permalink + related archives, categories, shop, and home page
 * from both Souin (port 2019 admin API) and Cloudflare edge (when enabled).
 */
function _souin_purge_post( int $post_id ): void {
    static $purged = [];
    if ( isset( $purged[ $post_id ] ) ) {
        return;
    }
    $purged[ $post_id ] = true;

    $post = get_post( $post_id );
    if ( ! $post || ! in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
        return;
    }

    $urls = [];

    $permalink = get_permalink( $post_id );
    if ( $permalink ) {
        $urls[] = $permalink;
    }

    if ( 'product' === $post->post_type ) {
        $shop_id = wc_get_page_id( 'shop' );
        if ( $shop_id ) {
            $urls[] = get_permalink( $shop_id );
        }

        $cats = get_the_terms( $post_id, 'product_cat' );
        if ( $cats && ! is_wp_error( $cats ) ) {
            foreach ( $cats as $cat ) {
                $cat_link = get_term_link( $cat );
                if ( ! is_wp_error( $cat_link ) ) {
                    $urls[] = $cat_link;
                }
            }
        }
    }

    $urls[] = home_url( '/' );

    $urls = array_unique( $urls );
    _souin_delete_paths( $urls );
    _souin_cf_purge_urls( $urls );
}

add_action( 'save_post', static function ( int $id, \WP_Post $post ): void {
    if ( in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
        _souin_purge_post( $id );
    }
}, 10, 2 );

add_action( 'delete_post', static function ( int $id ): void {
    _souin_purge_post( $id );
} );

add_action( 'woocommerce_update_product', '_souin_purge_post' );
add_action( 'woocommerce_product_set_stock', static function ( $product ): void {
    if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
        _souin_purge_post( $product->get_id() );
    }
} );
add_action( 'woocommerce_product_set_stock_status', static function ( $product_id ): void {
    _souin_purge_post( (int) $product_id );
} );

// ── Full-purge + warmer for site-wide changes ─────────────────────────────────

function _souin_full_purge_and_warm(): void {
    _souin_delete_all();
    _souin_enqueue_warmer();
    _souin_cf_purge_all();
}

add_action( 'wp_update_nav_menu',        '_souin_full_purge_and_warm' );
add_action( 'switch_theme',              '_souin_full_purge_and_warm' );
add_action( 'customize_save_after',      '_souin_full_purge_and_warm' );
add_action( 'activated_plugin',          '_souin_full_purge_and_warm' );
add_action( 'deactivated_plugin',        '_souin_full_purge_and_warm' );
add_action( 'upgrader_process_complete', '_souin_full_purge_and_warm' );