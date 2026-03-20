<?php
/**
 * TALE WEAVER – Quick Actions CMD_CENTER v2.1
 * Renders the Glass Terminal quick-actions bar only on the adventure page template.
 * Loaded via WPCode snippet or included in class-neoweaver-public.php.
 *
 * Tables used:
 *   cyber_quick_actions  – global actions (display_order, label, template, category, required_tag/s, is_permanent)
 *   cyber_combos         – combo actions
 *   cyber_user_actions   – per-character custom actions (character_id, label, template, category)
 *
 * Changelog v2.1:
 *   - Fix: currentCharId now resolved lazily so gameState has time to hydrate
 *   - Fix: innerHTML += loop replaced with DocumentFragment to prevent XSS and listener loss
 *   - Fix: handleQuickActionClick dispatches input+change events for framework reactivity
 *   - Fix: twLoadQuickActions now respects the active currentFilter
 *   - Fix: required_tags empty-string edge case no longer hides permanent-like actions
 *   - Fix: renderQuickActionsUI restores the active class on filter buttons after refresh
 *   - Fix: resize listener registered once via a flag; won't stack on re-init
 *   - Fix: Supabase fallback corrected to lowercase createClient global
 *   - Opt: tag lookup uses a Set for O(1) membership test
 *   - Opt: twLoadQuickActions debounced (200 ms)
 *   - Opt: DOM references cached after first lookup
 *   - Opt: render functions wrapped in individual try/catch so one failure can't block the other
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( is_page_template( 'templates/adventure.php' ) ) :
?>
<script>
(function($) {
    'use strict';

    // ── STATE ─────────────────────────────────────────────────────────────────

    let allActions    = [];
    let combos        = [];
    let userActions   = [];
    let currentFilter = 'ALL';
    let deleteMode    = false;
    let playerTagSet  = new Set();   // O(1) lookups vs Array.includes
    let resizeRegistered = false;    // guard against stacking resize listeners

    // ── LAZY HELPERS ──────────────────────────────────────────────────────────

    /**
     * Resolve the active character ID lazily so gameState has time to hydrate.
     * Falls back to localStorage.
     */
    function getCharId() {
        return window.gameState?.activeCharacterId
            || localStorage.getItem('activeCharId')
            || null;
    }

    /**
     * Resolve the Supabase client lazily.
     * Prefers the global instance; falls back to constructing one.
     * Note: the standard CDN global is `window.supabase` (lowercase), not `Supabase`.
     */
    function getSupabase() {
        if (window.twSupabase) return window.twSupabase;
        if (typeof window.supabase?.createClient === 'function') {
            window.twSupabase = window.supabase.createClient(
                window.twGlobals?.supabaseUrl || '<?php echo esc_js( trailingslashit( twsupabaseurl() ) ); ?>',
                window.twGlobals?.anonKey     || '<?php echo esc_js( twsupabaseanonkey() ); ?>'
            );
            return window.twSupabase;
        }
        return null;
    }

    // ── CACHED DOM REFS ───────────────────────────────────────────────────────

    let _bar  = null;
    let _list = null;

    function getBar()  { return _bar  || (_bar  = document.getElementById('quick-actions-bar')); }
    function getList() { return _list || (_list = document.getElementById('user-actions-list')); }

    // ── DATA LOADING ──────────────────────────────────────────────────────────

    async function loadAllData() {
        const sb     = getSupabase();
        const charId = getCharId();
        if (!sb || !charId) return;

        try {
            const [{ data: actions }, { data: cmb }, { data: ua }] = await Promise.all([
                sb.from('cyber_quick_actions').select('*').order('display_order'),
                sb.from('cyber_combos').select('*'),
                sb.from('cyber_user_actions').select('*').eq('character_id', charId)
            ]);
            allActions  = actions || [];
            combos      = cmb     || [];
            userActions = ua      || [];
        } catch (e) {
            console.error('QA Load Error:', e);
            return;
        }

        try { renderQuickActionsUI([...allActions, ...combos]); }
        catch (e) { console.error('QA Render Error (main):', e); }

        try { renderUserActions(); }
        catch (e) { console.error('QA Render Error (user actions):', e); }
    }

    // ── AVAILABILITY CHECK ────────────────────────────────────────────────────

    function isActionAvailable(action) {
        if (action.is_permanent) return true;

        // Normalise required tags from either column, filtering out empty strings
        const reqTags = (
            action.required_tags
                ? action.required_tags.split(',')
                : action.required_tag
                    ? [action.required_tag]
                    : []
        ).map(t => t.trim()).filter(Boolean);

        if (!reqTags.length) return true;
        return reqTags.some(tag => playerTagSet.has(tag));
    }

    // ── RENDER: MAIN ACTIONS ──────────────────────────────────────────────────

    function renderQuickActionsUI(actions) {
        const bar = getBar();
        if (!bar) return;

        const available = (actions || [])
            .filter(isActionAvailable)
            .filter(a => currentFilter === 'ALL'
                || (a.category || '').toLowerCase() === currentFilter.toLowerCase()
            );

        const fragment = document.createDocumentFragment();
        available.forEach(action => {
            const btn      = document.createElement('button');
            const category = (action.category
                || (action.type === 'Combo' ? 'combo' : 'universal')
            ).toLowerCase();
            btn.className = `qa-btn qa-${category}`;
            btn.innerHTML = `<span class="qa-label">${escapeHtml(action.label)}</span>`;
            btn.addEventListener('click', () => handleQuickActionClick(action.template));
            fragment.appendChild(btn);
        });

        bar.innerHTML = '';
        bar.appendChild(fragment);

        // Restore active class on the matching filter button
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.classList.toggle(
                'active',
                (btn.dataset.filter || btn.textContent.trim()) === currentFilter
            );
        });
    }

    // ── RENDER: USER ACTIONS ──────────────────────────────────────────────────

    function renderUserActions() {
        const list = getList();
        if (!list) return;

        const fragment = document.createDocumentFragment();
        (userActions || []).forEach(action => {
            const wrapper = document.createElement('div');
            wrapper.style.cssText = 'display:flex;gap:6px;align-items:center;';

            const btn      = document.createElement('button');
            const category = (action.category || 'universal').toLowerCase();
            btn.className  = `qa-btn qa-${category}`;
            btn.innerHTML  = `<span class="qa-label">${escapeHtml(action.label)}</span>`;
            btn.addEventListener('click', () => handleQuickActionClick(action.template));
            wrapper.appendChild(btn);

            if (deleteMode) {
                const del   = document.createElement('button');
                del.className   = 'qa-delete';
                del.title       = 'Delete';
                del.textContent = '[X]';
                del.addEventListener('click', () => deleteUserAction(action.id));
                wrapper.appendChild(del);
            }

            fragment.appendChild(wrapper);
        });

        list.innerHTML = '';
        list.appendChild(fragment);
    }

    // ── ACTION CLICK ──────────────────────────────────────────────────────────

    /**
     * Paste the action template into the chat input.
     * Dispatches input + change events so reactive frameworks (React, Vue, game
     * engines) pick up the programmatic value change.
     */
    window.handleQuickActionClick = function(template) {
        const input =
            window.gameState?.userInput ||
            document.querySelector('#chat-input-field');
        if (!input) return;

        const text = (template || '').replace(
            /\[WeaponTag\]/g,
            window.twCurrentWeaponTag || '#Unarmed'
        );
        input.value = text;
        input.dispatchEvent(new Event('input',  { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();

        const start = text.indexOf('[');
        const end   = text.indexOf(']', start);
        if (start !== -1 && end > start) {
            input.setSelectionRange(start, end + 1);
        }
    };

    // ── PUBLIC API ────────────────────────────────────────────────────────────

    window.refreshQuickActions = async function() {
        await loadAllData();
    };

    /** Called by inventory/deck module to update which tags the player holds. */
    window.twUpdatePlayerTags = function(tags) {
        playerTagSet = new Set(
            Array.isArray(tags) ? tags.map(t => t.trim()).filter(Boolean) : []
        );
        try { renderQuickActionsUI([...allActions, ...combos]); }
        catch (e) { console.error('QA Tag-refresh Error:', e); }
    };

    // ── UI CONTROLS ──────────────────────────────────────────────────────────

    window.toggleQAManager = function() {
        const panel  = document.getElementById('qa-manager-panel');
        const toggle = document.getElementById('qa-manager-toggle');
        if (!panel || !toggle) return;

        const isHidden = panel.style.display === 'none' || !panel.style.display;
        panel.style.display = isHidden ? 'block' : 'none';
        toggle.textContent  = isHidden ? '[-] CMD_CENTER' : '[+] CMD_CENTER';
    };

    window.toggleDeleteMode = function() {
        deleteMode = !deleteMode;
        const btn = document.getElementById('toggle-delete-mode-btn');
        if (btn) btn.textContent = deleteMode ? '[✓] DEL_MODE' : '[x] DEL_MODE';
        renderUserActions();
    };

    window.setQAFilter = function(filter, ev) {
        currentFilter = filter;
        // Normalise event: support both direct calls and inline onclick
        const event = ev || window.event;
        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        if (event?.target?.classList) event.target.classList.add('active');
        renderQuickActionsUI([...allActions, ...combos]);
    };

    // Debounced search – avoids a full re-render on every keypress
    let _searchTimer = null;
    window.twLoadQuickActions = function() {
        clearTimeout(_searchTimer);
        _searchTimer = setTimeout(() => {
            const input  = document.getElementById('qa-search-input');
            const search = (input?.value || '').toLowerCase();

            const filtered = [...allActions, ...combos].filter(a =>
                (a.label    || '').toLowerCase().includes(search) ||
                (a.template || '').toLowerCase().includes(search)
            );
            // Respect current category filter while searching
            renderQuickActionsUI(filtered);
        }, 200);
    };

    window.saveCustomAction = async function() {
        const label    = document.getElementById('custom-label')?.value    || '';
        const template = document.getElementById('custom-template')?.value || '';
        const category = document.getElementById('custom-category')?.value || 'universal';

        if (!label || !template) { alert('Label and Prompt are required!'); return; }

        const sb     = getSupabase();
        const charId = getCharId();
        if (!sb || !charId) return;

        const { error } = await sb.from('cyber_user_actions').insert({
            character_id: charId,
            label,
            template,
            category
        });

        if (!error) {
            const labelEl    = document.getElementById('custom-label');
            const templateEl = document.getElementById('custom-template');
            if (labelEl)    labelEl.value    = '';
            if (templateEl) templateEl.value = '';
            await loadAllData();
        } else {
            console.error('Save custom action error:', error);
        }
    };

    window.deleteUserAction = async function(id) {
        const sb = getSupabase();
        if (!sb || !id) return;
        if (!confirm('Delete custom action?')) return;

        const { error } = await sb.from('cyber_user_actions').delete().eq('id', id);
        if (!error) await loadAllData();
        else        console.error('Delete user action error:', error);
    };

    // ── UTILITIES ─────────────────────────────────────────────────────────────

    /** Minimal HTML escaper – prevents XSS when injecting action labels. */
    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── INIT ──────────────────────────────────────────────────────────────────

    $(document).ready(function() {
        loadAllData();

        if (!resizeRegistered) {
            resizeRegistered = true;
            const bar      = getBar();
            const applyWrap = () => {
                if (bar) bar.style.flexWrap = $(window).width() < 768 ? 'wrap' : 'nowrap';
            };
            applyWrap();
            $(window).on('resize.qaResize', applyWrap);
        }
    });

})(jQuery);
</script>
<?php endif;
