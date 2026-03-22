<?php
/**
 * Shortcode: [tale_weaver_character_creator]
 *
 * Renders the 9-step character creation wizard.
 * Extracted from Neoweaver_Public to keep each wizard in its own file.
 *
 * Dependencies (must be loaded before this file):
 *   - tw_supabase_url() / tw_supabase_anon_key()  (supabase-helpers.php)
 *   - neoweaver-char-creator JS/CSS               (enqueued by Neoweaver_Public::enqueue_assets)
 *
 * RENDER-ONLY. The form submits via fetch() to the theme endpoint at
 * {stylesheet_dir}/endpoint/tw-endpoint-character.php.
 *
 * CSS scope : .neoweaver-screen #tw-char-creator  (tw-character-creator.css)
 * JS file   : assets/js/tw-character-creator.js
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_character_creator' ) ) {

	/**
	 * [tale_weaver_character_creator] callback.
	 *
	 * Intentionally a standalone function so it can be registered
	 * independently of Neoweaver_Public.  The shortcode tag is still
	 * registered by Neoweaver_Public::__construct() via
	 * [ $this, 'shortcode_character_creator' ], which now delegates here.
	 *
	 * BUG FIX #8:
	 *   The old selectClass() JS inferred the skill limit from the visible
	 *   <strong> class-name text (hardcoded "PSYCHIC" check). That broke
	 *   whenever a class was renamed in Supabase.
	 *   Fix: loadClasses() stores each class's skill_limit value as a
	 *   data-skill-limit attribute on the radio <input>. selectClass() reads
	 *   that attribute — no string comparison needed.
	 *   The JS change lives in assets/js/tw-character-creator.js.
	 *   This function only passes the nonce and endpoint via wp_localize_script().
	 *
	 * @return string  Rendered HTML wrapped in .neoweaver-screen.
	 */
	function neoweaver_shortcode_character_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce    = wp_create_nonce( 'tw_character_nonce' );
		$endpoint = get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-character.php';

		wp_localize_script(
			'neoweaver-char-creator',
			'twCharCreatorConfig',
			[
				'nonce'    => $nonce,
				'endpoint' => $endpoint,
			]
		);

		// Attribute definitions rendered server-side so the PHP loop stays in PHP.
		$attrs = [
			'body'   => [ 'BODY (STR+CON)',    'Brute force, health pool, heavy lifting.' ],
			'reflex' => [ 'REFLEX (DEX)',       'Speed, evasion, precision aiming.' ],
			'mind'   => [ 'MIND (INT+WIS)',     'Logic, repair, investigation, awareness.' ],
			'spirit' => [ 'SPIRIT (CHA+WILL)', 'Magic power, persuasion, willpower.' ],
		];

		$path = get_stylesheet_directory() . '/templates/partials/character-creator.php';
		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial character-creator.php -->';
		}

		ob_start();
		( static function ( $tw_data, $__path ) {
			extract( [ 'tw_data' => $tw_data ], EXTR_SKIP );
			include $__path;
		} )( [ 'attrs' => $attrs ], $path );
		$html = ob_get_clean() ?: '';

		return '<div class="neoweaver-screen">' . $html . '</div>';
	}
}
