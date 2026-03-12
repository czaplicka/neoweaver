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
$template_args = array(
    'supabase_base' => $supabase_base,
    'auth_headers'  => $auth_headers,
    'wp_user_id'    => $userid,
);
$game_data = function_exists('get_user_game_data_from_supabase')
    ? get_user_game_data_from_supabase( $userid )
    : array(
        'active_session_id'   => 0,
        'active_campaign_id'  => 0,
        'active_character_id' => 0,
        'active_world_id'     => 0,
        'active_location_id'  => 0,
        'char_name'           => 'Unknown',
        'char_tags'           => array(),
    );
echo "<script>
window.twAdventureData = window.twAdventureData || {};
window.twAdventureData.active_session_id   = ".(int)$game_data['active_session_id'].";
window.twAdventureData.active_campaign_id  = ".(int)$game_data['active_campaign_id'].";
window.twAdventureData.active_character_id = ".(int)$game_data['active_character_id'].";
window.twAdventureData.active_world_id     = ".(int)$game_data['active_world_id'].";
window.twAdventureData.active_location_id  = ".(int)$game_data['active_location_id'].";
window.twAdventureData.char_name           = ".json_encode($game_data['char_name']).";
window.twAdventureData.char_tags           = ".json_encode($game_data['char_tags']).";
</script>";
$activecampaignid   = get_user_meta( $userid, 'active_campaign_id', true );
$campaignworldtype  = null;
if ( ! empty( $activecampaignid ) && $supabase_base && $anon_key ) {
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
$map_rows = tw_get_data( $supabase_base . 'v_cyber_map_view?wp_user_id=eq.' . $userid . '&limit=1', $auth_headers );
$map_data = $map_rows[0] ?? [];

// 1. Pobierz aktywne session_id (źródło: twAdventureData / twGameState / query vars)
$active_session_id = $twGameState['session_id'] ?? get_query_var('session_id') ?? null;

// 2. Fetch battle grid tylko dla tej sesji
if ( $active_session_id ) {
    $grid_units = tw_get_data(
        $supabase_base . 'cyber_battle_grid'
        . '?select=*'
        . '&session_id=eq.' . rawurlencode( $active_session_id ),
        $auth_headers
    );
} else {
    $grid_units = [];
}

// 3. Zbuduj mapę slotów 1–9
$grid_map = [];

if ( is_array( $grid_units ) ) {
    foreach ( $grid_units as $unit ) {
        $slot = isset($unit['grid_slot']) ? (int) $unit['grid_slot'] : 0;
        if ( $slot >= 1 && $slot <= 9 ) {
            $grid_map[ $slot ] = $unit;
        }
    }
}

// 4. Czy w tej sesji jest jakikolwiek wróg?
$has_enemy = false;

if ( !empty($grid_map) ) {
    foreach ( $grid_map as $unit ) {
        if ( ($unit['unit_type'] ?? '') !== 'player' ) {
            $has_enemy = true;
            break;
        }
    }
}

// 5. Wczytaj panel taktyczny jako template part
include get_template_directory() . '/templates/parts/panel-tactical-left.php';
// lub, jeśli to plugin:
# include plugin_dir_path( __FILE__ ) . 'templates/parts/panel-tactical-left.php';

$grid_map = [];
$has_enemy = false;

if(is_array($grid_units)) {
    foreach($grid_units as $u) { 
        if (is_array($u) && isset($u['slot_index']) && isset($u['unit_type'])) {
            $grid_map[(int)$u['slot_index']] = $u; 
            if ($u['unit_type'] === 'enemy' || $u['unit_type'] === 'boss') {
                $has_enemy = true;
            }
        }
    }
}

// JS data dla tactical
echo "<script>
window.twTacticalData = {
    has_enemy: " . ($has_enemy ? 'true' : 'false') . ",
    map_data: " . json_encode($map_data) . ",
    grid_map: " . json_encode($grid_map) . "
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
        </section>
    </section>
</div>
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
<div style="position: fixed; z-index: 999; bottom: 20px; left: 20px; right: 20px; display: flex; gap: 20px; pointer-events: none;">
    <div style="pointer-events: all; flex: 1; max-width: 400px;">
        <?php include NEOWEAVER_PLUGIN_DIR . 'templates/parts/character-card.php'; ?>
    </div>
    <div style="pointer-events: all; flex: 1; max-width: 400px;">
        <?php include NEOWEAVER_PLUGIN_DIR . 'templates/parts/tactical-overlay.php'; ?>
    </div>
</div>
<script>
(function () {
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
                action: 'tw_get_scenarios_ajax',
                campaign_id: campaignId
            });
            const response = await fetch('/wp-admin/admin-ajax.php', {
                method: 'POST',
                body: formData
            });
            if (!response.ok) {
                throw new Error('AJAX HTTP error ' + response.status);
            }
            const json = await response.json();
            if (!json.success || !Array.isArray(json.data)) {
                list.innerHTML = '<p class="empty-msg">No missions available for this campaign yet.</p>';
                return;
            }
            const scenarios = json.data.slice(0, 3);
            if (!scenarios.length) {
                list.innerHTML = '<p class="empty-msg">No missions available. Ask your GM to sync the campaign.</p>';
                return;
            }
            list.innerHTML = '';
            scenarios.forEach((s) => {
                const tags = (s.tags || '').split(',').map((t) => t.trim()).filter(Boolean);
                const card = document.createElement('article');
                card.className = 'deck-card scenario-card';
                card.dataset.scenarioId = s.id;
                card.innerHTML = `
                    <div class="deck-card-inner">
                        ${s.img_url ? `<div class="scenario-image-wrap"><img src="${s.img_url}" alt="${s.name || ''}" class="scenario-image"></div>` : ''}
                        <header class="scenario-header">
                            <span class="scenario-difficulty">${s.difficulty || ''}</span>
                            <h4 class="scenario-title">${s.name || 'Untitled mission'}</h4>
                        </header>
                        <div class="scenario-body">
                            <p class="scenario-goal">${s.goal || ''}</p>
                            <p class="scenario-tags">
                                ${tags.map((t) => `<span class="scenario-tag">#${t}</span>`).join('')}
                                ${s.is_boss ? '<span class="scenario-tag">#boss</span>' : ''}
                                ${s.is_key_arc ? '<span class="scenario-tag">#key_arc</span>' : ''}
                            </p>
                        </div>
                        <footer class="scenario-footer">
                            <span class="scenario-type">${s.type || ''}</span>
                            <span class="scenario-category">${s.category || ''}</span>
                        </footer>
                    </div>
                `;
                list.appendChild(card);
            });
            console.log('✅ Loaded', scenarios.length, 'scenario cards');
        } catch (error) {
            console.error('❌ Error loading scenarios:', error);
            list.innerHTML = '<p class="empty-msg">Mission panel offline. Please refresh the terminal.</p>';
        }
    }
    window.twLoadScenarios = loadScenarios;
    if (window.twGameReady) {
        loadScenarios();
    } else {
        document.addEventListener('twGameStateHydrated', loadScenarios);
    }
document.addEventListener('DOMContentLoaded', function () {
  if (!window.twAdventureData) {
    console.warn('No twAdventureData – cannot bootstrap game');
    return;
  }
  window.twGameState = window.twGameState || {};
  (function hydrateTwGameState() {
    const d = window.twAdventureData || {};
    window.twGameState.currentSessionId   = d.active_session_id   ?? null;
    window.twGameState.currentCampaignId  = d.active_campaign_id  ?? null;
    window.twGameState.currentCharacterId = d.active_character_id ?? null;
    window.twGameState.currentWorldId     = d.active_world_id     ?? null;
    window.twGameState.currentLocationId  = d.active_location_id  ?? null;
    console.log('✓ twGameState hydrated from twAdventureData', window.twGameState);
  })();
});
(function () {
  const playerChatEl = document.getElementById('player-chat');
  if (!playerChatEl) return;
  async function fetchChatChannelId() {
    const sessionId = window.twGameState?.currentSessionId;
    if (!sessionId) {
      console.warn('No currentSessionId – cannot resolve chat channel');
      return null;
    }
    try {
      const params = new URLSearchParams({
        action: 'tw_get_session_state',
        session_id: sessionId
      });
      const resp = await fetch('/wp-admin/admin-ajax.php', {
        method: 'POST',
        body: params,
        credentials: 'same-origin'
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
  async function waitForChatChannel(maxTries = 10, delayMs = 2000) {
    let tries = 0;
    playerChatEl.innerHTML = '<p class="empty-msg">Channel syncing, please wait…</p>';
    while (tries < maxTries) {
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
      tries++;
      await new Promise(r => setTimeout(r, delayMs));
    }
    playerChatEl.innerHTML = '<p class="empty-msg">Channel sync timeout. Try refreshing the terminal.</p>';
  }
  document.addEventListener('twGameStateHydrated', function () {
    // jeśli już mamy chatChannelId, nie ma sensu retry
    if (window.twGameState?.chatChannelId) return;
    waitForChatChannel();
  });
})();
		document.addEventListener('twGameStateHydrated', async function () {
  const campaignId = window.twGameState?.currentCampaignId;
  const nonce = window.twAdventureData?.nonce;
  if (!campaignId || !nonce) return;
  try {
    const formData = new URLSearchParams({
      action: 'tw_ensure_world_state',
      campaign_id: String(campaignId),
      nonce: nonce
    });
    const resp = await fetch(window.twAdventureData.ajax_url, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin'
    });
    const json = await resp.json();
    console.log('🌍 World state ensure:', json);
    if (json.success) {
      setTimeout(() => {
        if (window.refreshTwClock) {
          window.refreshTwClock();
        }
      }, 500);
    }
  } catch (e) {
    console.error('World state ensure error', e);
  }
});
    console.log('🎮 Tale Weaver Scenarios Loader - Ready & Waiting');
})();
</script>