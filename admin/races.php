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

	private string $page_slug    = 'nw-races';
	private string $menu_parent  = 'neoweaver';

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_action( 'wp_ajax_nw_races_list', [ $this, 'ajax_list' ] );
		add_action( 'wp_ajax_nw_races_save', [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_races_delete', [ $this, 'ajax_delete' ] );
	}
public function register_menu(): void {
add_submenu_page(
    $this->menu_parent,
    'Races',                                                    // page_title
    '<span data-lucide-menu="users-round"></span> Races',            // menu_title
    'manage_options',                                           // capability
    $this->page_slug,                                           // menu_slug
    [ $this, 'render_page' ]                                    // callback
);
	}

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, $this->page_slug ) ) return;
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

	public function ajax_list(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );
		$resp = nw_supa_get( 'cyber_races?select=*&order=name.asc' );
		wp_send_json( $resp );
	}

	public function ajax_save(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );
		$id   = isset( $_POST['id'] ) ? intval( $_POST['id'] ) : 0;
		$data = [
			'name'        => sanitize_text_field( $_POST['name'] ?? '' ),
			'description' => sanitize_textarea_field( $_POST['description'] ?? '' ),
			'tags'        => json_decode( stripslashes( $_POST['tags'] ?? '[]' ) ),
			'is_active'   => filter_var( $_POST['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN ),
		];
		if ( $id ) {
			$resp = nw_supa_patch( 'cyber_races?id=eq.' . $id, $data );
		} else {
			$resp = nw_supa_post( 'cyber_races', $data );
		}
		wp_send_json( $resp );
	}

	public function ajax_delete(): void {
		check_ajax_referer( 'nw_races_nonce', 'nonce' );
		$id   = intval( $_POST['id'] ?? 0 );
		$resp = nw_supa_delete( 'cyber_races?id=eq.' . $id );
		wp_send_json( $resp );
	}
}
