<?php
/**
 * TALE WEAVER – Cyber HUD Overlay
 * Shortcode: [cyber_hud]
 * Renders the status HUD only on the game page (ID 2857).
 *
 * Supabase views / tables used:
 *   cyber_game_sessions       – active session (world_id, location_id, character_id)
 *   cyber_world_hud_stats     – global world stats  (was: world_status_summary_v2)
 *   cyber_location_hud_stats  – per-location stats  (was: location_status_summary)
 *   cyber_reputation          – per-character faction reputation
 *
 * Requires tw_supabase_url() and tw_supabase_anon_key() helpers to be defined.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function display_cyber_hud() {
    if ( ! is_page( 2857 ) ) return '';

    // Bug 2 fix: guard uses the same source as the JS credential injection — the helper functions.
    if ( ! function_exists( 'tw_supabase_url' ) || ! tw_supabase_url() ) return '';
    if ( ! function_exists( 'tw_supabase_anon_key' ) || ! tw_supabase_anon_key() ) return '';

    $current_user_id = get_current_user_id();

    ob_start(); ?>

    <div id="hud-wrapper" class="cyber-hud-wrapper">
        <div class="status-dots-row" onclick="toggleHud()">
            <div class="hud-status-label" id="hud-trigger-text">&rsaquo; SYSTEM_ACTIVE</div>
            <div class="dots-group">
                <div class="dot" id="dot-rep_local"       style="--base-color: #0055ff"></div>
                <div class="dot" id="dot-rep_world"       style="--base-color: #6699ff"></div>
                <div class="dot" id="dot-danger"          style="--base-color: #ff0033"></div>
                <div class="dot" id="dot-stealth"         style="--base-color: #00f2ff"></div>
                <div class="dot" id="dot-order"           style="--base-color: #ffd700"></div>
                <div class="dot" id="dot-rep_tech_nature" style="--base-color: #adff00"></div>
                <div class="dot" id="dot-rep_chaos_order" style="--base-color: #cc00ff"></div>
                <div class="dot" id="dot-rep_gold_thief"  style="--base-color: #ff8800"></div>
            </div>
        </div>

        <div class="cyber-hud-grid">
            <?php
            $stats = [
                ['id' => 'rep_local',       'l' => 'LOCAL FAME',  'r' => '',       'b' => false],
                ['id' => 'rep_world',       'l' => 'REPUTATION',  'r' => '',       'b' => false],
                ['id' => 'danger',          'l' => 'DANGER',      'r' => '',       'b' => false],
                ['id' => 'stealth',         'l' => 'STEALTH',     'r' => 'DETECT', 'b' => true],
                ['id' => 'order',           'l' => 'CHAOS',       'r' => 'ORDER',  'b' => true],
                ['id' => 'rep_tech_nature', 'l' => 'TECH',        'r' => 'NATURE', 'b' => true],
                ['id' => 'rep_chaos_order', 'l' => 'MAGIC',       'r' => 'SYSTEM', 'b' => true],
                ['id' => 'rep_gold_thief',  'l' => 'GOLD',        'r' => 'THIEF',  'b' => true],
            ];
            foreach ( $stats as $s ) : ?>
                <div class="hud-column" id="p-<?php echo esc_attr( $s['id'] ); ?>">
                    <div class="hud-labels">
                        <span class="l-label"><?php echo esc_html( $s['l'] ); ?></span>
                        <span class="val-num" id="v-<?php echo esc_attr( $s['id'] ); ?>">0</span>
                        <span class="r-label"><?php echo esc_html( $s['r'] ); ?></span>
                    </div>
                    <div class="hud-bar-container">
                        <div class="scanlines"></div>
                        <div class="hud-bar-fill" id="b-<?php echo esc_attr( $s['id'] ); ?>"></div>
                        <?php if ( $s['b'] ) : ?><div class="center-line"></div><?php endif; ?>
                    </div>
                    <div class="tag-cloud" id="t-<?php echo esc_attr( $s['id'] ); ?>"></div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="hud-close-trigger" onclick="toggleHud()">[ ESC ] TERMINAL_OFF</div>
    </div>

    <div id="hud-global-alert"></div>

<script>
function toggleHud() {
    const w = document.getElementById('hud-wrapper');
    const t = document.getElementById('hud-trigger-text');
    w.classList.toggle('is-open');
    t.innerText = w.classList.contains('is-open') ? '\u00d7 DISCONNECT_STREAMS' : '\u203a SYSTEM_ACTIVE';
}

// Bug 7 fix: rep_chaos_order #00f2ff → #cc00ff (distinct from stealth)
const colorMap = {
    rep_local:       '#0055ff',
    rep_world:       '#6699ff',
    danger:          '#ff0033',
    stealth:         '#00f2ff',
    order:           '#ffd700',
    rep_tech_nature: '#adff00',
    rep_chaos_order: '#cc00ff',
    rep_gold_thief:  '#ff8800'
};

// Bug 1 fix: use esc_js() and centralised helper functions instead of raw constant output
const SUPA_URL  = '<?php echo esc_js( trailingslashit( tw_supabase_url() ) . 'rest/v1' ); ?>';
const SUPA_KEY  = '<?php echo esc_js( tw_supabase_anon_key() ); ?>';
const SUPA_HEAD = { 'apikey': SUPA_KEY, 'Authorization': 'Bearer ' + SUPA_KEY };

// Bug 3 fix: check res.ok before parsing JSON — fetch() only rejects on network failure.
async function supaFetch(path) {
    const res = await fetch(SUPA_URL + path, { headers: SUPA_HEAD });
    if (!res.ok) {
        console.error('HUD supaFetch HTTP', res.status, path);
        return [];
    }
    return res.json();
}

// Bug 6 fix: escape HTML special characters before inserting into innerHTML.
function escapeHtml(str) {
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

async function updateHUD() {
    try {
        const sessArr = await supaFetch(
            `/cyber_game_sessions?wp_user_id=eq.<?php echo (int) $current_user_id; ?>&order=created_at.desc&limit=1`
        );
        if (!sessArr?.length) return;
        const { world_id: worldId, location_id: locationId, character_id: characterId } = sessArr[0];

        let activeAlertColor = null;

        // Bug 4 fix: parallel fetches. Bug 5 fix: updated view names.
        const [worldStatsArr, locStatsArr, repArr] = await Promise.all([
            supaFetch(`/cyber_world_hud_stats?world_id=eq.${worldId}`),
            supaFetch(`/cyber_location_hud_stats?world_id=eq.${worldId}&location_id=eq.${locationId}`),
            characterId
                ? supaFetch(`/cyber_reputation?character_id=eq.${characterId}&order=updated_at.desc&limit=1`)
                : Promise.resolve([]),
        ]);

        const worldStats = worldStatsArr[0] || null;
        const locStats   = locStatsArr[0]   || null;
        const rep        = repArr[0]         || null;

        const updateRow = (id, val, tagsStr, isBipolar = false) => {
            const bar    = document.getElementById(`b-${id}`);
            const num    = document.getElementById(`v-${id}`);
            const tagBox = document.getElementById(`t-${id}`);
            const dot    = document.getElementById(`dot-${id}`);
            const value  = parseFloat(val || 0);

            if (bar) {
                if (isBipolar) {
                    const width = Math.min(100, Math.abs(value)) / 2;
                    bar.style.width = width + '%';
                    bar.style.left  = value >= 0 ? '50%' : (50 - width) + '%';

                    if (id === 'stealth') {
                        // Bug 8 fix: semantics were inverted.
                        // Axis: positive = STEALTH (hidden, safe), negative = DETECT (exposed, danger).
                        // High detection (value <= -80) is the dangerous state — alert in orange.
                        // High stealth (value >= 80) is the calm state — show cyan, no global alert.
                        if (value < 0) {
                            // Detect-heavy: bar fills to the right in orange/danger palette
                            bar.style.backgroundColor = value <= -80 ? '#ff8800' : '#ff3300';
                            if (value <= -80) activeAlertColor = '#ff8800';
                        } else {
                            // Stealth-heavy: bar fills to the left in calm cyan
                            bar.style.backgroundColor = '#00f2ff';
                            // No global alert — being well-hidden is not a warning
                        }
                    } else {
                        bar.style.backgroundColor = value >= 0 ? colorMap[id] : '#ff3300';
                    }
                } else {
                    const clamped = Math.min(100, Math.max(0, value));
                    bar.style.width           = clamped + '%';
                    bar.style.left            = '0';
                    bar.style.backgroundColor = colorMap[id] || '#ffffff';
                    if (id === 'danger' && clamped >= 80) {
                        activeAlertColor = '#ff0033';
                    } else if (clamped >= 80 && colorMap[id]) {
                        activeAlertColor = colorMap[id];
                    }
                }
            }

            if (dot) {
                dot.style.opacity = Math.max(0.1, Math.abs(value) / 100);
                dot.classList.toggle('dot-pulse', Math.abs(value) >= 80);
            }
            if (num) num.innerText = Math.abs(Math.round(value));

            if (tagBox) {
                const tags = (tagsStr || '')
                    .split(',')
                    .map(t => t.trim())
                    .filter(t => t && !['neutral', 'balance', 'balanced'].includes(t.toLowerCase()));
                // Bug 6 fix: escape each tag string before interpolating into innerHTML.
                tagBox.innerHTML = tags.slice(-3).map(t => {
                    const safe = escapeHtml(t);
                    return `<span class="tag-item" style="border-left-color:${colorMap[id] || '#fff'}">#${safe}</span>`;
                }).join('');
            }
        };

        if (locStats?.political_val !== undefined)
            updateRow('rep_local', locStats.political_val, locStats.political_tags, false);

        if (worldStats)
            updateRow('rep_world', worldStats.political_val, worldStats.political_tags, false);

        if (locStats?.danger_val !== undefined)
            updateRow('danger', locStats.danger_val, locStats.danger_tags, false);
        else if (worldStats)
            updateRow('danger', worldStats.danger_val, worldStats.danger_tags, false);

        if (locStats?.stealth_val !== undefined)
            updateRow('stealth', locStats.stealth_val, locStats.stealth_tags, true);

        if (locStats?.order_val !== undefined)
            updateRow('order', locStats.order_val, locStats.order_tags, true);

        if (rep) {
            updateRow('rep_tech_nature', rep.tech_vs_nature, null, true);
            updateRow('rep_chaos_order', rep.chaos_vs_order, null, true);
            updateRow('rep_gold_thief',  rep.gold_vs_thief,  null, true);
        }

        const globalAlert = document.getElementById('hud-global-alert');
        if (globalAlert) {
            if (activeAlertColor) {
                document.body.classList.add('global-glitch-active');
                globalAlert.style.setProperty('--alert-c', activeAlertColor);
                globalAlert.classList.add('is-visible');
            } else {
                document.body.classList.remove('global-glitch-active');
                globalAlert.classList.remove('is-visible');
            }
        }
    } catch (e) {
        console.error('HUD update error:', e);
    }
}

setInterval(updateHUD, 5000);
document.addEventListener('DOMContentLoaded', updateHUD);
</script>

    <?php return ob_get_clean();
}
add_shortcode( 'cyber_hud', 'display_cyber_hud' );
