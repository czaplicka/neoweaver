/**
 * NEOWEAVE LOBBY SHORTCODE + AJAX USER LABELS + AVATARS + ONLINE DOT + LAUNCH/READY + AUTO-JOIN
 */

add_shortcode('neoweave_lobby', 'neoweave_lobby_terminal');

function neoweave_lobby_terminal() {
    $user_id = get_current_user_id();
    if (!$user_id) {
        return '<div class="neoweave-terminal">ERROR: OPERATOR NOT IDENTIFIED. ACCESS DENIED.</div>';
    }

    // Supabase config
    if ( ! function_exists('tw_supabase_url') || ! function_exists('tw_supabase_anon_key') ) {
        return '<div class="neoweave-terminal">ERROR: SUPABASE LINK OFFLINE. CHECK TW_SUPABASE_* IN WP-CONFIG.</div>';
    }

    $supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    $supabase_key  = tw_supabase_anon_key();

    $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
    if ($campaign_id <= 0) {
        return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
    }

    // kampania: nazwa + host_id
    $campaign_name     = 'UNKNOWN';
    $campaign_host_id  = 0;
    $camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=name,wp_user_id';
    $camp_res = wp_remote_get($camp_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);
    if (!is_wp_error($camp_res)) {
        $camp_body = wp_remote_retrieve_body($camp_res);
        $camp_data = json_decode($camp_body, true);
        if (is_array($camp_data) && !empty($camp_data[0])) {
            $campaign_name    = $camp_data[0]['name'] ?? 'UNKNOWN';
            $campaign_host_id = intval($camp_data[0]['wp_user_id'] ?? 0);
        }
    }

    // ajaxurl dla JS
    $ajax_url = admin_url('admin-ajax.php');

    // mapa ID -> display_name (na start tylko aktualny user)
    $user_map = [];
    $current_user = wp_get_current_user();
    if ( $current_user && $current_user->ID ) {
        $user_map[ $current_user->ID ] = $current_user->display_name;
    }
    $user_map_json = esc_attr( wp_json_encode( $user_map ) );

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

    .squad-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
        margin-top: 20px;
    }
    .squad-slot {
        border: 1px solid #adff00;
        padding: 12px;
        min-height: 80px;
        display: flex;
        align-items: center;
        position: relative;
    }
    .slot-body {
        font-size: 0.8rem;
        width: 100%;
        text-align: left;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .slot-empty {
        opacity: 0.6;
    }

    .slot-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        border: 0px solid #adff00;
        object-fit: cover;
        background: #050600;
        flex-shrink: 0;
    }
    .slot-avatar.placeholder {
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.5rem;
        color: #555;
    }
    .slot-text-block {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }
    .slot-text-line {
        line-height: 1.2;
    }

    .online-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #00ff55;
        box-shadow: 0 0 6px #00ff55;
        margin-left: auto;
        animation: onlinePulse 1.2s infinite;
        flex-shrink: 0;
    }
    .online-dot.offline {
        background: #444;
        box-shadow: none;
        animation: none;
    }
    @keyframes onlinePulse {
        0%   { transform: scale(1);   opacity: 1; }
        50%  { transform: scale(1.4); opacity: 0.4; }
        100% { transform: scale(1);   opacity: 1; }
    }

    .terminal-button {
        background: #adff00; color: #0a0c00; border: none; padding: 12px 20px;
        margin-top: 20px; width: 100%; font-family: 'Chakra Petch', sans-serif; font-weight: bold;
        cursor: pointer; text-align: center; text-decoration: none; display: inline-block;
    }
    .terminal-actions {
        display: flex;
        gap: 10px;
        margin-top: 25px;
    }
    .terminal-button.secondary {
        background: #0a0c00;
        color: #adff00;
        border: 1px solid #adff00;
    }
    </style>

    <div class="neoweave-terminal" id="neoweave-lobby"
         data-campaign-id="<?php echo esc_attr($campaign_id); ?>"
         data-ajax-url="<?php echo esc_url($ajax_url); ?>"
         data-user-map="<?php echo $user_map_json; ?>"
         data-current-user="<?php echo esc_attr( get_current_user_id() ); ?>"
         data-host-id="<?php echo esc_attr( $campaign_host_id ); ?>">
        <div class="terminal-header">
            <div class="terminal-title">SQUAD DEPLOYMENT: ID_<?php echo esc_html($campaign_id); ?></div>
            <div class="terminal-status">
                SCANNING FOR AGENT SIGNALS...<span class="blink">_</span><br>
                > NODE: [<?php echo esc_html($campaign_name); ?>]<br>
                > PROTOCOL: NEURAL_LINK_4_WAY
            </div>
        </div>

        <div class="squad-grid">
            <div class="squad-slot" id="squad-slot-1">
                <div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div>
            </div>
            <div class="squad-slot" id="squad-slot-2">
                <div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div>
            </div>
            <div class="squad-slot" id="squad-slot-3">
                <div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div>
            </div>
            <div class="squad-slot" id="squad-slot-4">
                <div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div>
            </div>
        </div>

        <div class="terminal-actions">
            <button type="button" class="terminal-button" id="neoweave-launch-button">
                LAUNCH DEPLOYMENT
            </button>
            <button type="button" class="terminal-button secondary" id="neoweave-leave-button">
                LEAVE LOBBY
            </button>
        </div>
    </div>

    <script>
    (function() {
        function initLobbyWithClient(client) {
            const lobbyEl  = document.getElementById('neoweave-lobby');
            if (!lobbyEl) return;

            const campaignId    = lobbyEl.getAttribute('data-campaign-id');
            const ajaxUrl       = lobbyEl.getAttribute('data-ajax-url');
            const currentUserId = lobbyEl.getAttribute('data-current-user');
            const hostId        = lobbyEl.getAttribute('data-host-id');

            const userMapAttr = lobbyEl.getAttribute('data-user-map');
            let userMap = {};
            if (userMapAttr) {
                try {
                    userMap = JSON.parse(userMapAttr);
                } catch (e) {
                    console.error('LOBBY: failed to parse user map', e);
                }
            }

            const slotEls = [
                document.getElementById('squad-slot-1'),
                document.getElementById('squad-slot-2'),
                document.getElementById('squad-slot-3'),
                document.getElementById('squad-slot-4')
            ];

            function renderSlots(signups) {
                signups.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

                for (let i = 0; i < 4; i++) {
                    const slot = slotEls[i];
                    if (!slot) continue;
                    const body = slot.querySelector('.slot-body');
                    if (!body) continue;

                    const signup = signups[i];

                    if (signup) {
                        slot.classList.remove('slot-empty');
                        body.classList.remove('slot-empty');
                        body.innerHTML = '';

                        const charName = signup.character_name || ('#' + signup.character_id);
                        const userName = signup.user_name || ('USER_' + signup.wp_user_id);

                        const readyLabel = signup.is_ready ? ' [READY]' : ' [IDLE]';

                        // avatar
                        const avatarUrl = signup.character_avatar || '';
                        let avatarEl = document.createElement('div');
                        avatarEl.className = 'slot-avatar placeholder';
                        if (avatarUrl) {
                            avatarEl = document.createElement('img');
                            avatarEl.className = 'slot-avatar';
                            avatarEl.src = avatarUrl;
                            avatarEl.alt = charName;
                        } else {
                            avatarEl.textContent = 'AV';
                        }

                        // tekst
                        const textBlock = document.createElement('div');
                        textBlock.className = 'slot-text-block';

                        const line1 = document.createElement('div');
                        line1.className = 'slot-text-line';
                        line1.textContent = 'AGENT ' + charName + readyLabel;

                        const line2 = document.createElement('div');
                        line2.className = 'slot-text-line';
                        line2.textContent = 'OPERATOR ' + userName;

                        textBlock.appendChild(line1);
                        textBlock.appendChild(line2);

                        // online dot (ostatnie 60s)
                        const dot = document.createElement('div');
                        dot.className = 'online-dot';
                        if (signup._isOnline === false) {
                            dot.classList.add('offline');
                        }

                        body.appendChild(avatarEl);
                        body.appendChild(textBlock);
                        body.appendChild(dot);
                    } else {
                        slot.classList.add('slot-empty');
                        body.classList.add('slot-empty');
                        body.textContent = '// WAITING FOR SIGNAL //';
                    }
                }
            }

            async function enrichSignups(rawSignups) {
                if (!Array.isArray(rawSignups) || !rawSignups.length) return [];

                const charIds = [...new Set(rawSignups.map(s => s.character_id).filter(Boolean))];
                const userIds = [...new Set(rawSignups.map(s => s.wp_user_id).filter(Boolean))];

                let charsById = {};

                // 1) nazwy + avatar postaci z Supabase
                try {
                    if (charIds.length) {
                        const { data: chars, error: charErr } = await client
                            .from('cyber_characters')
                            .select('id,name,avatar')
                            .in('id', charIds);

                        if (!charErr && Array.isArray(chars)) {
                            chars.forEach(c => { charsById[c.id] = c; });
                        } else {
                            console.error('LOBBY: char lookup error', charErr);
                        }
                    }
                } catch (e) {
                    console.error('LOBBY: enrichSignups char exception', e);
                }

                // 2) nazwy operatorów z WordPressa (AJAX)
                try {
                    if (userIds.length && ajaxUrl) {
                        const formData = new FormData();
                        formData.append('action', 'neoweave_user_labels');
                        userIds.forEach(id => formData.append('ids[]', id));

                        const res = await fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        const json = await res.json();
                        if (json && json.success && json.data && json.data.map) {
                            Object.assign(userMap, json.data.map);
                        } else {
                            console.error('LOBBY: user labels response error', json);
                        }
                    }
                } catch (e) {
                    console.error('LOBBY: enrichSignups user exception', e);
                }

                const now = Date.now();

                return rawSignups.map(s => {
                    let isOnline = false;
                    if (s.created_at) {
                        const t = new Date(s.created_at).getTime();
                        if (!isNaN(t) && (now - t) < 60000) { // 60s
                            isOnline = true;
                        }
                    }

                    return {
                        ...s,
                        character_name: charsById[s.character_id]?.name || null,
                        character_avatar: charsById[s.character_id]?.avatar || '',
                        user_name: userMap[String(s.wp_user_id)] || null,
                        _isOnline: isOnline
                    };
                });
            }

            async function fetchSignups() {
                try {
                    const { data, error } = await client
                        .from('cyber_campaign_signups')
                        .select('campaign_id, wp_user_id, character_id, created_at, is_ready')
                        .eq('campaign_id', campaignId);

                    if (error) {
                        console.error('NEOWEAVE LOBBY: signups fetch error', error);
                        return;
                    }

                    const enriched = await enrichSignups(data || []);
                    renderSlots(enriched);
                } catch (e) {
                    console.error('NEOWEAVE LOBBY: exception while fetching signups', e);
                }
            }

            fetchSignups();
            setInterval(fetchSignups, 3000);

            // WATCHER: gdy tylko pojawi się active session dla tego usera w tej kampanii,
            // przerzucamy go do terminala
            async function watchForSessionAndRedirect() {
                try {
                    const { data, error } = await client
                        .from('cyber_game_sessions')
                        .select('id, status')
                        .eq('campaign_id', campaignId)
                        .eq('wp_user_id', currentUserId)
                        .eq('status', 'active')
                        .limit(1);

                    if (!error && data && data.length) {
                        window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
                    }
                } catch (e) {
                    console.error('SESSION WATCH ERROR', e);
                }
            }

            setInterval(watchForSessionAndRedirect, 4000);

            async function handleLaunchAsHost() {
                if (!ajaxUrl) {
                    alert('LAUNCH FAILED: missing AJAX URL.');
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'neoweave_launch_campaign');
                formData.append('campaign_id', campaignId);

                try {
                    const res  = await fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                    const json = await res.json();

                    if (json && json.success) {
                        window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
                    } else {
                        const msg = (json && (json.data?.message || json.message)) || 'UNKNOWN ERROR';
                        alert('LAUNCH FAILED: ' + msg);
                    }
                } catch (e) {
                    console.error('LAUNCH ERROR', e);
                    alert('LAUNCH FAILED: CLIENT EXCEPTION');
                }
            }

            const launchBtn = document.getElementById('neoweave-launch-button');
            if (launchBtn) {
                if (String(currentUserId) === String(hostId)) {
                    // host → LAUNCH
                    launchBtn.textContent = 'LAUNCH DEPLOYMENT';
                    launchBtn.addEventListener('click', handleLaunchAsHost);
                } else {
                    // pozostali → READY (toggle is_ready w Supabase)
                    launchBtn.textContent = 'READY';

                    let localReady = false;

                    launchBtn.addEventListener('click', async function() {
                        if (!campaignId || !currentUserId) return;

                        const newReady = !localReady;

                        try {
                            const { error } = await client
                                .from('cyber_campaign_signups')
                                .update({ is_ready: newReady })
                                .eq('campaign_id', campaignId)
                                .eq('wp_user_id', currentUserId);

                            if (error) {
                                console.error('READY TOGGLE ERROR', error);
                                return;
                            }

                            localReady = newReady;
                            launchBtn.textContent = newReady ? 'READY ✓' : 'READY';
                        } catch (e) {
                            console.error('READY TOGGLE EXCEPTION', e);
                        }
                    });
                }
            }

            const leaveBtn = document.getElementById('neoweave-leave-button');
            if (leaveBtn && ajaxUrl) {
                leaveBtn.addEventListener('click', async function() {
                    try {
                        // 1) zresetuj READY w Supabase dla obecnego usera
                        if (campaignId && currentUserId) {
                            try {
                                const { error: readyErr } = await client
                                    .from('cyber_campaign_signups')
                                    .update({ is_ready: false })
                                    .eq('campaign_id', campaignId)
                                    .eq('wp_user_id', currentUserId);

                                if (readyErr) {
                                    console.error('LEAVE: ready reset error', readyErr);
                                }
                            } catch (e) {
                                console.error('LEAVE: ready reset exception', e);
                            }
                        }

                        // 2) wywołaj istniejący AJAX leave_lobby
                        const formData = new FormData();
                        formData.append('action', 'neoweave_leave_lobby');
                        formData.append('campaign_id', campaignId);

                        const res  = await fetch(ajaxUrl, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        const json = await res.json();

                        if (json && json.success) {
                            window.location.href = '/';
                        } else {
                            console.error('NEOWEAVE LOBBY: leave failed', json);
                        }
                    } catch (e) {
                        console.error('NEOWEAVE LOBBY: leave exception', e);
                    }
                });
            }
        }

        function waitForTwSupabase() {
            if (window.twSupabase) {
                console.log('NEOWEAVE LOBBY: binding to global Supabase client');
                initLobbyWithClient(window.twSupabase);
            } else {
                setTimeout(waitForTwSupabase, 500);
            }
        }

        waitForTwSupabase();
    })();
    </script>
    <?php
    return ob_get_clean();
}

/**
 * AJAX: mapa wp_user_id -> display_name dla lobby
 */
add_action('wp_ajax_neoweave_user_labels', 'neoweave_user_labels');
add_action('wp_ajax_nopriv_neoweave_user_labels', 'neoweave_user_labels');

function neoweave_user_labels() {
    if (empty($_POST['ids']) || !is_array($_POST['ids'])) {
        wp_send_json_error(['message' => 'NO_IDS']);
    }

    $ids = array_map('intval', $_POST['ids']);
    $ids = array_filter($ids);
    $ids = array_unique($ids);

    if (empty($ids)) {
        wp_send_json_success(['map' => []]);
    }

    $users = get_users([
        'include' => $ids,
        'fields'  => ['ID', 'display_name'],
    ]);

    $map = [];
    foreach ($users as $u) {
        $map[$u->ID] = $u->display_name;
    }

    wp_send_json_success(['map' => $map]);
}

/**
 * AJAX: host LAUNCH → tworzy cyber_game_sessions z campaign_signups
 */
add_action('wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign');

function neoweave_launch_campaign() {
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => 'not_logged_in']);
    }

    $campaign_id = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0;
    if ($campaign_id <= 0) {
        wp_send_json_error(['message' => 'invalid_campaign']);
    }

    $current_user_id = get_current_user_id();

    if ( ! function_exists('tw_supabase_url') || ! function_exists('tw_supabase_anon_key') ) {
        wp_send_json_error(['message' => 'supabase_config_missing']);
    }

    $supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
    $supabase_key  = tw_supabase_anon_key();

    // 1) host check – tylko właściciel kampanii może kliknąć LAUNCH
    $camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=wp_user_id';
    $camp_res = wp_remote_get($camp_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);
    if (is_wp_error($camp_res)) {
        wp_send_json_error(['message' => 'campaign_fetch_error']);
    }
    $camp_data = json_decode(wp_remote_retrieve_body($camp_res), true);
    $host_id   = isset($camp_data[0]['wp_user_id']) ? intval($camp_data[0]['wp_user_id']) : 0;
    if ($host_id !== $current_user_id) {
        wp_send_json_error(['message' => 'not_host']);
    }

    // 1b) world_id dla tej kampanii (z cyber_campaign_worlds)
    $world_id = null;
    $world_url = $supabase_rest . 'cyber_campaign_worlds?campaign_id=eq.' . $campaign_id . '&select=world_id';
    $world_res = wp_remote_get($world_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);
    if (!is_wp_error($world_res)) {
        $world_data = json_decode(wp_remote_retrieve_body($world_res), true);
        if (is_array($world_data) && !empty($world_data[0]['world_id'])) {
            $world_id = intval($world_data[0]['world_id']);
        }
    }

    // jeśli świat nie jest przypięty → nie pozwalamy wystartować
    if (!$world_id) {
        wp_send_json_error(['message' => 'no_world_linked']);
    }

    // 1c) startowa lokacja (0,0) w tym świecie
    $location_id = null;
    $loc_url = $supabase_rest
        . 'cyber_world_map?world_id=eq.' . $world_id
        . '&coord_x=eq.0&coord_y=eq.0&select=id&limit=1';
    $loc_res = wp_remote_get($loc_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);
    if (!is_wp_error($loc_res)) {
        $loc_data = json_decode(wp_remote_retrieve_body($loc_res), true);
        if (is_array($loc_data) && !empty($loc_data[0]['id'])) {
            $location_id = intval($loc_data[0]['id']);
        }
    }

    // jeśli nie ma kafelka 0,0 → blokujemy start, żeby FK nie wybuchł
    if (!$location_id) {
        wp_send_json_error(['message' => 'no_start_location']);
    }

    // 2) pobierz signupy do tej kampanii
    $signup_url = $supabase_rest . 'cyber_campaign_signups?campaign_id=eq.' . $campaign_id
        . '&select=wp_user_id,character_id';
    $signup_res = wp_remote_get($signup_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);
    if (is_wp_error($signup_res)) {
        wp_send_json_error(['message' => 'signup_fetch_error']);
    }
    $signups = json_decode(wp_remote_retrieve_body($signup_res), true);
    if ( ! is_array($signups) || ! count($signups) ) {
        wp_send_json_error(['message' => 'no_signups']);
    }

    // 2b) wyczyść stare aktywne sesje tych userów (nie gramy kilku gier naraz)
    $user_ids = array_unique(array_map(
        static function($s) { return intval($s['wp_user_id']); },
        $signups
    ));
    $user_ids = array_filter($user_ids);

    if (!empty($user_ids)) {
        $ids_list = implode(',', $user_ids);

        $cleanup_url = $supabase_rest . 'cyber_game_sessions'
            . '?wp_user_id=in.(' . $ids_list . ')&status=eq.active';

        $cleanup_res = wp_remote_request($cleanup_url, [
            'method'  => 'PATCH',
            'headers' => [
                'apikey'        => $supabase_key,
                'Authorization' => 'Bearer ' . $supabase_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode(['status' => 'paused']),
        ]);
        // ewentualny błąd cleanupu ignorujemy na start
    }

    // 3) wstaw nowe sesje – jedna ACTIVE na usera w tej kampanii, z world_id i location_id
    $sessions_payload = [];
    foreach ($signups as $s) {
        $sessions_payload[] = [
            'campaign_id'  => $campaign_id,
            'wp_user_id'   => intval($s['wp_user_id']),
            'character_id' => intval($s['character_id']),
            'world_id'     => $world_id,
            'location_id'  => $location_id,
            'status'       => 'active',
        ];
    }

    $session_url = $supabase_rest . 'cyber_game_sessions';
    $session_res = wp_remote_post($session_url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key,
            'Content-Type'  => 'application/json'
        ],
        'body'    => wp_json_encode($sessions_payload),
    ]);

    if (is_wp_error($session_res) || wp_remote_retrieve_response_code($session_res) >= 300) {
        wp_send_json_error(['message' => 'session_insert_error']);
    }

    wp_send_json_success(['message' => 'launched']);
}