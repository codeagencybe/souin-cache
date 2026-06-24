<?php
/**
 * Plugin Name: Cloudflare Edge Cache Purge
 * Description: On WordPress/WooCommerce content changes, purges affected URLs from the
 *              Cloudflare edge cache. Complements souin-cache-warmer.php which handles
 *              the Souin (server-side) layer. Surgical: only the changed page + related
 *              archives/categories/shop are purged, not the full site.
 * Version:     1.0.0
 * Author:      Code Agency
 *
 * Required env var (injected from k8s secret 'cloudflare-cache-purge', key 'api-token'):
 *   CF_API_TOKEN — Cloudflare API token with Cache Purge permission for the bescards.com zone
 *
 * Zone ID and domains are hardcoded for bescards.com. The plugin silently no-ops when
 * CF_API_TOKEN is absent so pods start without the secret during development or staging.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'BESCARDS_CF_ZONE_ID' ) ) {
    define( 'BESCARDS_CF_ZONE_ID', '44fd476294f8534f5af17a00cfef1b4b' );
}

if ( ! defined( 'BESCARDS_CF_DOMAINS' ) ) {
    define( 'BESCARDS_CF_DOMAINS', [
        'https://bescards.com',
        'https://www.bescards.com',
        'https://bescards.nl',
        'https://www.bescards.nl',
    ] );
}

/**
 * Purge specific URL paths from the CF edge cache.
 * Expands each path across all bescards domains and deduplicates within the request.
 */
function _bescards_cf_purge_paths( array $urls ): void {
    static $purged = [];
    $token = getenv( 'CF_API_TOKEN' );
    if ( ! $token || empty( $urls ) ) {
        return;
    }

    $fresh = array_values( array_filter(
        array_unique( $urls ),
        static fn( $u ) => ! empty( $u ) && ! isset( $purged[ $u ] )
    ) );

    if ( empty( $fresh ) ) {
        return;
    }

    foreach ( $fresh as $u ) {
        $purged[ $u ] = true;
    }

    $files = [];
    foreach ( $fresh as $url ) {
        $path = parse_url( $url, PHP_URL_PATH ) ?? $url;
        foreach ( BESCARDS_CF_DOMAINS as $domain ) {
            $files[] = $domain . $path;
        }
    }

    $ch = curl_init( 'https://api.cloudflare.com/client/v4/zones/' . BESCARDS_CF_ZONE_ID . '/purge_cache' );
    curl_setopt( $ch, CURLOPT_POST,           true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( [ 'files' => $files ] ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT,        5 );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ] );
    curl_exec( $ch );
    curl_close( $ch );
}

/**
 * Purge every cached URL from CF edge. Used only for site-wide structural changes
 * (theme switch, nav menu, customizer) where every page is potentially stale.
 */
function _bescards_cf_purge_everything(): void {
    static $done = false;
    if ( $done ) {
        return;
    }
    $done  = true;
    $token = getenv( 'CF_API_TOKEN' );
    if ( ! $token ) {
        return;
    }

    $ch = curl_init( 'https://api.cloudflare.com/client/v4/zones/' . BESCARDS_CF_ZONE_ID . '/purge_cache' );
    curl_setopt( $ch, CURLOPT_POST,           true );
    curl_setopt( $ch, CURLOPT_POSTFIELDS,     json_encode( [ 'purge_everything' => true ] ) );
    curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
    curl_setopt( $ch, CURLOPT_TIMEOUT,        5 );
    curl_setopt( $ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ] );
    curl_exec( $ch );
    curl_close( $ch );
}

/**
 * Surgical CF purge for a single post/product.
 * Mirrors the same URL set as _souin_purge_post() in souin-cache-warmer.php:
 * permalink + product categories + shop page + homepage.
 */
function _bescards_cf_purge_post( int $post_id ): void {
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
        $shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;
        if ( $shop_id > 0 ) {
            $shop_url = get_permalink( $shop_id );
            if ( $shop_url ) {
                $urls[] = $shop_url;
            }
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

    _bescards_cf_purge_paths( array_unique( $urls ) );
}

// ── Per-post/product surgical purge ──────────────────────────────────────────

add_action( 'save_post', static function( int $id, \WP_Post $post ): void {
    if ( in_array( $post->post_status, [ 'publish', 'future' ], true ) ) {
        _bescards_cf_purge_post( $id );
    }
}, 10, 2 );

add_action( 'delete_post', static function( int $id ): void {
    _bescards_cf_purge_post( $id );
} );

add_action( 'woocommerce_update_product', '_bescards_cf_purge_post' );

add_action( 'woocommerce_product_set_stock', static function( $product ): void {
    if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
        _bescards_cf_purge_post( (int) $product->get_id() );
    }
} );

add_action( 'woocommerce_product_set_stock_status', static function( $product_id ): void {
    _bescards_cf_purge_post( (int) $product_id );
} );

// ── Full-site structural changes ──────────────────────────────────────────────

add_action( 'wp_update_nav_menu',        '_bescards_cf_purge_everything' );
add_action( 'switch_theme',              '_bescards_cf_purge_everything' );
add_action( 'customize_save_after',      '_bescards_cf_purge_everything' );
add_action( 'activated_plugin',          '_bescards_cf_purge_everything' );
add_action( 'deactivated_plugin',        '_bescards_cf_purge_everything' );
add_action( 'upgrader_process_complete', '_bescards_cf_purge_everything' );
