<?php
/**
 * NeoWeaver Admin — Races
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NWRacesAdmin {

	private string $page_slug   = 'nw-races';
	private string $menu_parent = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',              [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_races_list',   [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_races_save',   [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_races_delete', [ $this, 'ajax_delete' ] );
		add_action( 'wp_ajax_nw_races_duplicate', [ $this, 'ajax_duplicate' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			$this->menu_parent,
			'Races',
			'<span data-lucide-menu="users-round"></span> Races',
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ]
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) {
			return;
		}
		wp_enqueue_style( 'nw-admin-core' );
		wp_enqueue_style( 'nw-admin-races', NW_PLUGIN_URL . 'assets/css/admin/races.css', [], NW_VERSION );
		wp_enqueue_script( 'nw-lucide' );
		wp_enqueue_script( 'nw-admin-races', NW_PLUGIN_URL . 'assets/js/admin/races.js', [ 'nw-lucide' ], NW_VERSION, true );
		wp_localize_script( 'nw-admin-races', 'NWRaces', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'nw_races_nonce' ),
		] );
	}

	public function render_page(): void {
		echo '<div class="wrap nw-admin-wrap" id="nw-races-app"></div>';
	}

	// -------------------------------------------------------------------------
	// AJAX handlers
	// -------------------------------------------------------------------------

	public function ajax_list(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_get_admin' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$rows = tw_supabase_get_admin( 'cyber_races', [ 'order' => 'name.asc' ] );

		if ( is_wp_error( $rows ) ) {
			wp_send_json_error( $rows->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( $rows );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$data = [
			'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
			'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'tags'        => json_decode( stripslashes( $_POST['tags'] ?? '[]' ) ),
			'is_active'   => filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];

		$endpoint = $id ? 'cyber_races?id=eq.' . $id : 'cyber_races';
		$method   = $id ? 'PATCH' : 'POST';

		$resp = tw_supabase_request( $method, $endpoint, $data );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = intval( $_POST['id'] ?? 0 );
		$resp = tw_supabase_request( 'DELETE', 'cyber_races?id=eq.' . $id );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}

	public function ajax_duplicate(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );

		if ( ! function_exists( 'tw_supabase_get_admin' ) || ! function_exists( 'tw_supabase_request' ) ) {
			wp_send_json_error( 'Supabase helpers not loaded.', 500 );
			return;
		}

		$id   = intval( $_POST['id'] ?? 0 );
		$rows = tw_supabase_get_admin( 'cyber_races', [ 'id' => 'eq.' . $id ] );

		if ( is_wp_error( $rows ) || empty( $rows[0] ) ) {
			wp_send_json_error( 'Race not found.' );
			return;
		}

		$row = $rows[0];
		unset( $row['id'], $row['created_at'], $row['updated_at'] );
		$row['name']      = $row['name'] . ' (copy)';
		$row['is_active'] = false;

		$resp = tw_supabase_request( 'POST', 'cyber_races', $row );

		if ( is_wp_error( $resp ) ) {
			wp_send_json_error( $resp->get_error_message(), 500 );
			return;
		}

		wp_send_json_success( true );
	}
}
