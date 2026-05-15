<?php
/**
 * Plugin Name: Souin Cache Invalidator
 * Description: On WordPress/WooCommerce content changes, surgically DELETE the
 *              affected URLs from Souin's cache via its admin API (port 2019).
 *              Only the updated page + its archives/categories are cleared —
 *              the full cache is never wiped, so unrelated pages stay warm.
 * Version:     2.0.0
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
 * Purge ALL cached entries. Used only for site-wide changes
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

/**
 * Surgical cache invalidation for a single post/product.
 * Clears the permalink + related archives, categories, shop, and home page.
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

    _souin_delete_paths( array_unique( $urls ) );
}

// ── Per-post/product surgical invalidation ───────────────────────────────────

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
}

add_action( 'wp_update_nav_menu',        '_souin_full_purge_and_warm' );
add_action( 'switch_theme',              '_souin_full_purge_and_warm' );
add_action( 'customize_save_after',      '_souin_full_purge_and_warm' );
add_action( 'activated_plugin',          '_souin_full_purge_and_warm' );
add_action( 'deactivated_plugin',        '_souin_full_purge_and_warm' );
add_action( 'upgrader_process_complete', '_souin_full_purge_and_warm' );
