<?php
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
		$uploads     = wp_upload_dir();
		$uploads_url = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';

		if ( function_exists( 'tw_enqueue_world_creator_assets' ) ) {
			tw_enqueue_world_creator_assets(
				array(
					'nonce'      => $nonce,
					'restNonce'  => wp_create_nonce( 'wp_rest' ),
					'nodesUrl'   => $nodes_url,
					'uploadsUrl' => $uploads_url,
				)
			);
		}

		$world_steps = array(
			3  => array( 'WORLD_SIZE', 'Define expansion magnitude', array( array( 'Local Node', 'A single, dense micro-world.' ), array( 'Few Nodes', 'A vast region.' ), array( 'Multi Nodes', 'Full nodes simulation.' ), array( 'World', 'Multiple systems.' ), array( 'Infinite', 'Infinite reality stream.' ) ), 'size' ),
			4  => array( 'NODE_ECONOMY', 'Resource availability', array( array( 'Frayed', 'Survival is a miracle.' ), array( 'Scarcity', 'Basic scavenge economy.' ), array( 'Balanced', 'Stable commerce.' ), array( 'Wealthy', 'High consumerism.' ), array( 'Abundant', 'Digital abundance.' ) ), 'wealth' ),
			5  => array( 'ENTROPY_DANGER', 'Entropy & Threat Rate', array( array( 'Coherent', 'Stable world.' ), array( 'Stable', 'Manageable threats.' ), array( 'Unstable', 'Standard risks.' ), array( 'Critical', 'The Fray is strong.' ), array( 'Catastrophic', 'Systemic collapse.' ) ), 'difficulty' ),
			6  => array( 'NODE_MAGIC', 'Weave Permeability', array( array( 'None', 'Strict logic.' ), array( 'Glitched', 'Rare anomalies.' ), array( 'Standard', 'Standard utility.' ), array( 'High', 'Reality is soft.' ), array( 'Extreme', 'Chaos rules.' ) ), 'magic' ),
			7  => array( 'NODE_GODS', 'Higher Protocols / Admins', array( array( 'Absent', 'No entities.' ), array( 'Echoes', 'Forgotten Admins.' ), array( 'Observers', 'Silent code.' ), array( 'Active', 'Demanding data.' ), array( 'Manifested', 'God-AI active.' ) ), 'gods' ),
			8  => array( 'NODE_TECH', 'Technological Anchor', array( array( 'None existant', 'Almost analog.' ), array( 'Somewhat', 'Tech can be found' ), array( 'Normal', 'Easy to find a terminal' ), array( 'Popular', 'Sentient AI. Androids' ), array( 'Transcendent', 'Post-human. Apocalyptic future' ) ), 'technology' ),
			9  => array( 'NODE_SOCIAL', 'Race interaction', array( array( 'Hostile', 'Tribal survival.' ), array( 'Strained', 'Faction tension.' ), array( 'Pragmatic', 'Uneasy peace.' ), array( 'Integrated', 'Common goals.' ), array( 'Unified', 'Hive-mind.' ) ), 'relations' ),
			10 => array( 'NODE_MORALITY', 'Ethical Framework', array( array( 'Chaotic', 'Fittest survives.' ), array( 'Gray', 'Ambiguity.' ), array( 'Lawful', 'Strict codes.' ) ), 'moral' ),
		);

		$path = NEOWEAVER_PLUGIN_DIR . 'templates/partials/world-creator.php';

		if ( ! file_exists( $path ) ) {
			return '<!-- Neoweaver: missing partial world-creator.php -->';
		}

		ob_start();

		(
			static function ( $tw_data, $__path ) {
				extract( array( 'tw_data' => $tw_data ), EXTR_SKIP );
				include $__path;
			}
		)(
			array(
				'world_steps' => $world_steps,
			),
			$path
		);

		$html = ob_get_clean() ?: '';

		return '<div class="neoweaver-screen">' . $html . '</div>';
	}

	add_shortcode( 'tw_world_creator', 'neoweaver_shortcode_world_creator' );
}
