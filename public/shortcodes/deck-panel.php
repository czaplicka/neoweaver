<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! function_exists( 'tw_deck_panel_render' ) ) {
	function tw_deck_panel_render(): string {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return '';
		}

		ob_start();
		?>
		<div id="deck-panel" class="is-collapsed" aria-label="<?php echo esc_attr__( 'Deck panel', 'neoweaver' ); ?>">
			<div class="deck-tabs-wrapper">

				<button
					id="toggle-deck"
					class="panel-tab"
					type="button"
					aria-label="<?php echo esc_attr__( 'Toggle deck panel', 'neoweaver' ); ?>"
					aria-controls="deck-panel"
					aria-expanded="false"
				>
					&#9776;
				</button>

				<button
					class="panel-tab is-active"
					type="button"
					data-tab="tab-mission"
					aria-controls="tab-mission"
					aria-pressed="true"
				>
					<?php echo esc_html__( 'MISSION', 'neoweaver' ); ?>
				</button>

				<button
					class="panel-tab"
					type="button"
					data-tab="tab-augments"
					aria-controls="tab-augments"
					aria-pressed="false"
				>
					<?php echo esc_html__( 'AUGMENTS', 'neoweaver' ); ?>
				</button>

				<button
					class="panel-tab"
					type="button"
					data-tab="tab-skills"
					aria-controls="tab-skills"
					aria-pressed="false"
				>
					<?php echo esc_html__( 'SKILLS', 'neoweaver' ); ?>
				</button>

			</div>

			<div id="tab-mission" class="deck-tab-content is-active">
				<h3 class="deck-tab-title"><?php echo esc_html__( '// MISSION_DATA', 'neoweaver' ); ?></h3>
				<div id="tw-mission-content"><?php echo esc_html__( 'Loading mission data...', 'neoweaver' ); ?></div>
			</div>

			<div id="tab-augments" class="deck-tab-content">
				<h3 class="deck-tab-title"><?php echo esc_html__( '// AUGMENTS', 'neoweaver' ); ?></h3>
				<div id="tw-augments-content"><?php echo esc_html__( 'Loading augments...', 'neoweaver' ); ?></div>
			</div>

			<div id="tab-skills" class="deck-tab-content">
				<h3 class="deck-tab-title"><?php echo esc_html__( '// SKILLS & ABILITIES', 'neoweaver' ); ?></h3>
				<div id="tw-skills-content"><?php echo esc_html__( 'Loading skills...', 'neoweaver' ); ?></div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'tw_register_deck_panel_shortcode' ) ) {
	function tw_register_deck_panel_shortcode(): void {
		add_shortcode( 'tw_deck_panel', 'tw_deck_panel_render' );
	}
}

tw_register_deck_panel_shortcode();
