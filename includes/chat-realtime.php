<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NEOWEAVER — chat-realtime.php
 *
 * Ładuje skrypt chat-realtime.js na stronach z szablonem adventure.php.
 * Przekazuje do JS dane konfiguracyjne przez wp_localize_script:
 *   - ajaxUrl  : adres WordPress AJAX
 *   - nonce    : zabezpieczenie formularza
 *   - charId   : UUID aktywnej postaci (z meta lub query param)
 *   - channelId: UUID kanału czatu
 *   - sessionId, campaignId : opcjonalne
 */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( 'templates/adventure.php' ) ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}

		$file_rel  = 'assets/js/public/chat-realtime.js';
		$file_path = trailingslashit( NEOWEAVER_PLUGIN_DIR ) . $file_rel;
		$file_url  = trailingslashit( NEOWEAVER_PLUGIN_URL ) . $file_rel;
		$version   = file_exists( $file_path ) ? (string) filemtime( $file_path ) : NEOWEAVER_VERSION;

		wp_enqueue_script(
			'tw-chat-realtime',
			$file_url,
			array(),
			$version,
			true
		);

		// Dane przekazywane do JS
		$char_id    = sanitize_text_field( get_query_var( 'tw_char_id',    get_user_meta( $user_id, 'tw_active_char_id',    true ) ) );
		$channel_id = sanitize_text_field( get_query_var( 'tw_channel_id', get_user_meta( $user_id, 'tw_active_channel_id', true ) ) );
		$session_id = sanitize_text_field( get_user_meta( $user_id, 'tw_active_session_id',  true ) );
		$campaign_id= sanitize_text_field( get_user_meta( $user_id, 'tw_active_campaign_id', true ) );

		wp_localize_script(
			'tw-chat-realtime',
			'twChatData',
			[
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'tw_chat_nonce' ),
				'charId'     => $char_id,
				'channelId'  => $channel_id,
				'sessionId'  => $session_id,
				'campaignId' => $campaign_id,
			]
		);
	},
	20
);
