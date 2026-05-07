<?php
/**
 * Plugin Name:  Souin Cache Purger
 * Description:  Purges Souin HTTP cache on WordPress content updates. Sends HTTP PURGE requests to the Souin middleware running in the same FrankenPHP pod.
 * Version:      1.0.0
 * Author:       Code Agency
 * Author URI:   https://codeagency.be
 * License:      GPL-2.0+
 * Text Domain:  souin-cache
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SOUIN_CACHE_VERSION', '1.0.0' );
define( 'SOUIN_CACHE_DIR', plugin_dir_path( __FILE__ ) );

require_once SOUIN_CACHE_DIR . 'includes/class-purger.php';
require_once SOUIN_CACHE_DIR . 'includes/class-admin.php';

add_action( 'plugins_loaded', function () {
	new Souin_Cache_Admin();
	new Souin_Cache_Purger();
} );

// Send Cache-Control: no-store for admin-configured excluded URL paths.
add_action( 'template_redirect', function () {
	$raw = (string) get_option( 'souin_cache_excludes', '' );
	$patterns = array_filter( array_map( 'trim', explode( "\n", $raw ) ) );
	if ( empty( $patterns ) ) {
		return;
	}
	$path = '/' . ltrim( parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ?? '', '/' );
	foreach ( $patterns as $pattern ) {
		if ( str_starts_with( $path, $pattern ) ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			return;
		}
	}
}, 1 );
