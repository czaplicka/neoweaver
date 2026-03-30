<?php
// ==========================================
// 4. AJAX – PUBLICZNY PROFIL POSTACI
// ==========================================

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_ajax_tw_toggle_char_public', 'tw_handle_toggle_char_public' );

if ( ! function_exists( 'tw_handle_toggle_char_public' ) ) {
    function tw_handle_toggle_char_public() {
        check_ajax_referer( 'tw_char_nonce', 'nonce' );

        // BUG-FIX: char_id is a UUID — intval() collapses it to 0, causing the
        // PATCH to match no rows. Sanitize with UUID-safe stripping instead.
        $char_id    = isset( $_POST['char_id'] )
            ? preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $_POST['char_id'] )
            : '';
        $is_public  = filter_var( $_POST['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $wp_user_id = get_current_user_id();

        if ( empty( $char_id ) || ! $wp_user_id ) {
            wp_send_json_error( 'Unauthorized' );
            return;
        }

        // Używamy centralnego helpera request
        $result = tw_supabase_request(
            'PATCH',
            'cyber_characters',
            [
                'id'         => 'eq.' . $char_id,
                'wp_user_id' => 'eq.' . $wp_user_id,
            ],
            [ 'is_public' => $is_public ]
        );

        if ( ! $result['ok'] ) {
            wp_send_json_error( 'Database Error' );
            return;
        }

        wp_send_json_success();
    }
}
