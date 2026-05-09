<?php
/**
 * NeoWeaver Admin Panel — Status Tags (cyber_status_tags)
 *
 * Columns: id, label, category, effect_description, mechanic_modifier,
 *          duration, is_stackable, is_debuff, source, color_hex, is_active
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NeoWeaver_Status_Tags_Admin {

	private string $page_slug   = 'neoweaver-status-tags';
	private string $parent_slug = 'neoweaver';

	/**
	 * Sanitise a UUID / slug ID from user input.
	 * Strips everything except hex digits and hyphens.
	 */
	private function sanitize_uuid( string $raw ): string {
		return preg_replace( '/[^a-f0-9\-]/i', '', sanitize_text_field( $raw ) );
	}

	public function __construct() {
		add_action( 'admin_menu',            [ $this, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );

		add_action( 'wp_ajax_nw_st_get_all', [ $this, 'ajax_get_all' ] );
		add_action( 'wp_ajax_nw_st_save',    [ $this, 'ajax_save' ] );
		add_action( 'wp_ajax_nw_st_toggle',  [ $this, 'ajax_toggle' ] );
		add_action( 'wp_ajax_nw_st_delete',  [ $this, 'ajax_delete' ] );
	}
