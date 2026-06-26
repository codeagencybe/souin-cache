<?php
/**
 * Plugin Name: Bescards Script Defer — Fix Blocking Head Scripts
 * Description: Fixes render-blocking scripts that delay First Contentful Paint.
 *
 *   Problem 1 — Trustpilot plugin bug: the plugin calls wp_script_add_data() with
 *   'async' => true using an older API that embeds the attribute as a URL query
 *   param (?ver=1.0' async='async) instead of an HTML attribute. The browser then
 *   loads these scripts synchronously — including widget.trustpilot.com which
 *   requires DNS + TCP + TLS to an external domain, blocking the page by 2-5 s.
 *   Fix: deregister the broken plugin-registered external bootstrap and fix the
 *   local Trustpilot scripts via the script_loader_tag filter.
 *
 *   Problem 2 — Woodmart theme & WPBakery load small utility scripts in <head>
 *   without defer: device.min.js, scrollBar.min.js, woocommerce-add-to-cart.js.
 *   None of these are dependencies of inline scripts; deferring them shifts the
 *   download off the critical rendering path.
 *
 * Version: 1.0.0
 * Author:  Code Agency
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── 1. Fix Trustpilot broken async scripts ───────────────────────────────────
// The Trustpilot plugin registers widget.trustpilot.com/bootstrap as a blocking
// external script. The same script is already added by Trustpilot's own embed
// code as a correctly-async inline tag, so deregistering the plugin's version
// eliminates the blocking external request without losing widget functionality.
add_action( 'wp_enqueue_scripts', static function (): void {
    wp_deregister_script( 'widget-bootstrap' );
}, 100 );

// Fix the remaining local Trustpilot scripts (headerScript, trustBoxScript) that
// have the broken async-in-URL bug. The filter replaces the malformed src
// attribute with a clean URL and adds a proper async attribute.
add_filter( 'script_loader_tag', static function ( string $tag, string $handle ): string {
    static $tp_handles = [ 'tp-js', 'trustbox' ];

    if ( ! in_array( $handle, $tp_handles, true ) ) {
        return $tag;
    }

    // Strip the mangled ?ver=…' async='async suffix from the src URL and add
    // a proper async attribute so the script never blocks rendering.
    $tag = preg_replace(
        '/\s+src=(["\'])([^"\']+)\'\s+async=\'async(["\'])/i',
        ' async src=$1$2$1',
        $tag
    );

    return $tag;
}, 10, 2 );

// ── 2. Defer Woodmart & WPBakery head scripts ────────────────────────────────
// These scripts have no dependents that use inline jQuery(document).ready(),
// so adding defer is safe. They move off the critical path while still
// executing before DOMContentLoaded (consistent with how defer behaves).
add_filter( 'script_loader_tag', static function ( string $tag, string $handle ): string {
    static $defer_handles = [
        'vc_woocommerce-add-to-cart-js',  // WPBakery 1 KB — no inline dependents
        'wd-device-library',               // Woodmart device detection 3 KB
        'wd-scrollbar',                    // Woodmart scrollbar 540 B
    ];

    if ( ! in_array( $handle, $defer_handles, true ) ) {
        return $tag;
    }

    // Only add defer if not already present.
    if ( str_contains( $tag, ' defer' ) ) {
        return $tag;
    }

    return str_replace( '<script ', '<script defer ', $tag );
}, 10, 2 );
