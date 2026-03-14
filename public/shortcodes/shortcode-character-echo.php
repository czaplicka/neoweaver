<?php
/**
 * SHORTCODE: [character_echo]
 *
 * Renders the ECHO STREAM panel: all tags attached to the active character,
 * grouped by source type (status / skill / ability / item / narrative).
 *
 * Only rendered on page ID 2857. Uses tw_supabase_url() / tw_supabase_anon_key()
 * instead of bare constants so credentials are never hard-coded.
 *
 * Supabase view: cyber_character_complete_tags
 * Required columns: character_id, label, color, source_type
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_character_echo_shortcode' ) ) {
	function tw_character_echo_shortcode() {
		// 1. Render only on the main game page
		if ( ! is_page( 2857 ) ) {
			return '';
		}

		// 2. Resolve active character ID
		$character_id = function_exists( 'tw_get_current_character_id' )
			? tw_get_current_character_id()
			: null;

		if ( ! $character_id ) {
			return '<div class="echo-stream-container">// ERROR: NO ACTIVE CHARACTER IN NEURAL LINK</div>';
		}

		// 3. Fetch from the Supabase view
		// tw_supabase_url() already returns the full base URL (no project-id constant needed).
		$endpoint = trailingslashit( tw_supabase_url() )
			. 'rest/v1/cyber_character_complete_tags'
			. '?character_id=eq.' . (int) $character_id;

		$anon_key = tw_supabase_anon_key();

		$response = wp_remote_get( $endpoint, [
			'headers' => [
				'apikey'        => $anon_key,
				'Authorization' => 'Bearer ' . $anon_key,
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return '<div class="echo-stream-container">// ERROR: CONNECTION TIMEOUT</div>';
		}

		$body = wp_remote_retrieve_body( $response );
		$rows = json_decode( $body, true );

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return '<div class="echo-stream-container">// ECHO STREAM EMPTY: NO DATA DETECTED</div>';
		}

		// 4. Group tags by source type
		$groups = [
			'status'    => [ 'title' => 'SYSTEM STATUS', 'items' => [] ],
			'skill'     => [ 'title' => 'NEURAL SKILLS', 'items' => [] ],
			'ability'   => [ 'title' => 'AUGMENTATIONS', 'items' => [] ],
			'item'      => [ 'title' => 'HARDWARE',      'items' => [] ],
			'narrative' => [ 'title' => 'IDENTITY',      'items' => [] ],
		];

		foreach ( $rows as $tag ) {
			$st = $tag['source_type'] ?? 'narrative';

			// Map source_type to group key; race/class fall into IDENTITY
			$target = isset( $groups[ $st ] ) ? $st : 'narrative';

			$groups[ $target ]['items'][] = [
				'label' => '#' . ltrim( $tag['label'] ?? '', '#' ),
				'color' => ! empty( $tag['color'] ) ? $tag['color'] : '#00ffff',
			];
		}

		// 5. Build HTML
		ob_start();
		?>
		<div class="echo-stream-container">
			<div class="echo-title">ECHO STREAM</div>
			<div class="echo-list">
				<?php
				$has_any = false;
				foreach ( $groups as $group ) :
					if ( empty( $group['items'] ) ) continue;
					$has_any = true;
					?>
					<div class="echo-group">
						<div class="echo-group-title"><?php echo esc_html( $group['title'] ); ?></div>
						<div class="echo-group-items">
							<?php foreach ( $group['items'] as $item ) : ?>
								<div class="echo-item"
								     style="--echo-tag-color: <?php echo esc_attr( $item['color'] ); ?>;">
									<span class="echo-label">
										<?php echo esc_html( $item['label'] ); ?>
									</span>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>

				<?php if ( ! $has_any ) : ?>
					<div class="echo-item" style="opacity: 0.5;">// NO ECHOES RECORDED</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	add_shortcode( 'character_echo', 'tw_character_echo_shortcode' );
}
