<?php
/**
 * Template Name: Adventure Template
 * Post Type: page
 */
get_header();
$content_url = content_url();
echo '<script>window.twContentUrl = ' . json_encode( $content_url ) . ';</script>';
$userid = get_current_user_id();
$supabase_base = function_exists( 'tw_supabase_url' )
    ? trailingslashit( tw_supabase_url() ) . 'rest/v1/'
    : '';
$anon_key = function_exists( 'tw_supabase_anon_key' )
    ? tw_supabase_anon_key()
    : '';
$auth_headers = array(
    'headers' => array(
        'apikey'        => $anon_key,
        'Authorization' => 'Bearer ' . $anon_key,
    ),
    'timeout' => 12,
);
$game_data = function_exists('get_user_game_data_from_supabase')
    ? get_user_game_data_from_supabase( $userid )
    : array(
        'active_session_id'   => '',
        'active_campaign_id'  => '',
        'active_character_id' => '',
        'active_world_id'     => '',
        'active_location_id'  => 0,
        'char_name'           => 'Unknown',
        'char_tags'           => array(),
    );

// FIX #8: Generate nonce here so it's available for JS and server-side verification.
$adventure_nonce = wp_create_nonce( 'tw_adventure_nonce' );

// BUG-FIX: active_session_id, active_campaign_id, active_character_id, and
// active_world_id are UUID strings in Supabase. Casting them with (int) collapses
// every UUID to 0, breaking all JS-side Supabase queries that filter on these IDs.
// Use json_encode() to emit them as JS strings; active_location_id is a true
// integer FK and keeps its (int) cast.
echo "<script>
window.twAdventureData = window.twAdventureData || {};
window.twAdventureData.active_session_id   = ".json_encode( (string) ( $game_data['active_session_id']   ?? '' ) ).";
window.twAdventureData.active_campaign_id  = ".json_encode( (string) ( $game_data['active_campaign_id']  ?? '' ) ).";
window.twAdventureData.active_character_id = ".json_encode( (string) ( $game_data['active_character_id'] ?? '' ) ).";
window.twAdventureData.active_world_id     = ".json_encode( (string) ( $game_data['active_world_id']     ?? '' ) ).";
window.twAdventureData.active_location_id  = ".(int)$game_data['active_location_id'].";
window.twAdventureData.char_name           = ".json_encode($game_data['char_name']).";
window.twAdventureData.char_tags           = ".json_encode($game_data['char_tags']).";
window.twAdventureData.nonce               = ".json_encode( $adventure_nonce ).";
window.twAdventureData.ajax_url            = ".json_encode( admin_url( 'admin-ajax.php' ) ).";
window.twAdventureData.supabase_url        = ".json_encode( function_exists( 'tw_supabase_url' ) ? tw_supabase_url() : '' ).";
window.twAdventureData.supabase_anon_key   = ".json_encode( function_exists( 'tw_supabase_anon_key' ) ? tw_supabase_anon_key() : '' ).";
</script>";

$activecampaignid  = get_user_meta( $userid, 'active_campaign_id', true );
$campaignworldtype = null;

// PERF #10: Only fetch campaign world type if it is actually consumed downstream.
// Wrapped in a function_exists guard consistent with the rest of the template.
if ( ! empty( $activecampaignid ) && $supabase_base && $anon_key && function_exists( 'tw_needs_world_type' ) ) {
    $camp_url = add_query_arg(
        array(
            'id'     => 'eq.' . (int) $activecampaignid,
            'select' => 'world_type',
            'limit'  => 1,
        ),
        $supabase_base . 'cyber_campaign'
    );
    $camp_resp = wp_remote_get( $camp_url, array(
        'headers' => array(
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ),
        'timeout' => 10,
    ) );
    if ( ! is_wp_error( $camp_resp ) && wp_remote_retrieve_response_code( $camp_resp ) >= 200 && wp_remote_retrieve_response_code( $camp_resp ) < 300 ) {
        $camp_data = json_decode( wp_remote_retrieve_body( $camp_resp ), true ) ?: array();
        if ( ! empty( $camp_data[0]['world_type'] ) ) {
            $campaignworldtype = $camp_data[0]['world_type'];
        }
    }
}

// === TACTICAL OVERLAY DATA ===

// FIX #2: Resolve active_session_id from game_data (PHP scope).
// $twGameState was never defined in PHP — replaced with the correct source.
$active_session_id = (int) ( $game_data['active_session_id'] ?? 0 );

// PERF #11: Only hit Supabase for map/grid when there is an active session.
if ( $active_session_id > 0 && $supabase_base ) {
    $map_rows = tw_get_data(
        $supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . $userid . '&limit=1',
        $auth_headers
    );

    // FIX #1: Single, authoritative grid fetch using slot_index (removed the
    // duplicate build that used grid_slot and was immediately overwritten).
    $grid_units = tw_get_data(
        $supabase_base . 'cyber_battle_grid'
        . '?select=*'
        . '&session_id=eq.' . rawurlencode( $active_session_id ),
        $auth_headers
    );
} else {
    $map_rows   = [];
    $grid_units = [];
}

$map_data = $map_rows[0] ?? [];

// Build grid map using slot_index (single authoritative pass).
$grid_map  = [];
$has_enemy = false;

if ( is_array( $grid_units ) ) {
    foreach ( $grid_units as $u ) {
        if ( is_array( $u ) && isset( $u['slot_index'], $u['unit_type'] ) ) {
            $slot = (int) $u['slot_index'];
            if ( $slot >= 1 && $slot <= 9 ) {
                $grid_map[ $slot ] = $u;
                if ( $u['unit_type'] === 'enemy' || $u['unit_type'] === 'boss' ) {
                    $has_enemy = true;
                }
            }
        }
    }
}

// Load tactical panel template part.
//include plugin_dir_path( __FILE__ ) . 'parts/panel-tactical-left.php';

// JS data for tactical overlay.
echo "<script>
window.twTacticalData = {
    has_enemy: " . ( $has_enemy ? 'true' : 'false' ) . ",
    map_data: " . json_encode( $map_data ) . ",
    grid_map: " . json_encode( $grid_map ) . "
};
</script>";
?>
<div class="adventure-shell chat-only" id="adventure-shell">
    <section class="chat-panel">
        <header class="chat-header">
            <h1 class="chat-title">
                TERMINAL <span>CONNECTED</span>
            </h1>
            <p class="chat-subtitle">
                Instruction: write, play cards and have fun
            </p>
        </header>
        <?php echo do_shortcode( '[cyber_hud]' ); ?>
        <?php echo do_shortcode( '[tw_time_wheel]' ); ?>
        <?php echo do_shortcode( '[tw_compass]' ); ?>
        <section id="adventure-chat">
            <div class="chat-tabs">
                <button class="chat-tab is-active" data-chat-target="player-chat">Mission Chat</button>
                <button class="chat-tab" data-chat-target="gm-chat">GM Channel</button>
            </div>
            <div id="player-chat" class="chat-window is-active"></div>
            <div id="gm-chat" class="chat-window" style="display:none;"></div>
            <div id="quick-actions-container" style="display: flex; margin: 15px 0; font-family: 'Chakra Petch', sans-serif;">
                <div id="qa-static-zone" style="display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px;">
                    <div id="quick-actions-bar" style="display: flex; flex-wrap: wrap; gap: 8px;"></div>
                    <button onclick="window.toggleQAManager()" id="qa-manager-toggle"
                            style="background: #000; border: 1px dashed #adff00; color: #adff00; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-weight: bold; font-family: 'Chakra Petch', sans-serif;">
                        [+] CMD_CENTER
                    </button>
                </div>
                <div id="qa-manager-panel" style="display: none; background: rgba(0,20,0,0.95); border: 1px solid #adff00; padding: 15px; border-radius: 10px; box-shadow: 0 0 20px rgba(0,255,0,0.3); margin-top: 10px;">
                    <div style="display: flex; gap: 10px; margin-bottom: 15px; align-items: center; flex-wrap: wrap;">
                        <div style="position: relative; flex-grow: 1;">
                            <input type="text" id="qa-search-input" oninput="window.twLoadQuickActions()" placeholder="SEARCH_DATABASE..."
                                   style="width: 100%; background: #000; color: #adff00; border: 1px solid #adff00; padding: 8px 8px 8px 30px; font-family: monospace;">
                            <span style="position: absolute; left: 10px; top: 8px;">🔍</span>
                        </div>
                        <button onclick="window.toggleDeleteMode()" id="toggle-delete-mode-btn"
                                style="background: none; border: 1px solid #666; color: #666; padding: 8px 15px; cursor: pointer; font-size: 0.8em;">[x] DEL_MODE</button>
                    </div>
                    <div id="qa-category-filters" style="display: flex; gap: 8px; margin-bottom: 15px;">
                        <button onclick="window.setQAFilter('ALL')" class="filter-btn active" style="background: #adff00; color: #000; border: none; padding: 5px 15px; cursor: pointer; font-size: 0.75em; font-weight: bold; border-radius: 3px;">ALL</button>
                        <button onclick="window.setQAFilter('Red')" class="filter-btn" style="background: none; color: #ff4444; border: 1px solid #ff4444; padding: 5px 15px; cursor: pointer; font-size: 0.75em;">COMBAT</button>
                        <button onclick="window.setQAFilter('Blue')" class="filter-btn" style="background: none; color: #00ccff; border: 1px solid #00ccff; padding: 5px 15px; cursor: pointer; font-size: 0.75em;">TECH</button>
                    </div>
                    <div id="user-actions-list" style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 20px; border-top: 1px solid rgba(173,255,0,0.2); padding-top: 15px;"></div>
                    <div id="custom-action-form" style="border-top: 1px solid rgba(173,255,0,0.2); padding-top: 15px;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                            <input type="text" id="custom-label" placeholder="LABEL" style="background: #000; color: #adff00; border: 1px solid #333; padding: 8px;">
                            <select id="custom-category" style="background: #000; color: #adff00; border: 1px solid #333; padding: 8px;">
                                <option value="Custom">USER</option>
                                <option value="Red">COMBAT</option>
                                <option value="Blue">TECH</option>
                            </select>
                        </div>
                        <textarea id="custom-template" placeholder="PROMPT" rows="2" style="width: 100%; background: #000; color: #adff00; border: 1px solid #333; padding: 8px; margin-bottom: 8px; font-family: monospace;"></textarea>
                        <button onclick="window.saveCustomAction()" style="width: 100%; background: #adff00; color: #000; font-weight: bold; padding: 10px; border: none; cursor: pointer;">[EXECUTE_SAVE]</button>
                    </div>
                </div>
            </div>
            <div class="chat-input-wrapper">
                <div class="chat-input-inner">
                    <textarea id="chat-input" class="chat-input" placeholder="What will you do?"></textarea>
                </div>
                <div class="chat-action-row">
                    <button id="send-btn" class="btn-send">TRANSMIT</button>
                </div>
            </div>
        </section><!-- /#adventure-chat -->
    </section><!-- /.chat-panel -->
</div><!-- /#adventure-shell -->

<aside id="scenario-panel" class="scenario-panel">
    <div class="scenario-panel-body">
        <div id="deck-panel" class="is-open">
            <div class="deck-tabs-wrapper">
                <button class="panel-tab is-active" data-tab="tab-scenarios">Mission</button>
                <button class="panel-tab" data-tab="tab-hand">Augments</button>
                <button class="panel-tab" data-tab="tab-skills">Skills</button>
                <button id="toggle-deck" class="panel-tab">✕</button>
            </div>
            <div id="deck-container">
                <div id="tab-scenarios" class="deck-tab-content is-active">
                    <div class="deck-cards" id="scenarios-list"><p style="text-align:center;padding:20px;">Loading missions...</p></div>
                </div>
                <div id="tab-hand" class="deck-tab-content">
                    <div id="hand-frame">
                        <div class="hand-type-tabs">
                            <button class="hand-type-tab is-active" data-type-tab="all">All</button>
                            <button class="hand-type-tab" data-type-tab="attack">Attack</button>
                            <button class="hand-type-tab" data-type-tab="social">Social</button>
                            <button class="hand-type-tab" data-type-tab="control">Control</button>
                            <button class="hand-type-tab" data-type-tab="passive">Passive</button>
                            <button class="hand-type-tab" data-type-tab="special">Special</button>
                            <button class="hand-type-tab" data-type-tab="tech">Tech</button>
                        </div>
                        <div class="hand-type-views">
                            <div class="deck-cards hand-cards is-active" id="hand-cards-all"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-attack"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-social"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-control"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-passive"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-special"></div>
                            <div class="deck-cards hand-cards" id="hand-cards-tech"></div>
                        </div>
                    </div>
                </div>
                <div id="tab-skills" class="deck-tab-content">
                    <div class="deck-cards deck-cards-skills"></div>
                    <div class="deck-cards deck-cards-abilities"></div>
                </div>
            </div>
        </div>
    </div>
</aside>

<!-- GAME HUD (fixed position) -->
<?php
// =========================================================================
// CHARACTER CARD DATA PREPARATION
// These variables must be set before including character-card.php.
// All undefined-variable notices on lines throughout that template (including
// the final one near line 396) were caused by this block being absent.
// =========================================================================

// UUID-safe sanitizer — never intval() a UUID.
$_sanitize_uuid = function( $raw ): string {
    return preg_replace( '/[^a-f0-9\-]/i', '', (string) $raw );
};

$char_id = $_sanitize_uuid( $game_data['active_character_id'] ?? '' );

// Defaults for every variable the template references.
$char_data           = [];
$c_hp = $m_hp        = 10;
$c_mp = $m_mp        = 10;
$c_satiety           = 100;
$c_hydration         = 100;
$c_rest              = 100;
$sync_p              = 100;
$hp_p = $mp_p        = 100;
$hp_class            = 'hp-green';
$sync_class          = 'sync-stable';
$skills_and_abilities = [];
$inventory           = [];
$logs_data           = [];
$total_mass          = 0;
$mass_limit          = 50;
$total_power         = 0;

if ( $char_id && function_exists( 'tw_supabase_get' ) ) {

    // 1. Core character row — name, race, class, bio, notes, gold, lvl, stats.
    $char_rows = tw_supabase_get(
        'cyber_characters',
        [
            'id'     => 'eq.' . $char_id,
            'select' => 'name,race,class,bio,notes,gold,lvl,body,mind,reflex,spirit,avatar',
            'limit'  => 1,
        ]
    );
    $char_data = ( is_array( $char_rows ) && isset( $char_rows[0] ) ) ? (array) $char_rows[0] : [];

    // 2. HUD state — HP, MP, satiety, hydration, rest, sync_rate.
    $hud_rows = tw_supabase_get(
        'cyber_state_of_the_campaign',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'hp,hp_max,mp,mp_max,satiety,hydration,rest,sync_rate',
            'limit'        => 1,
        ]
    );
    if ( is_array( $hud_rows ) && isset( $hud_rows[0] ) ) {
        $h          = (array) $hud_rows[0];
        $c_hp       = max( 0, (int) ( $h['hp']       ?? 10 ) );
        $m_hp       = max( 1, (int) ( $h['hp_max']   ?? 10 ) );
        $c_mp       = max( 0, (int) ( $h['mp']       ?? 10 ) );
        $m_mp       = max( 1, (int) ( $h['mp_max']   ?? 10 ) );
        $c_satiety  = max( 0, min( 100, (int) ( $h['satiety']   ?? 100 ) ) );
        $c_hydration= max( 0, min( 100, (int) ( $h['hydration'] ?? 100 ) ) );
        $c_rest     = max( 0, min( 100, (int) ( $h['rest']      ?? 100 ) ) );
        $sync_p     = max( 0, min( 100, (int) ( $h['sync_rate'] ?? 100 ) ) );
    }

    // 3. Derived bar widths (clamped 0-100).
    $hp_p = $m_hp > 0 ? (int) min( 100, round( $c_hp / $m_hp * 100 ) ) : 0;
    $mp_p = $m_mp > 0 ? (int) min( 100, round( $c_mp / $m_mp * 100 ) ) : 0;

    // 4. CSS class helpers.
    if ( $hp_p > 50 )      { $hp_class = 'hp-green'; }
    elseif ( $hp_p > 25 )  { $hp_class = 'hp-yellow'; }
    else                   { $hp_class = 'hp-red'; }

    if ( $sync_p >= 80 )   { $sync_class = 'sync-stable'; }
    elseif ( $sync_p >= 50 ){ $sync_class = 'sync-warning'; }
    else                   { $sync_class = 'sync-critical'; }

    // 5. Skills & abilities — join with cyber_actions_library for display info.
    $skills_raw = tw_supabase_get(
        'cyber_character_skills',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'id,skill_id,cyber_actions_library(name,description,cost)',
        ]
    );
    if ( is_array( $skills_raw ) ) {
        foreach ( $skills_raw as $row ) {
            $skills_and_abilities[] = [
                'id'   => $row['id']       ?? null,
                'info' => $row['cyber_actions_library'] ?? null,
            ];
        }
    }

    // 6. Inventory — unequipped items with item details.
    $inv_raw = tw_supabase_get(
        'cyber_character_inventory',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'id,quantity,is_equipped,cyber_items(name,slot,mass,img_url)',
        ]
    );
    if ( is_array( $inv_raw ) ) {
        $total_mass = 0;
        foreach ( $inv_raw as $row ) {
            $item_info = $row['cyber_items'] ?? null;
            $inventory[] = [
                'id'          => $row['id']          ?? null,
                'quantity'    => (int) ( $row['quantity']    ?? 1 ),
                'is_equipped' => ! empty( $row['is_equipped'] ),
                'info'        => $item_info,
            ];
            if ( $item_info && isset( $item_info['mass'] ) ) {
                $total_mass += (float) $item_info['mass'] * (int) ( $row['quantity'] ?? 1 );
            }
        }
        $total_mass = round( $total_mass, 2 );
    }

    // Mass limit derived from Body attribute (simple formula: 30 + body * 4).
    $mass_limit = 30 + (int) ( $char_data['body'] ?? 0 ) * 4;

    // 7. Logs — most recent 20, newest first.
    $logs_raw = tw_supabase_get(
        'cyber_character_logs',
        [
            'character_id' => 'eq.' . $char_id,
            'select'       => 'log,created_at',
            'order'        => 'created_at.desc',
            'limit'        => 20,
        ]
    );
    $logs_data = is_array( $logs_raw ) ? $logs_raw : [];
}
// End character card data preparation.
?>
<?php include NEOWEAVER_PLUGIN_DIR . 'templates/parts/character-card.php'; ?>
<?php include NEOWEAVER_PLUGIN_DIR . 'templates/parts/tactical-overlay.php'; ?>

<script>
(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // FIX #3: Guard against DOMContentLoaded already having fired when this
    // inline script runs (which is common for scripts at the bottom of body).
    // -------------------------------------------------------------------------
    function onDOMReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn, { once: true });
        } else {
            fn();
        }
    }

    // =========================================================================
    // SCENARIOS
    // FIX #9: scenarios fetch now includes a nonce so the AJAX handler can
    // verify the request via check_ajax_referer().
    // PERF #12: limit=3 moved to the server-side AJAX handler; slice removed.
    // =========================================================================
    async function loadScenarios() {
        const shell = document.getElementById('adventure-shell');
        if (!shell) return;
        const list = document.getElementById('scenarios-list');
        if (!list) {
            console.warn('⚠️ scenarios-list not found in DOM');
            return;
        }
        list.innerHTML = '<p class="empty-msg">Scanning network for missions...</p>';
        try {
            const campaignId = window.twGameState?.currentCampaignId || window.twAdventureData?.active_campaign_id;
            console.log('🔍 Scenarios: Resolved campaignId:', campaignId);
            if (!campaignId) {
                list.innerHTML = '<p class="empty-msg">No active campaign detected.</p>';
                return;
            }
            const formData = new URLSearchParams({
                action:      'tw_get_scenarios_ajax',
                campaign_id: campaignId,
                nonce:       window.twAdventureData?.nonce ?? '', // FIX #9
            });
            const response = await fetch(window.twAdventureData?.ajax_url ?? '/wp-admin/admin-ajax.php', {
                method:      'POST',
                body:        formData,
                credentials: 'same-origin',
            });
            if (!response.ok) {
                throw new Error('AJAX HTTP error ' + response.status);
            }
            const json = await response.json();
            if (!json.success || !Array.isArray(json.data)) {
                list.innerHTML = '<p class="empty-msg">No missions available for this campaign yet.</p>';
                return;
            }
            // PERF #12: server should now return max 3 — no client-side slice needed.
            const scenarios = json.data;
            if (!scenarios.length) {
                list.innerHTML = '<p class="empty-msg">No missions available. Ask your GM to sync the campaign.</p>';
                return;
            }
            list.innerHTML = '';
            // XSS FIX: build cards via DOM API (textContent / setAttribute) instead
            // of innerHTML interpolation so scenario data from the server can never
            // inject arbitrary HTML or script into the page.
            scenarios.forEach((s) => {
                const tags = (s.tags || '').split(',').map((t) => t.trim()).filter(Boolean);

                const card  = document.createElement('article');
                card.className = 'deck-card scenario-card';
                card.dataset.scenarioId = s.id;

                const inner = document.createElement('div');
                inner.className = 'deck-card-inner';

                // Optional image
                if (s.img_url) {
                    const wrap = document.createElement('div');
                    wrap.className = 'scenario-image-wrap';
                    const img = document.createElement('img');
                    img.setAttribute('src', s.img_url);
                    img.setAttribute('alt', s.name || '');
                    img.className = 'scenario-image';
                    wrap.appendChild(img);
                    inner.appendChild(wrap);
                }

                // Header
                const header = document.createElement('header');
                header.className = 'scenario-header';
                const diff = document.createElement('span');
                diff.className = 'scenario-difficulty';
                diff.textContent = s.difficulty || '';
                const title = document.createElement('h4');
                title.className = 'scenario-title';
                title.textContent = s.name || 'Untitled mission';
                header.appendChild(diff);
                header.appendChild(title);

                // Body
                const body = document.createElement('div');
                body.className = 'scenario-body';
                const goal = document.createElement('p');
                goal.className = 'scenario-goal';
                goal.textContent = s.goal || '';
                const tagsP = document.createElement('p');
                tagsP.className = 'scenario-tags';
                tags.forEach((t) => {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#' + t;
                    tagsP.appendChild(span);
                });
                if (s.is_boss) {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#boss';
                    tagsP.appendChild(span);
                }
                if (s.is_key_arc) {
                    const span = document.createElement('span');
                    span.className = 'scenario-tag';
                    span.textContent = '#key_arc';
                    tagsP.appendChild(span);
                }
                body.appendChild(goal);
                body.appendChild(tagsP);

                // Footer
                const footer = document.createElement('footer');
                footer.className = 'scenario-footer';
                const type = document.createElement('span');
                type.className = 'scenario-type';
                type.textContent = s.type || '';
                const cat = document.createElement('span');
                cat.className = 'scenario-category';
                cat.textContent = s.category || '';
                footer.appendChild(type);
                footer.appendChild(cat);

                inner.appendChild(header);
                inner.appendChild(body);
                inner.appendChild(footer);
                card.appendChild(inner);
                list.appendChild(card);
            });
            console.log('✅ Loaded', scenarios.length, 'scenario cards');
        } catch (error) {
            console.error('❌ Error loading scenarios:', error);
            list.innerHTML = '<p class="empty-msg">Mission panel offline. Please refresh the terminal.</p>';
        }
    }
    window.twLoadScenarios = loadScenarios;

    // =========================================================================
    // CHAT CHANNEL
    // FIX #4 & #5: fetchChatChannelId now uses strict null/undefined check so
    // a session ID of 0 is correctly detected as "no active session" and
    // waitForChatChannel is only started after twGameState is fully hydrated.
    // PERF #13: exponential backoff replaces fixed 2 s × 10 poll loop.
    // =========================================================================
    const playerChatEl = document.getElementById('player-chat');

    async function fetchChatChannelId() {
        const sessionId = window.twGameState?.currentSessionId;

        // FIX #5: strict check — 0, null, and undefined all mean "no session".
        if (sessionId === null || sessionId === undefined || sessionId === 0) {
            console.warn('No valid currentSessionId – cannot resolve chat channel');
            return null;
        }
        try {
            const params = new URLSearchParams({
                action:     'tw_get_session_state',
                session_id: sessionId,
                nonce:      window.twAdventureData?.nonce ?? '',
            });
            const resp = await fetch(window.twAdventureData?.ajax_url ?? '/wp-admin/admin-ajax.php', {
                method:      'POST',
                body:        params,
                credentials: 'same-origin',
            });
            if (!resp.ok) {
                console.error('Session state HTTP error', resp.status);
                return null;
            }
            const json = await resp.json();
            if (!json.success || !json.data) return null;
            return json.data.chat_channel_id || null;
        } catch (e) {
            console.error('Session state fetch error', e);
            return null;
        }
    }

    // PERF #13: exponential backoff — starts at 1 s, caps at 8 s, max 6 tries.
    async function waitForChatChannel(maxTries = 6) {
        if (!playerChatEl) return;
        playerChatEl.innerHTML = '<p class="empty-msg">Channel syncing, please wait…</p>';
        let delay = 1000;
        for (let i = 0; i < maxTries; i++) {
            const chan = await fetchChatChannelId();
            if (chan) {
                window.twGameState.chatChannelId = chan;
                console.log('✓ Chat channel ready:', chan);
                if (window.twInitMissionChat) {
                    window.twInitMissionChat(chan);
                } else {
                    playerChatEl.innerHTML = '<p class="empty-msg">Channel ready. Messages will appear here.</p>';
                }
                return;
            }
            await new Promise(r => setTimeout(r, delay));
            delay = Math.min(delay * 1.5, 8000);
        }
        playerChatEl.innerHTML = '<p class="empty-msg">Channel sync timeout. Try refreshing the terminal.</p>';
    }

    // =========================================================================
    // WORLD STATE
    // =========================================================================
    async function ensureWorldState() {
        const campaignId = window.twGameState?.currentCampaignId;
        const nonce      = window.twAdventureData?.nonce;
        if (!campaignId || !nonce) return;
        try {
            const formData = new URLSearchParams({
                action:      'tw_ensure_world_state',
                campaign_id: String(campaignId),
                nonce:       nonce,
            });
            const resp = await fetch(window.twAdventureData.ajax_url, {
                method:      'POST',
                body:        formData,
                credentials: 'same-origin',
            });
            const json = await resp.json();
            console.log('🌍 World state ensure:', json);
            if (json.success && window.refreshTwClock) {
                setTimeout(window.refreshTwClock, 500);
            }
        } catch (e) {
            console.error('World state ensure error', e);
        }
    }

    // =========================================================================
    // HYDRATION
    // FIX #3: Use onDOMReady to safely handle already-fired DOMContentLoaded.
    // PERF #14: All three twGameStateHydrated consumers consolidated into one
    // listener so initialisation order is explicit and handlers run in parallel.
    // =========================================================================
    function hydrateTwGameState() {
        if (!window.twAdventureData) {
            console.warn('No twAdventureData – cannot bootstrap game');
            return;
        }
        window.twGameState = window.twGameState || {};
        const d = window.twAdventureData;
        window.twGameState.currentSessionId   = d.active_session_id   ?? null;
        window.twGameState.currentCampaignId  = d.active_campaign_id  ?? null;
        window.twGameState.currentCharacterId = d.active_character_id ?? null;
        window.twGameState.currentWorldId     = d.active_world_id     ?? null;
        window.twGameState.currentLocationId  = d.active_location_id  ?? null;
        console.log('✓ twGameState hydrated from twAdventureData', window.twGameState);
        document.dispatchEvent(new Event('twGameStateHydrated'));
    }

    // PERF #14: single consolidated listener replaces three separate handlers.
    document.addEventListener('twGameStateHydrated', function onGameStateReady() {
        // Run all post-hydration tasks in parallel; failures are isolated.
        Promise.allSettled([
            loadScenarios(),
            // FIX #4: waitForChatChannel is now called only after hydration,
            // so twGameState.currentSessionId is guaranteed to be set.
            window.twGameState?.chatChannelId
                ? Promise.resolve()
                : waitForChatChannel(),
            ensureWorldState(),
        ]).then(results => {
            results.forEach((r, i) => {
                if (r.status === 'rejected') {
                    console.error('Post-hydration task', i, 'failed:', r.reason);
                }
            });
        });
    }, { once: true });

    // Kick off hydration once the DOM is ready.
    onDOMReady(hydrateTwGameState);

    console.log('🎮 Tale Weaver Scenarios Loader - Ready & Waiting');
})();
</script>
