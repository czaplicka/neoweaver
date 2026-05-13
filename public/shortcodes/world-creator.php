<?php
/**
 * Shortcode: [tw_world_creator]
 *
 * Renders the 11-step Node (World) creation wizard.
 * Extracted from Neoweaver_Public to keep each wizard in its own file.
 *
 * Dependencies (must be loaded before this file):
 *   - tw_supabase_url() / tw_supabase_anon_key()  (supabase-helpers.php)
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_world_creator' ) ) {

	function neoweaver_shortcode_world_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce       = wp_create_nonce( 'tw_world_nonce' );
		$nodes_url   = home_url( '/nodes/' );
		$uploads_url = wp_upload_dir()['baseurl'];

		// Load World Creator CSS only when shortcode is rendered.
		$world_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/world-creator.css';
		if ( file_exists( $world_css ) ) {
			wp_enqueue_style(
				'neoweaver-world-creator',
				NEOWEAVER_PLUGIN_URL . 'assets/css/public/world-creator.css',
				[],
				(string) filemtime( $world_css )
			);
		}

		// Load World Creator JS only when shortcode is rendered.
		$world_js = NEOWEAVER_PLUGIN_DIR . 'assets/js/public/world-creator.js';
		if ( file_exists( $world_js ) ) {
			wp_enqueue_script(
				'neoweaver-world-creator',
				NEOWEAVER_PLUGIN_URL . 'assets/js/public/world-creator.js',
				[],
				(string) filemtime( $world_js ),
				true
			);
		}

		wp_localize_script(
			'neoweaver-world-creator',
			'twWorldCreatorConfig',
			[
				'nonce'      => $nonce,
				'restNonce'  => wp_create_nonce( 'wp_rest' ),
				'nodesUrl'   => $nodes_url,
				'uploadsUrl' => $uploads_url,
			]
		);

		// Spinner CSS — loaded here because it is only needed when the
		// world creator shortcode is actually on the page.
		$spinner_css = NEOWEAVER_PLUGIN_DIR . 'assets/css/public/node-spinner.css';
		if ( file_exists( $spinner_css ) ) {
			wp_enqueue_style(
				'neoweaver-node-spinner',
				NEOWEAVER_PLUGIN_URL . 'assets/css/public/node-spinner.css',
				[],
				(string) filemtime( $spinner_css )
			);
		}

		$world_steps = [
			3  => [ 'WORLD_SIZE',     'Define expansion magnitude', [ [ 'Local Node', 'A single, dense micro-world.' ], [ 'Few Nodes', 'A vast region.' ], [ 'Multi Nodes', 'Full nodes simulation.' ], [ 'World', 'Multiple systems.' ], [ 'Infinite', 'Infinite reality stream.' ] ], 'size' ],
			4  => [ 'NODE_ECONOMY',   'Resource availability',     [ [ 'Frayed', 'Survival is a miracle.' ], [ 'Scarcity', 'Basic scavenge economy.' ], [ 'Balanced', 'Stable commerce.' ], [ 'Wealthy', 'High consumerism.' ], [ 'Abundant', 'Digital abundance.' ] ], 'wealth' ],
			5  => [ 'ENTROPY_DANGER', 'Entropy & Threat Rate',     [ [ 'Coherent', 'Stable world.' ], [ 'Stable', 'Manageable threats.' ], [ 'Unstable', 'Standard risks.' ], [ 'Critical', 'The Fray is strong.' ], [ 'Catastrophic', 'Systemic collapse.' ] ], 'difficulty' ],
			6  => [ 'NODE_MAGIC',     'Weave Permeability',        [ [ 'None', 'Strict logic.' ], [ 'Glitched', 'Rare anomalies.' ], [ 'Standard', 'Standard utility.' ], [ 'High', 'Reality is soft.' ], [ 'Extreme', 'Chaos rules.' ] ], 'magic' ],
			7  => [ 'NODE_GODS',      'Higher Protocols / Admins', [ [ 'Absent', 'No entities.' ], [ 'Echoes', 'Forgotten Admins.' ], [ 'Observers', 'Silent code.' ], [ 'Active', 'Demanding data.' ], [ 'Manifested', 'God-AI active.' ] ], 'gods' ],
			8  => [ 'NODE_TECH',      'Technological Anchor',      [ [ 'None existant', 'Almost analog.' ], [ 'Somewhat', 'Tech can be found' ], [ 'Normal', 'Easy to find a terminal' ], [ 'Popular', 'Sentient AI. Androids' ], [ 'Transcendent', 'Post-human. Apocalyptic future' ] ], 'technology' ],
			9  => [ 'NODE_SOCIAL',    'Race interaction',          [ [ 'Hostile', 'Tribal survival.' ], [ 'Strained', 'Faction tension.' ], [ 'Pragmatic', 'Uneasy peace.' ], [ 'Integrated', 'Common goals.' ], [ 'Unified', 'Hive-mind.' ] ], 'relations' ],
			10 => [ 'NODE_MORALITY',  'Ethical Framework',         [ [ 'Chaotic', 'Fittest survives.' ], [ 'Gray', 'Ambiguity.' ], [ 'Lawful', 'Strict codes.' ] ], 'moral' ],
		];

		$path = NEOWEAVER_PLUGIN_DIR . '/templates/partials/world-creator.php';
		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial world-creator.php -->';
		}

		ob_start();
		( static function ( $tw_data, $__path ) {
			extract( [ 'tw_data' => $tw_data ], EXTR_SKIP );
			include $__path;
		} )( [ 'world_steps' => $world_steps ], $path );
		$html = ob_get_clean() ?: '';

		return '<div class="neoweaver-screen">' . $html . '</div>';
	}
}
