<?php
/**
 * Shortcode: [tw_world_creator]
 *
 * Renders the 11-step Node (World) creation wizard.
 * Extracted from Neoweaver_Public to keep each wizard in its own file.
 *
 * Dependencies (must be loaded before this file):
 *   - tw_supabase_url() / tw_supabase_anon_key()  (supabase-helpers.php)
 *   - neoweaver-world-creator JS/CSS               (enqueued by Neoweaver_Public::enqueue_assets)
 *
 * CSS scope : .neoweaver-screen #tw-world-creator-container
 * JS file   : assets/js/tw-world-creator.js
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'neoweaver_shortcode_world_creator' ) ) {

	/**
	 * [tw_world_creator] callback.
	 *
	 * Intentionally a standalone function, not a method, so it can be
	 * registered independently of Neoweaver_Public.  The shortcode tag
	 * is still registered by Neoweaver_Public::__construct() via
	 * [ $this, 'shortcode_world_creator' ], which now delegates here.
	 *
	 * @return string  Rendered HTML wrapped in .neoweaver-screen.
	 */
	function neoweaver_shortcode_world_creator(): string {
		if ( ! is_user_logged_in() ) {
			return '<div class="neoweaver-screen"><div class="tw-error">ACCESS DENIED: Unauthorized Operator.</div></div>';
		}

		$nonce     = wp_create_nonce( 'tw_world_nonce' );
		$endpoint  = get_stylesheet_directory_uri() . '/endpoint/tw-endpoint-world.php';
		$nodes_url = home_url( '/nodes/' );

		wp_localize_script(
			'neoweaver-world-creator',
			'twWorldCreatorConfig',
			[
				'nonce'    => $nonce,
				'endpoint' => $endpoint,
				'nodesUrl' => $nodes_url,
			]
		);

		// World-step option definitions — static config kept in PHP so future
		// translators can use standard WP i18n functions without touching JS.
		$world_steps = [
			3  => [ 'WORLD_SIZE',     'Define expansion magnitude',  [ ['Local Node','A single, dense micro-world.'], ['Few Nodes','A vast region.'], ['Multi Nodes','Full nodes simulation.'], ['World','Multiple systems.'], ['Infinite','Infinite reality stream.'] ],          'size'       ],
			4  => [ 'NODE_ECONOMY',   'Resource availability',       [ ['Frayed','Survival is a miracle.'], ['Scarcity','Basic scavenge economy.'], ['Balanced','Stable commerce.'], ['Wealthy','High consumerism.'], ['Abundant','Digital abundance.'] ],                       'wealth'     ],
			5  => [ 'ENTROPY_DANGER', 'Entropy & Threat Rate',       [ ['Coherent','Stable world.'], ['Stable','Manageable threats.'], ['Unstable','Standard risks.'], ['Critical','The Fray is strong.'], ['Catastrophic','Systemic collapse.'] ],                             'difficulty' ],
			6  => [ 'NODE_MAGIC',     'Weave Permeability',          [ ['None','Strict logic.'], ['Glitched','Rare anomalies.'], ['Standard','Standard utility.'], ['High','Reality is soft.'], ['Extreme','Chaos rules.'] ],                                                   'magic'      ],
			7  => [ 'NODE_GODS',      'Higher Protocols / Admins',   [ ['Absent','No entities.'], ['Echoes','Forgotten Admins.'], ['Observers','Silent code.'], ['Active','Demanding data.'], ['Manifested','God-AI active.'] ],                                                'gods'       ],
			8  => [ 'NODE_TECH',      'Technological Anchor',        [ ['Retro','Analog/CRT, late \'90.'], ['Modern','Networked. Today'], ['Advanced','Cybernetics. Tomorrow'], ['Future','Sentient AI. Close future'], ['Transcendent','Post-human. Apocalyptic future'] ],    'technology' ],
			9  => [ 'NODE_SOCIAL',    'Thread interaction',          [ ['Hostile','Tribal survival.'], ['Strained','Faction tension.'], ['Pragmatic','Uneasy peace.'], ['Integrated','Common goals.'], ['Unified','Hive-mind.'] ],                                               'relations'  ],
			10 => [ 'NODE_MORALITY',  'Ethical Framework',           [ ['Chaotic','Fittest survives.'], ['Gray','Ambiguity.'], ['Lawful','Strict codes.'] ],                                                                                                                     'moral'      ],
		];

		$path = get_stylesheet_directory() . '/templates/partials/world-creator.php';
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
