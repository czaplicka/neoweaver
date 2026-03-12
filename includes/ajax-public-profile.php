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

        $char_id    = isset( $_POST['char_id'] ) ? intval( $_POST['char_id'] ) : 0;
        $is_public  = filter_var( $_POST['is_public'] ?? false, FILTER_VALIDATE_BOOLEAN );
        $wp_user_id = get_current_user_id();

        if ( ! $char_id || ! $wp_user_id ) {
            // BUG-FIX 2: Added return after wp_send_json_error. Although
            // wp_send_json_error() calls wp_die() internally, relying on that
            // is fragile (wp_die can be filtered in tests). Without the explicit
            // return, execution could fall through to the Supabase PATCH with
            // char_id=0 and wp_user_id=0, potentially mutating unintended rows.
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
        }

        wp_send_json_success();
    }
}
