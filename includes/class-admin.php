<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Souin_Cache_Admin {

	public function __construct() {
		add_action( 'admin_menu',         [ $this, 'register_settings_page' ] );
		add_action( 'admin_init',         [ $this, 'register_settings' ] );
		add_action( 'admin_bar_menu',     [ $this, 'add_admin_bar_button' ], 100 );
		add_action( 'admin_post_souin_purge_all', [ $this, 'handle_manual_purge' ] );
	}

	// -------------------------------------------------------------------------
	// Settings page
	// -------------------------------------------------------------------------

	public function register_settings_page() {
		add_options_page(
			'Souin Cache',
			'Souin Cache',
			'manage_options',
			'souin-cache',
			[ $this, 'render_settings_page' ]
		);
	}

	public function register_settings() {
		register_setting( 'souin_cache', 'souin_redis_url', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'dragonfly.dragonfly.svc.cluster.local:6379',
		] );

		register_setting( 'souin_cache', 'souin_redis_password', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		] );

		register_setting( 'souin_cache', 'souin_cache_excludes', [
			'type'              => 'string',
			'sanitize_callback' => [ $this, 'sanitize_excludes' ],
			'default'           => '',
		] );

		add_settings_section( 'souin_cache_main', 'Redis Connection', '__return_false', 'souin-cache' );

		add_settings_field(
			'souin_redis_url',
			'DragonflyDB / Redis host:port',
			function () {
				$value = get_option( 'souin_redis_url', 'dragonfly.dragonfly.svc.cluster.local:6379' );
				echo '<input type="text" name="souin_redis_url" value="' . esc_attr( $value ) . '" class="regular-text" />';
				echo '<p class="description">Host and port of the Redis/DragonflyDB instance used by Souin (e.g. <code>dragonfly.dragonfly.svc.cluster.local:6379</code>).</p>';
			},
			'souin-cache',
			'souin_cache_main'
		);

		add_settings_field(
			'souin_redis_password',
			'DragonflyDB / Redis password',
			function () {
				$value = get_option( 'souin_redis_password', '' );
				echo '<input type="password" name="souin_redis_password" value="' . esc_attr( $value ) . '" class="regular-text" autocomplete="new-password" />';
				echo '<p class="description">Leave empty if no authentication is required.</p>';
			},
			'souin-cache',
			'souin_cache_main'
		);

		add_settings_section( 'souin_cache_excludes_section', 'Cache Exclusions', '__return_false', 'souin-cache' );

		add_settings_field(
			'souin_cache_excludes',
			'Excluded URL paths',
			function () {
				$value = get_option( 'souin_cache_excludes', '' );
				echo '<textarea name="souin_cache_excludes" rows="8" class="large-text code">' . esc_textarea( $value ) . '</textarea>';
				echo '<p class="description">One URL path prefix per line. Requests matching these paths will receive <code>Cache-Control: no-store</code> and will never be cached by Souin.<br>';
				echo 'Example: <code>/winkelwagen/</code><br>';
				echo '<strong>Note:</strong> checkout, cart and my-account pages are already excluded at the server level regardless of this list.</p>';
			},
			'souin-cache',
			'souin_cache_excludes_section'
		);
	}

	public function sanitize_excludes( $val ): string {
		return implode( "\n", array_filter( array_map(
			function ( $line ) {
				$line = trim( $line );
				if ( $line !== '' && $line[0] !== '/' ) {
					$line = '/' . $line;
				}
				return $line;
			},
			explode( "\n", (string) $val )
		) ) );
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$purged = isset( $_GET['souin_purged'] ) && $_GET['souin_purged'] === '1';
		?>
		<div class="wrap">
			<h1>Souin Cache</h1>
			<?php if ( $purged ) : ?>
				<div class="notice notice-success is-dismissible"><p>Cache purged successfully.</p></div>
			<?php endif; ?>
			<p>Purges the Souin HTTP cache on post publish, update, delete, and theme/menu changes. Cache keys are deleted directly from DragonflyDB using the phpredis extension.</p>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'souin_cache' );
				do_settings_sections( 'souin-cache' );
				submit_button( 'Save Settings' );
				?>
			</form>
			<hr />
			<h2>Manual Purge</h2>
			<p>Delete all Souin cache entries for this site from DragonflyDB immediately.</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="souin_purge_all" />
				<?php wp_nonce_field( 'souin_purge_all' ); ?>
				<?php submit_button( 'Purge All Cache', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Admin bar button
	// -------------------------------------------------------------------------

	public function add_admin_bar_button( WP_Admin_Bar $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->add_node( [
			'id'    => 'souin-purge-all',
			'title' => '🗑 Purge Cache',
			'href'  => wp_nonce_url(
				admin_url( 'admin-post.php?action=souin_purge_all' ),
				'souin_purge_all'
			),
		] );
	}

	// -------------------------------------------------------------------------
	// Manual purge handler
	// -------------------------------------------------------------------------

	public function handle_manual_purge() {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'souin_purge_all' ) ) {
			wp_die( 'Unauthorized', 403 );
		}

		( new Souin_Cache_Purger() )->purge_all();

		wp_redirect( add_query_arg( 'souin_purged', '1', wp_get_referer() ?: admin_url() ) );
		exit;
	}
}
