/**
 * neoweaver-header-node.js
 *
 * Displays the active world name in the site header.
 *
 * Flow:
 *  1. PHP shortcode [tw_active_node] injects the current WP user ID into
 *     data-wp-user-id on #node-name-display (server-side, unforgeable).
 *  2. This script queries cyber_game_sessions for the ONE active session
 *     belonging to that user (guaranteed unique by idx_one_active_session_per_user).
 *  3. It then selects the related world name via Supabase PostgREST join:
 *     cyber_worlds(name).
 *
 * Dependencies: twNeoWeaverData (supabaseUrl, supabaseKey) injected by
 *               wp_localize_script in neoweaver-wp-core.php.
 */
document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('node-name-display');
    if (!el) return;

    const wpUserId = el.dataset.wpUserId;
    if (!wpUserId) {
        el.textContent = 'NO_UPLINK';
        return;
    }

    if (!window.twNeoWeaverData) {
        el.textContent = 'CFG_ERR';
        return;
    }

    const { supabaseUrl, supabaseKey } = window.twNeoWeaverData;

    // Query the single active session for this user and join the world name.
    // cyber_game_sessions has a unique partial index on wp_user_id WHERE status='active',
    // so this returns at most one row.
    const endpoint =
        `${supabaseUrl}/rest/v1/cyber_game_sessions` +
        `?wp_user_id=eq.${wpUserId}` +
        `&status=eq.active` +
        `&select=world_id,cyber_worlds(name)` +
        `&limit=1`;

    fetch(endpoint, {
        headers: {
            'apikey': supabaseKey,
            'Authorization': `Bearer ${supabaseKey}`,
            'Accept': 'application/json'
        }
    })
    .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function (data) {
        if (!data || !data.length) {
            el.textContent = 'NO_ACTIVE_SESSION';
            return;
        }
        const worldName = data[0]?.cyber_worlds?.name ?? 'UNKNOWN_NODE';
        el.textContent  = worldName;
        el.style.color  = 'var(--neon-green, #adff00)';
    })
    .catch(function () {
        el.textContent = 'CONN_ERROR';
    });
});
