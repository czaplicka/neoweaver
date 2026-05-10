<?php
// ============================================================
// AJAX HANDLER: tw_join_campaign
// Bug-Fix 3: signup moved server-side with nonce + ownership check.
// ============================================================
add_action( 'wp_ajax_tw_join_campaign', 'tw_ajax_join_campaign' );

function tw_ajax_join_campaign() {
    // 1. Nonce
    check_ajax_referer( 'tw_join_nonce', 'nonce' );

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_send_json_error( [ 'message' => 'not_logged_in' ] );
        return;
    }

    if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
        wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
        return;
    }

    $join_code    = isset( $_POST['join_code'] )    ? strtoupper( sanitize_text_field( $_POST['join_code'] ) )    : '';
    $character_id = isset( $_POST['character_id'] ) ? sanitize_text_field( $_POST['character_id'] )               : '';

    if ( ! $join_code ) {
        wp_send_json_error( [ 'message' => 'missing_join_code' ] );
        return;
    }
    if ( ! $character_id ) {
        wp_send_json_error( [ 'message' => 'missing_character_id' ] );
        return;
    }

    $base    = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    $anon    = tw_supabase_anon_key();
    $headers = [
        'apikey'        => $anon,
        'Authorization' => 'Bearer ' . $anon,
    ];

    // OPT 1: character ownership check and campaign lookup are independent —
    // fire both wp_remote_get calls before reading either response, so they
    // run concurrently inside PHP's HTTP stack instead of sequentially.
    $safe_char_id = preg_replace( '/[^a-zA-Z0-9\-]/', '', $character_id );
    $char_url = add_query_arg( [
        'id'         => 'eq.' . $safe_char_id,
        'wp_user_id' => 'eq.' . $user_id,
        'status'     => 'neq.STATUS_DEAD',
        'select'     => 'id',
        'limit'      => 1,
    ], $base . 'cyber_characters' );

    // BUG-FIX 5 note: no rawurlencode() — add_query_arg() handles encoding.
    $camp_url = add_query_arg( [
        'join_code' => 'eq.' . $join_code,
        'select'    => 'id',
        'limit'     => 1,
    ], $base . 'cyber_campaign' );

    // Fire both requests before reading either response.
    $char_resp = wp_remote_get( $char_url, [ 'headers' => $headers, 'timeout' => 10 ] );
    $camp_resp = wp_remote_get( $camp_url, [ 'headers' => $headers, 'timeout' => 10 ] );

    // 2. Evaluate character ownership response.
    // BUG-FIX 6: keyed on exact UUID — 0 or 1 row, no positional access.
    if ( is_wp_error( $char_resp ) || wp_remote_retrieve_response_code( $char_resp ) !== 200 ) {
        wp_send_json_error( [ 'message' => 'character_lookup_failed' ] );
        return;
    }
    $char_rows = json_decode( wp_remote_retrieve_body( $char_resp ), true );
    if ( empty( $char_rows ) ) {
        wp_send_json_error( [ 'message' => 'character_not_owned_or_dead' ] );
        return;
    }
    // Use the DB-confirmed safe ID for all subsequent inserts.
    $character_id = $safe_char_id;

    // 3. Evaluate campaign lookup response.
    if ( is_wp_error( $camp_resp ) || wp_remote_retrieve_response_code( $camp_resp ) !== 200 ) {
        wp_send_json_error( [ 'message' => 'campaign_lookup_failed' ] );
        return;
    }
    $camp_rows = json_decode( wp_remote_retrieve_body( $camp_resp ), true );
    if ( empty( $camp_rows ) ) {
        wp_send_json_error( [ 'message' => 'no_campaign_for_code' ] );
        return;
    }
    $campaign_id = $camp_rows[0]['id'];

    // 4. Check for existing signup.
    $existing_url = add_query_arg( [
        'campaign_id' => 'eq.' . $campaign_id,
        'wp_user_id'  => 'eq.' . $user_id,
        'select'      => 'id',
        'limit'       => 1,
    ], $base . 'cyber_campaign_signups' );

    $existing_resp = wp_remote_get( $existing_url, [ 'headers' => $headers, 'timeout' => 10 ] );
    if ( ! is_wp_error( $existing_resp ) && wp_remote_retrieve_response_code( $existing_resp ) === 200 ) {
        $existing = json_decode( wp_remote_retrieve_body( $existing_resp ), true );
        if ( ! empty( $existing ) ) {
            // Already signed up — return success with campaign_id for redirect.
            wp_send_json_success( [ 'campaign_id' => $campaign_id, 'status' => 'already_joined' ] );
            return;
        }
    }

    // 5. Insert signup.
    $insert_resp = wp_remote_post( $base . 'cyber_campaign_signups', [
        'headers' => array_merge( $headers, [
            'Content-Type' => 'application/json',
            'Prefer'       => 'return=minimal',
        ] ),
        'body'    => wp_json_encode( [
            'campaign_id'  => $campaign_id,
            'wp_user_id'   => $user_id,
            'character_id' => $character_id,
        ] ),
        'timeout' => 15,
    ] );

    if ( is_wp_error( $insert_resp ) ) {
        wp_send_json_error( [ 'message' => 'signup_insert_failed' ] );
        return;
    }
    $insert_code = wp_remote_retrieve_response_code( $insert_resp );
    if ( $insert_code < 200 || $insert_code >= 300 ) {
        wp_send_json_error( [ 'message' => 'signup_insert_failed', 'http' => $insert_code ] );
        return;
    }

    wp_send_json_success( [ 'campaign_id' => $campaign_id, 'status' => 'joined' ] );
}

add_shortcode('neoweave_join_terminal', 'neoweave_join_terminal_shortcode');

function neoweave_join_terminal_shortcode() {
    if ( ! is_user_logged_in() ) {
        return '<div class="neoweave-terminal">ERROR: OPERATOR NOT LOGGED IN. ACCESS DENIED.</div>';
    }

    $user_id = get_current_user_id();

    // Supabase config z wp-config (spójne z resztą NeoWeave)
    if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
        return '<div class="neoweave-terminal">ERROR: SUPABASE CONFIG MISSING.</div>';
    }

    $supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    // Note: anon key is intentionally NOT assigned here.
    // The JS reads it from window.twAdventureData.supabase_anon_key
    // (injected by head-injection.php) to avoid exposing it in HTML attributes.

    // Bug-Fix 3: nonce for the server-side AJAX handler.
    $join_nonce = wp_create_nonce( 'tw_join_nonce' );
    $ajax_url   = admin_url( 'admin-ajax.php' );

    // Bug-Fix 4: fetch the user's living characters server-side so the player
    // can choose which agent to send — instead of blindly taking chars[0].
    $anon_key = tw_supabase_anon_key();
    $chars_url = add_query_arg( [
        'wp_user_id' => 'eq.' . $user_id,
        'status'     => 'neq.STATUS_DEAD',
        'select'     => 'id,name',
        'order'      => 'created_at.asc',
    ], $supabase_rest . 'cyber_characters' );
    $chars_resp = wp_remote_get( $chars_url, [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ],
        'timeout' => 10,
    ] );
    $available_chars = [];
    if ( ! is_wp_error( $chars_resp ) && wp_remote_retrieve_response_code( $chars_resp ) === 200 ) {
        $available_chars = json_decode( wp_remote_retrieve_body( $chars_resp ), true ) ?: [];
    }

    // BUG-FIX 11: fonts enqueued via wp_enqueue_style() so they land in <head>
    //    and are deduplicated if another shortcode requests the same fonts.
    wp_enqueue_style(
        'neoweave-join-fonts',
        'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap',
        [],
        null
    );

    ob_start();
    ?>
    <style>
    /* BUG-FIX 10: all rules scoped to #neoweave-join-terminal to prevent
       collision with .neoweave-terminal / .terminal-button used in the lobby
       shortcode and any theme components sharing these generic class names. */
    #neoweave-join-terminal.neoweave-terminal {
        background-color: #0a0c00; color: #adff00; font-family: 'Share Tech Mono', monospace;
        padding: 30px; border: 2px solid #adff00; position: relative; max-width: 700px; margin: 20px auto;
        text-transform: uppercase; box-shadow: 0 0 20px rgba(173, 255, 0, 0.2);
    }
    #neoweave-join-terminal .terminal-header { border-bottom: 1px solid #adff00; margin-bottom: 20px; padding-bottom: 10px; }
    #neoweave-join-terminal .terminal-title { font-family: 'Chakra Petch', sans-serif; font-size: 1.2rem; font-weight: bold; }
    #neoweave-join-terminal .terminal-status { margin-top: 5px; font-size: 0.9rem; }
    #neoweave-join-terminal .blink { animation: nwjt-blinker 1s linear infinite; }
    @keyframes nwjt-blinker { 50% { opacity: 0; } }
    #neoweave-join-terminal .terminal-input { margin-top: 20px; }
    #neoweave-join-terminal .terminal-input label {
        display: block;
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    #neoweave-join-terminal .terminal-input input[type="text"] {
        width: 100%;
        padding: 10px;
        background: #0a0c00;
        border: 1px solid #adff00;
        color: #adff00;
        font-family: 'Share Tech Mono', monospace;
        text-transform: uppercase;
    }
    #neoweave-join-terminal .terminal-button {
        background: #adff00; color: #0a0c00; border: none; padding: 12px 20px;
        margin-top: 20px; width: 100%; font-family: 'Chakra Petch', sans-serif; font-weight: bold;
        cursor: pointer; text-align: center; text-decoration: none; display: inline-block;
    }
    #neoweave-join-terminal .terminal-message {
        margin-top: 15px;
        font-size: 0.85rem;
        min-height: 1.2em;
    }
    #neoweave-join-terminal .terminal-message.error { color: #ff5577; }
    #neoweave-join-terminal .terminal-message.success { color: #adff00; }
    /* OPT 3: select styles moved from inline attribute into scoped stylesheet. */
    #neoweave-join-terminal .terminal-input select {
        width: 100%;
        padding: 10px;
        background: #0a0c00;
        border: 1px solid #adff00;
        color: #adff00;
        font-family: 'Share Tech Mono', monospace;
        text-transform: uppercase;
    }
    </style>

    <div class="neoweave-terminal" id="neoweave-join-terminal"
         data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
         data-nonce="<?php echo esc_attr( $join_nonce ); ?>">
        <div class="terminal-header">
            <div class="terminal-title">NEURAL LINK JOIN TERMINAL</div>
            <div class="terminal-status">
                AWAITING DEPLOYMENT ACCESS CODE<span class="blink">_</span><br>
                > PROTOCOL: SECURE_SQUAD_HANDSHAKE
            </div>
        </div>

        <div class="terminal-input">
            <label for="neoweave-join-code">ENTER DEPLOYMENT CODE:</label>
            <input type="text" id="neoweave-join-code" maxlength="16" autocomplete="off"
                   placeholder="TYPE CODE HERE">
        </div>

        <!-- Bug-Fix 4: character selector populated server-side -->
        <div class="terminal-input">
            <label for="neoweave-join-character">SELECT FIELD AGENT:</label>
            <select id="neoweave-join-character">
                <?php if ( empty( $available_chars ) ) : ?>
                    <option value="">-- NO LIVING AGENTS FOUND --</option>
                <?php else : ?>
                    <option value="">-- SELECT AGENT --</option>
                    <?php foreach ( $available_chars as $ch ) : ?>
                        <option value="<?php echo esc_attr( $ch['id'] ); ?>">
                            <?php echo esc_html( $ch['name'] ); ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </div>

        <button type="button" class="terminal-button" id="neoweave-join-button">
            INITIATE LINK
        </button>

        <div class="terminal-message" id="neoweave-join-message"></div>
    </div>

    <script>
    (function() {
        // OPT 2: bail out of the entire IIFE immediately if the terminal element
        // is not on this page — avoids registering a DOMContentLoaded listener
        // on every page load when the shortcode isn't present.
        if (!document.getElementById('neoweave-join-terminal')) return;

        function initJoinTerminal() {
            const box = document.getElementById('neoweave-join-terminal');

            // BUG-FIX 3: read nonce + ajax url from data attributes (safe values).
            const ajaxUrl = box.getAttribute('data-ajax-url');
            const nonce   = box.getAttribute('data-nonce');

            const codeInput   = document.getElementById('neoweave-join-code');
            const charSelect  = document.getElementById('neoweave-join-character');
            const button      = document.getElementById('neoweave-join-button');
            const msgEl       = document.getElementById('neoweave-join-message');

            if (!ajaxUrl || !nonce) {
                if (msgEl) {
                    msgEl.textContent = 'CONFIG ERROR: JOIN TERMINAL OFFLINE.';
                    msgEl.classList.add('error');
                }
                return;
            }

            function setMessage(text, type) {
                if (!msgEl) return;
                msgEl.textContent = text;
                msgEl.classList.remove('error', 'success');
                if (type) msgEl.classList.add(type);
            }

            async function handleJoin() {
                // BUG-FIX 8: disable immediately so rapid clicks cannot queue
                // multiple concurrent requests before validation even runs.
                button.disabled = true;

                const rawCode     = (codeInput?.value  || '').trim().toUpperCase();
                const characterId = (charSelect?.value || '').trim();

                if (!rawCode) {
                    setMessage('NO CODE DETECTED. TRY AGAIN.', 'error');
                    button.disabled = false;
                    return;
                }
                if (!characterId) {
                    setMessage('SELECT A FIELD AGENT BEFORE LINKING.', 'error');
                    button.disabled = false;
                    return;
                }

                setMessage('SCANNING DEPLOYMENT REGISTRY...', 'success');

                // BUG-FIX 7: add a 10-second timeout via AbortController so the
                // button does not hang forever if admin-ajax.php is unresponsive.
                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 10000);

                try {
                    const fd = new FormData();
                    fd.append('action',       'tw_join_campaign');
                    fd.append('nonce',        nonce);
                    fd.append('join_code',    rawCode);
                    fd.append('character_id', characterId);

                    const res  = await fetch(ajaxUrl, {
                        method:      'POST',
                        body:        fd,
                        credentials: 'same-origin',
                        signal:      controller.signal   // BUG-FIX 7
                    });
                    clearTimeout(timeoutId);

                    const json = await res.json();

                    if (!json.success) {
                        const msg = json.data?.message || 'CHECK CODE OR CONNECTION.';
                        setMessage('JOIN FAILED: ' + msg, 'error');
                        button.disabled = false;
                        return;
                    }

                    const campaignId = json.data?.campaign_id;
                    const status     = json.data?.status;

                    // BUG-FIX 9: each outcome sets its own message and triggers
                    // its own redirect explicitly. No shared fall-through.
                    if (status === 'already_joined') {
                        setMessage('LINK RESTORED. REDIRECTING TO LOBBY...', 'success');
                    } else {
                        setMessage('LINK STABLE. REDIRECTING TO LOBBY...', 'success');
                    }

                    // Button stays disabled intentionally — page is about to redirect.
                    setTimeout(function() {
                        window.location.href = '/lobby/?campaign_id=' + encodeURIComponent(campaignId);
                    }, 1200);

                } catch (e) {
                    clearTimeout(timeoutId);
                    console.error('JOIN TERMINAL ERROR', e);
                    const msg = e?.name === 'AbortError'
                        ? 'JOIN FAILED: REQUEST TIMED OUT.'
                        : 'JOIN FAILED: NETWORK ERROR.';
                    setMessage(msg, 'error');
                    button.disabled = false;
                }
            }

            if (button) {
                button.addEventListener('click', handleJoin);
            }

            if (codeInput) {
                codeInput.addEventListener('keydown', function(ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        handleJoin();
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', initJoinTerminal);
    })();
    </script>
    <?php
    return ob_get_clean();
}
?>
