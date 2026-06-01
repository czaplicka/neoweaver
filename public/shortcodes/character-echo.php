<?php
/**
 * SHORTCODE: [character_echo]
 *
 * Renders the ECHO STREAM panel: all tags attached to the active character,
 * grouped by source type (status / skill / ability / item / narrative).
 *
 * Supabase view: cyber_character_complete_tags
 * Required columns: character_id, label, color, source_type
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'tw_character_echo_shortcode' ) ) {
	function tw_character_echo_shortcode(): string {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return '';
		}

		if ( ! function_exists( 'tw_get_current_character_id' ) ) {
			return '<div class="echo-stream-container">// ERROR: CHARACTER RESOLVER OFFLINE</div>';
		}

		if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_service_key' ) ) {
			return '<div class="echo-stream-container">// ERROR: SUPABASE CONFIG OFFLINE</div>';
		}

		if ( function_exists( 'tw_enqueue_character_echo_assets' ) ) {
			tw_enqueue_character_echo_assets();
		}

		$character_id = tw_get_current_character_id();

		if ( empty( $character_id ) ) {
			return '<div class="echo-stream-container">// ERROR: NO ACTIVE CHARACTER IN NEURAL LINK</div>';
		}

		$safe_id = preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $character_id );

		if ( empty( $safe_id ) ) {
			return '<div class="echo-stream-container">// ERROR: INVALID CHARACTER IDENTIFIER</div>';
		}

		// BUG 17 fix — cache key scoped to wp_user_id so two users never share a cache entry.
		// TTL set to 0 (no cache) so GM tag changes via Make.com are reflected immediately.
		$wp_user_id = get_current_user_id();
		$cache_key  = 'tw_echo_tags_' . md5( $safe_id . '_u' . $wp_user_id );
		$rows       = get_transient( $cache_key );

		if ( false === $rows ) {
			$endpoint = add_query_arg(
				[
					'character_id' => 'eq.' . $safe_id,
					'select'       => 'label,color,source_type',
				],
				trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_character_complete_tags'
			);

			// BUG 16 fix — use service-role key so RLS (auth.uid() checks) is bypassed
			// for this server-side read. The anon key was causing empty results when the
			// view policy requires an authenticated user JWT.
			$service_key = tw_supabase_service_key();

			if ( empty( $service_key ) ) {
				return '<div class="echo-stream-container">// ERROR: SUPABASE SERVICE KEY MISSING</div>';
			}

			$response = wp_remote_get(
				$endpoint,
				[
					'headers' => [
						'apikey'        => $service_key,
						'Authorization' => 'Bearer ' . $service_key,
						'Content-Type'  => 'application/json',
					],
					'timeout' => 10,
				]
			);

			if ( is_wp_error( $response ) ) {
				return '<div class="echo-stream-container">// ERROR: CONNECTION TIMEOUT</div>';
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $code ) {
				error_log( 'TW Echo: Supabase HTTP ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
				return '<div class="echo-stream-container">// ERROR: DATA FEED UNAVAILABLE</div>';
			}

			$rows = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $rows ) ) {
				$rows = [];
			}

			// TTL = 0 → no caching; tags must always reflect the live GM state.
			set_transient( $cache_key, $rows, 0 );
		}

		$groups = [
			'status'    => [ 'title' => 'SYSTEM STATUS', 'items' => [] ],
			'skill'     => [ 'title' => 'NEURAL SKILLS', 'items' => [] ],
			'ability'   => [ 'title' => 'AUGMENTATIONS', 'items' => [] ],
			'item'      => [ 'title' => 'HARDWARE', 'items' => [] ],
			'narrative' => [ 'title' => 'IDENTITY', 'items' => [] ],
		];

		foreach ( $rows as $tag ) {
			$source_type = sanitize_key( $tag['source_type'] ?? 'narrative' );
			$target      = isset( $groups[ $source_type ] ) ? $source_type : 'narrative';

			$raw_color = sanitize_hex_color( $tag['color'] ?? '' );
			$color     = ! empty( $raw_color ) ? $raw_color : '#00ffff';

			$label = sanitize_text_field( (string) ( $tag['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$groups[ $target ]['items'][] = [
				'label' => '#' . ltrim( $label, '#' ),
				'color' => $color,
			];
		}

		$has_items = false;
		foreach ( $groups as $group ) {
			if ( ! empty( $group['items'] ) ) {
				$has_items = true;
				break;
			}
		}

		ob_start();
		?>
		<div class="echo-stream-container" data-tw-character-echo="1">
			<div class="echo-title">ECHO STREAM</div>
			<div class="echo-list">
				<?php if ( ! $has_items ) : ?>
					<div class="echo-item echo-item--empty">// NO ECHOES RECORDED</div>
				<?php else : ?>
					<?php foreach ( $groups as $group ) : ?>
						<?php if ( empty( $group['items'] ) ) : ?>
							<?php continue; ?>
						<?php endif; ?>

						<div class="echo-group">
							<div class="echo-group-title"><?php echo esc_html( $group['title'] ); ?></div>
							<div class="echo-group-items">
								<?php foreach ( $group['items'] as $item ) : ?>
									<div class="echo-item" style="--echo-tag-color: <?php echo esc_attr( $item['color'] ); ?>;">
										<span class="echo-label"><?php echo esc_html( $item['label'] ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	add_shortcode( 'character_echo', 'tw_character_echo_shortcode' );
}
