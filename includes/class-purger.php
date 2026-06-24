<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Souin_Cache_Purger {

	public function __construct() {
		// Standard post lifecycle
		add_action( 'save_post',              [ $this, 'on_post_save' ], 10, 2 );
		add_action( 'deleted_post',           [ $this, 'on_post_save' ], 10, 2 );
		add_action( 'trashed_post',           [ $this, 'on_post_save' ] );
		add_action( 'untrashed_post',         [ $this, 'on_post_save' ] );

		// Terms (category/tag page URLs change)
		add_action( 'edited_term',            [ $this, 'purge_all' ] );
		add_action( 'delete_term',            [ $this, 'purge_all' ] );

		// Theme / widget / menu changes
		add_action( 'switch_theme',           [ $this, 'purge_all' ] );
		add_action( 'customize_save_after',   [ $this, 'purge_all' ] );
		add_action( 'wp_update_nav_menu',     [ $this, 'purge_all' ] );

		// WooCommerce
		add_action( 'woocommerce_product_set_stock',     [ $this, 'on_woo_product' ] );
		add_action( 'woocommerce_variation_set_stock',   [ $this, 'on_woo_product' ] );
		add_action( 'woocommerce_product_object_updated_props', [ $this, 'on_woo_product' ] );
	}

	// -------------------------------------------------------------------------
	// Event handlers
	// -------------------------------------------------------------------------

	public function on_post_save( $post_id, $post = null ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = $post instanceof WP_Post ? $post : get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_status, [ 'publish', 'trash', 'private' ], true ) ) {
			return;
		}

		$urls = $this->get_post_related_urls( $post );
		foreach ( $urls as $url ) {
			$this->purge_url( $url );
		}

		$this->cf_purge_urls( $urls );
	}

	public function on_woo_product( $product ) {
		$post_id = is_object( $product ) ? $product->get_id() : (int) $product;
		if ( $post_id ) {
			$this->on_post_save( $post_id );
		}
	}

	public function purge_all() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		// Souin keys: GET-http-{host}-{path}, HEAD-http-{host}-{path}, IDX_*-http-{host}-{path}
		$this->redis_scan_delete( '*-http-' . $host . '-*' );

		$this->cf_purge_all();
	}

	// -------------------------------------------------------------------------
	// URL collection
	// -------------------------------------------------------------------------

	private function get_post_related_urls( WP_Post $post ): array {
		$urls = [];

		$permalink = get_permalink( $post->ID );
		if ( $permalink ) {
			$urls[] = $permalink;
			$urls[] = trailingslashit( $permalink ) . 'amp/';
		}

		$urls[] = home_url( '/' );
		$urls[] = home_url( '/feed/' );
		$urls[] = home_url( '/page/' );

		$author_url = get_author_posts_url( $post->post_author );
		if ( $author_url ) {
			$urls[] = $author_url;
		}

		foreach ( wp_get_post_categories( $post->ID ) as $cat_id ) {
			$cat_url = get_category_link( $cat_id );
			if ( $cat_url ) {
				$urls[] = $cat_url;
				$urls[] = trailingslashit( $cat_url ) . 'feed/';
			}
		}

		foreach ( wp_get_post_tags( $post->ID ) as $tag ) {
			$tag_url = get_tag_link( $tag->term_id );
			if ( $tag_url ) {
				$urls[] = $tag_url;
			}
		}

		$year  = get_the_date( 'Y', $post );
		$month = get_the_date( 'm', $post );
		if ( $year ) {
			$urls[] = get_year_link( $year );
			$urls[] = get_month_link( $year, $month );
		}

		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$urls[] = wc_get_page_permalink( 'shop' );
			foreach ( wc_get_product_terms( $post->ID, 'product_cat', [ 'fields' => 'ids' ] ) as $cat_id ) {
				$urls[] = get_term_link( $cat_id, 'product_cat' );
			}
		}

		return array_filter( array_unique( $urls ) );
	}

	// -------------------------------------------------------------------------
	// Redis-based purge (direct DragonflyDB key deletion)
	// -------------------------------------------------------------------------

	/**
	 * Purges a specific URL by deleting all matching Souin cache keys from Redis.
	 * Souin stores keys as: {METHOD}-http-{host}-{path}
	 */
	public function purge_url( string $url ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$path = str_starts_with( $url, 'http' )
			? ( wp_parse_url( $url, PHP_URL_PATH ) ?? '/' )
			: $url;

		// Escape Redis glob special chars in path ([ ] ? * \)
		$safe_path = strtr( $path, [ '[' => '\[', ']' => '\]', '?' => '\?', '\\' => '\\\\' ] );

		// Match both the response entry and its IDX_ sibling for every method variant
		$this->redis_scan_delete( '*-http-' . $host . '-' . $safe_path . '*' );
	}

	/**
	 * Scans DragonflyDB for keys matching $pattern and deletes them in batches.
	 */
	private function redis_scan_delete( string $pattern ) {
		if ( ! class_exists( 'Redis' ) ) {
			error_log( '[Souin Cache] phpredis extension not available' );
			return;
		}

		[ $host, $port, $password ] = $this->get_redis_config();

		try {
			$redis = new Redis();
			if ( ! $redis->connect( $host, $port, 2.0 ) ) {
				error_log( '[Souin Cache] Redis connect failed (' . $host . ':' . $port . ')' );
				return;
			}
			if ( $password !== '' ) {
				$redis->auth( $password );
			}
			$redis->setOption( Redis::OPT_SCAN, Redis::SCAN_RETRY );

			$cursor = null;
			while ( $keys = $redis->scan( $cursor, $pattern, 200 ) ) {
				$redis->del( $keys );
			}

			$redis->close();
		} catch ( \Exception $e ) {
			error_log( '[Souin Cache] Redis scan/delete failed (' . $pattern . '): ' . $e->getMessage() );
		}
	}

	/**
	 * Returns [host, port, password] from the souin_redis_url option.
	 * Expected format: host:port — password is stored separately in souin_redis_password.
	 */
	private function get_redis_config(): array {
		$url      = get_option( 'souin_redis_url', 'dragonfly.dragonfly.svc.cluster.local:6379' );
		$password = get_option( 'souin_redis_password', '' );

		$parts = explode( ':', $url, 2 );
		return [
			$parts[0] ?? 'dragonfly.dragonfly.svc.cluster.local',
			(int) ( $parts[1] ?? 6379 ),
			(string) $password,
		];
	}

	// -------------------------------------------------------------------------
	// Cloudflare edge cache purge
	// -------------------------------------------------------------------------

	/**
	 * Purges specific absolute URLs from Cloudflare's edge cache.
	 * CF accepts a maximum of 30 URLs per API request.
	 * No-ops when CF integration is disabled or credentials are missing.
	 */
	public function cf_purge_urls( array $urls ): void {
		if ( ! get_option( 'souin_cf_enabled' ) ) {
			return;
		}

		$zone_id = (string) get_option( 'souin_cf_zone_id', '' );
		$token   = (string) get_option( 'souin_cf_api_token', '' );

		if ( $zone_id === '' || $token === '' ) {
			return;
		}

		$urls = array_values( array_filter( $urls, static fn( $u ) => str_starts_with( $u, 'http' ) ) );

		if ( empty( $urls ) ) {
			return;
		}

		foreach ( array_chunk( $urls, 30 ) as $chunk ) {
			$this->cf_api_request( $zone_id, $token, [ 'files' => $chunk ] );
		}
	}

	/**
	 * Purges the entire Cloudflare edge cache for this zone.
	 * Only used for site-wide changes (theme switch, nav menu update, etc.).
	 */
	public function cf_purge_all(): void {
		if ( ! get_option( 'souin_cf_enabled' ) ) {
			return;
		}

		$zone_id = (string) get_option( 'souin_cf_zone_id', '' );
		$token   = (string) get_option( 'souin_cf_api_token', '' );

		if ( $zone_id === '' || $token === '' ) {
			return;
		}

		$this->cf_api_request( $zone_id, $token, [ 'purge_everything' => true ] );
	}

	/**
	 * Sends a POST request to the Cloudflare Cache Purge API.
	 * Times out after 5 seconds — never blocks a visitor request.
	 */
	private function cf_api_request( string $zone_id, string $token, array $body ): void {
		$ch = curl_init( 'https://api.cloudflare.com/client/v4/zones/' . $zone_id . '/purge_cache' );
		curl_setopt_array( $ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_TIMEOUT        => 5,
			CURLOPT_HTTPHEADER     => [
				'Authorization: Bearer ' . $token,
				'Content-Type: application/json',
			],
			CURLOPT_POSTFIELDS => wp_json_encode( $body ),
		] );
		$response = curl_exec( $ch );
		$errno    = curl_errno( $ch );
		curl_close( $ch );

		if ( $errno ) {
			error_log( '[Souin Cache] CF purge curl error ' . $errno );
		} elseif ( $response ) {
			$decoded = json_decode( $response, true );
			if ( isset( $decoded['success'] ) && ! $decoded['success'] ) {
				$msg = wp_json_encode( $decoded['errors'] ?? [] );
				error_log( '[Souin Cache] CF purge API error: ' . $msg );
			}
		}
	}
}