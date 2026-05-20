<?php
/**
 * NeoWeaver asset helpers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_asset_path' ) ) {
	/**
	 * Return absolute plugin path for a relative asset path.
	 */
	function tw_asset_path( string $relative_path ): string {
		return trailingslashit( NEOWEAVER_PLUGIN_DIR ) . ltrim( $relative_path, '/' );
	}
}

if ( ! function_exists( 'tw_asset_url' ) ) {
	/**
	 * Return plugin URL for a relative asset path.
	 */
	function tw_asset_url( string $relative_path ): string {
		return trailingslashit( NEOWEAVER_PLUGIN_URL ) . ltrim( $relative_path, '/' );
	}
}

if ( ! function_exists( 'tw_asset_version' ) ) {
	/**
	 * Return automatic asset version based on file modification time.
	 * Falls back to plugin version when file is missing.
	 */
	function tw_asset_version( string $relative_path ): string {
		$absolute_path = tw_asset_path( $relative_path );

		clearstatcache( true, $absolute_path );

		return file_exists( $absolute_path )
			? (string) filemtime( $absolute_path )
			: NEOWEAVER_VERSION;
	}
}

if ( ! function_exists( 'tw_has_shortcode_on_current_page' ) ) {
	/**
	 * Check whether current singular page contains given shortcode.
	 */
	function tw_has_shortcode_on_current_page( string $shortcode ): bool {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}

		global $post;

		return ( $post instanceof WP_Post )
			&& ! empty( $post->post_content )
			&& has_shortcode( $post->post_content, $shortcode );
	}
}

if ( ! function_exists( 'tw_enqueue_style_asset' ) ) {
	/**
	 * Enqueue frontend or shared stylesheet by relative path.
	 */
	function tw_enqueue_style_asset( string $handle, string $relative_path, array $deps = [], string $media = 'all' ): void {
		wp_enqueue_style(
			$handle,
			tw_asset_url( $relative_path ),
			$deps,
			tw_asset_version( $relative_path ),
			$media
		);
	}
}

if ( ! function_exists( 'tw_enqueue_script_asset' ) ) {
	/**
	 * Enqueue frontend or shared script by relative path.
	 */
	function tw_enqueue_script_asset( string $handle, string $relative_path, array $deps = [], bool $in_footer = true ): void {
		wp_enqueue_script(
			$handle,
			tw_asset_url( $relative_path ),
			$deps,
			tw_asset_version( $relative_path ),
			$in_footer
		);
	}
}

if ( ! function_exists( 'tw_enqueue_admin_style_asset' ) ) {
	/**
	 * Enqueue admin stylesheet by relative path.
	 */
	function tw_enqueue_admin_style_asset( string $handle, string $relative_path, array $deps = [], string $media = 'all' ): void {
		wp_enqueue_style(
			$handle,
			tw_asset_url( $relative_path ),
			$deps,
			tw_asset_version( $relative_path ),
			$media
		);
	}
}

if ( ! function_exists( 'tw_enqueue_admin_script_asset' ) ) {
	/**
	 * Enqueue admin script by relative path.
	 */
	function tw_enqueue_admin_script_asset( string $handle, string $relative_path, array $deps = [], bool $in_footer = true ): void {
		wp_enqueue_script(
			$handle,
			tw_asset_url( $relative_path ),
			$deps,
			tw_asset_version( $relative_path ),
			$in_footer
		);
	}
}

if ( ! function_exists( 'tw_is_admin_screen' ) ) {
	/**
	 * Check current admin screen by exact hook suffix match.
	 *
	 * Example values:
	 * - toplevel_page_neoweaver
	 * - neoweaver_page_neoweaver-skills
	 * - post.php
	 */
	function tw_is_admin_screen( string $hook_suffix, ?string $current_hook = null ): bool {
		if ( ! is_admin() ) {
			return false;
		}

		if ( null !== $current_hook ) {
			return $current_hook === $hook_suffix;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && isset( $screen->id ) && $screen->id === $hook_suffix;
	}
}

if ( ! function_exists( 'tw_is_admin_screen_in' ) ) {
	/**
	 * Check current admin screen against a list of hook suffixes.
	 */
	function tw_is_admin_screen_in( array $hook_suffixes, ?string $current_hook = null ): bool {
		foreach ( $hook_suffixes as $hook_suffix ) {
			if ( tw_is_admin_screen( (string) $hook_suffix, $current_hook ) ) {
				return true;
			}
		}

		return false;
	}
}
