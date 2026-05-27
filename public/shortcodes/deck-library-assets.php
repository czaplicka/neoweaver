<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Enqueue CSS + JS for the deck-library shortcode.
 * Called from cyber_deck_builder_shortcode() as tw_enqueue_library_assets().
 *
 * @param array $config  Data passed to JS as nwDeckConfig.
 */
if ( ! function_exists( 'tw_enqueue_library_assets' ) ) {
	function tw_enqueue_library_assets( array $config = [] ): void {

		$plugin_url = defined( 'NEOWEAVER_PLUGIN_URL' )
			? NEOWEAVER_PLUGIN_URL
			: plugin_dir_url( dirname( __DIR__ ) . '/neoweaver.php' );

		$version = defined( 'NEOWEAVER_VERSION' ) ? NEOWEAVER_VERSION : '1.0.0';

		// ── CSS ──────────────────────────────────────────────────────────────
		wp_enqueue_style(
			'nw-cards',
			$plugin_url . 'assets/css/public/cards.css',
			[],
			$version
		);

		wp_enqueue_style(
			'nw-deck-library',
			$plugin_url . 'assets/css/public/library.css',
			[ 'nw-cards' ],
			$version
		);

		// ── JS ───────────────────────────────────────────────────────────────
		wp_enqueue_script(
			'nw-deck-library',
			$plugin_url . 'public/js/deck-library.js',
			[],          // brak zależności
			$version,
			true         // footer
		);

		// ── Dane dla JS (nwDeckConfig) ───────────────────────────────────────
		wp_localize_script(
			'nw-deck-library',
			'nwDeckConfig',
			array_merge(
				[
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
					'nonce'       => wp_create_nonce( 'cyber_deck_builder' ),
					'characterId' => '',
					'limits'      => [ 'minActive' => 20, 'maxActive' => 50 ],
				],
				$config
			)
		);
	}
}
