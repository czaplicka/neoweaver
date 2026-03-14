<?php
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
    $supabase_key  = tw_supabase_anon_key();

    ob_start();
    ?>
    <style>
    @import url('https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap');
    .neoweave-terminal {
        background-color: #0a0c00; color: #adff00; font-family: 'Share Tech Mono', monospace;
        padding: 30px; border: 2px solid #adff00; position: relative; max-width: 700px; margin: 20px auto;
        text-transform: uppercase; box-shadow: 0 0 20px rgba(173, 255, 0, 0.2);
    }
    .terminal-header { border-bottom: 1px solid #adff00; margin-bottom: 20px; padding-bottom: 10px; }
    .terminal-title { font-family: 'Chakra Petch', sans-serif; font-size: 1.2rem; font-weight: bold; }
    .terminal-status { margin-top: 5px; font-size: 0.9rem; }
    .blink { animation: blinker 1s linear infinite; }
    @keyframes blinker { 50% { opacity: 0; } }
    .terminal-input { margin-top: 20px; }
    .terminal-input label {
        display: block;
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    .terminal-input input[type="text"] {
        width: 100%;
        padding: 10px;
        background: #0a0c00;
        border: 1px solid #adff00;
        color: #adff00;
        font-family: 'Share Tech Mono', monospace;
        text-transform: uppercase;
    }
    .terminal-button {
        background: #adff00; color: #0a0c00; border: none; padding: 12px 20px;
        margin-top: 20px; width: 100%; font-family: 'Chakra Petch', sans-serif; font-weight: bold;
        cursor: pointer; text-align: center; text-decoration: none; display: inline-block;
    }
    .terminal-message {
        margin-top: 15px;
        font-size: 0.85rem;
        min-height: 1.2em;
    }
    .terminal-message.error { color: #ff5577; }
    .terminal-message.success { color: #adff00; }
    </style>

    <div class="neoweave-terminal" id="neoweave-join-terminal"
         data-rest-base="<?php echo esc_url( $supabase_rest ); ?>"
         data-apikey="<?php echo esc_attr( $supabase_key ); ?>"
         data-wp-user-id="<?php echo esc_attr( $user_id ); ?>">
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

        <button type="button" class="terminal-button" id="neoweave-join-button">
            INITIATE LINK
        </button>

        <div class="terminal-message" id="neoweave-join-message"></div>
    </div>

    <script>
    (function() {
        async function postJson(url, apiKey, payload) {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'apikey': apiKey,
                    'Authorization': 'Bearer ' + apiKey,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(payload)
            });
            const text = await res.text();
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch(e) {}
            if (!res.ok) {
                throw { status: res.status, body: data || text };
            }
            return data;
        }

        async function getJson(url, apiKey) {
            const res = await fetch(url, {
                headers: {
                    'apikey': apiKey,
                    'Authorization': 'Bearer ' + apiKey
                }
            });
            const text = await res.text();
            let data = null;
            try { data = text ? JSON.parse(text) : null; } catch(e) {}
            if (!res.ok) {
                throw { status: res.status, body: data || text };
            }
            return data;
        }

        function initJoinTerminal() {
            const box = document.getElementById('neoweave-join-terminal');
            if (!box) return;

            const restBase  = box.getAttribute('data-rest-base');
            const apiKey    = box.getAttribute('data-apikey');
            const wpUserId  = box.getAttribute('data-wp-user-id');

            const codeInput = document.getElementById('neoweave-join-code');
            const button    = document.getElementById('neoweave-join-button');
            const msgEl     = document.getElementById('neoweave-join-message');

            if (!restBase || !apiKey || !wpUserId) {
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
                const rawCode = (codeInput.value || '').trim().toUpperCase();
                if (!rawCode) {
                    setMessage('NO CODE DETECTED. TRY AGAIN.', 'error');
                    return;
                }

                setMessage('SCANNING DEPLOYMENT REGISTRY...', 'success');

                try {
                    // 1) kampania po kodzie
                    const campUrl = restBase + 'cyber_campaign'
                        + '?select=id,join_code,world_type'
                        + '&join_code=eq.' + encodeURIComponent(rawCode);

                    const campaigns = await getJson(campUrl, apiKey);

                    if (!Array.isArray(campaigns) || !campaigns.length) {
                        setMessage('NO DEPLOYMENT MATCHES THIS CODE.', 'error');
                        return;
                    }

                    const campaign   = campaigns[0];
                    const campaignId = campaign.id;

                    // 2) pierwsza postać użytkownika
                    let charUrl = restBase + 'cyber_characters'
                        + '?select=id,name,wp_user_id,origin_kingdom_id'
                        + '&wp_user_id=eq.' + encodeURIComponent(wpUserId);

                    const chars = await getJson(charUrl, apiKey);

                    if (!Array.isArray(chars) || !chars.length) {
                        setMessage('NO COMPATIBLE FIELD AGENT FOUND FOR THIS NODE.', 'error');
                        return;
                    }

                    const character = chars[0];

                    // 3) sprawdź, czy signup już istnieje
                    const existingSignupUrl = restBase + 'cyber_campaign_signups'
                        + '?select=id,campaign_id,wp_user_id,character_id'
                        + '&campaign_id=eq.' + encodeURIComponent(campaignId)
                        + '&wp_user_id=eq.' + encodeURIComponent(wpUserId);

                    const existingSignups = await getJson(existingSignupUrl, apiKey);

                    if (Array.isArray(existingSignups) && existingSignups.length) {
                        // już zapisany → traktujemy jak sukces
                        setMessage('LINK RESTORED. REDIRECTING TO LOBBY...', 'success');
                    } else {
                        // 4) wpisz do cyber_campaign_signups
                        const signupPayload = {
                            campaign_id: campaignId,
                            wp_user_id: Number(wpUserId),
                            character_id: character.id
                        };

                        const signupUrl = restBase + 'cyber_campaign_signups';
                        await postJson(signupUrl, apiKey, signupPayload);

                        setMessage('LINK STABLE. REDIRECTING TO LOBBY...', 'success');
                    }

                    // 5) redirect do lobby
                    const lobbyUrl = '/lobby/?campaign_id=' + encodeURIComponent(campaignId);
                    setTimeout(function() {
                        window.location.href = lobbyUrl;
                    }, 1200);

                } catch (e) {
                    console.error('JOIN TERMINAL ERROR', e);
                    const errMsg =
                        (e && e.body && e.body.message)
                            ? e.body.message
                            : 'CHECK CODE OR CONNECTION.';
                    setMessage('JOIN FAILED: ' + errMsg, 'error');
                }
            }

            if (button) {
                button.addEventListener('click', handleJoin);
            }

            if (codeInput) {
                codeInput.addEventListener('keypress', function(ev) {
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
