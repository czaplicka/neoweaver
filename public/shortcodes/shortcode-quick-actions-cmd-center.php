<?php
/**
 * TALE WEAVER – Quick Actions CMD_CENTER v2.0
 * Renders the Glass Terminal quick-actions bar only on the adventure page template.
 * Loaded via WPCode snippet or included in class-neoweaver-public.php.
 *
 * Tables used:
 *   cyber_quick_actions  – global actions (display_order, label, template, category, required_tag/s, is_permanent)
 *   cyber_combos         – combo actions
 *   cyber_user_actions   – per-character custom actions (character_id, label, template, category)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( is_page_template( 'templates/adventure.php' ) ) :
?>
<script>
// TW Quick Actions CMD_CENTER v2.0 - Glass Terminal + Combos
(function($) {
    let allActions = [], combos = [], userActions = [], currentFilter = 'ALL', deleteMode = false;
    let currentPlayerTags = []; // Global player tags (from inventory/deck)

    // Supabase client (reuse global instance if available)
    const supabase = window.twSupabase || (typeof Supabase !== 'undefined' && Supabase?.createClient(
        window.twGlobals?.supabaseUrl || '<?php echo trailingslashit( twsupabaseurl() ); ?>',
        window.twGlobals?.anonKey      || '<?php echo twsupabaseanonkey(); ?>'
    ));
    const currentCharId = window.gameState?.activeCharacterId || localStorage.getItem('activeCharId');

    /**
     * Load data from Supabase: actions + combos + user_actions
     */
    async function loadAllData() {
        if (!supabase || !currentCharId) return;
        try {
            const [{ data: actions }, { data: cmb }, { data: ua }] = await Promise.all([
                supabase.from('cyber_quick_actions').select('*').order('display_order'),
                supabase.from('cyber_combos').select('*'),
                supabase.from('cyber_user_actions').select('*').eq('character_id', currentCharId)
            ]);
            allActions  = actions || [];
            combos      = cmb    || [];
            userActions = ua     || [];

            renderQuickActionsUI([...allActions, ...combos]);
            renderUserActions();
        } catch(e) {
            console.error('QA Load Error:', e);
        }
    }

    /**
     * Check whether an action is available for the current player
     */
    function isActionAvailable(action) {
        if (action.is_permanent) return true;

        const reqTags = action.required_tag
            ? [action.required_tag]
            : action.required_tags
                ? action.required_tags.split(',')
                : [];

        if (!reqTags.length) return true;
        return reqTags.some(tag => currentPlayerTags.includes(tag.trim()));
    }

    /**
     * Render quick-action + combo buttons (no counters)
     */
    function renderQuickActionsUI(actions) {
        const bar = document.getElementById('quick-actions-bar');
        if (!bar) return;
        bar.innerHTML = '';

        const availableActions = (actions || [])
            .filter(isActionAvailable)
            .filter(a => currentFilter === 'ALL'
                || a.category?.toLowerCase() === currentFilter.toLowerCase()
            );

        availableActions.forEach(action => {
            const btn = document.createElement('button');
            const category = (action.category || (action.type === 'Combo' ? 'combo' : 'universal')).toLowerCase();
            btn.className = `qa-btn qa-${category}`;
            btn.innerHTML = `<span class="qa-label">${action.label}</span>`;
            btn.onclick   = () => handleQuickActionClick(action.template);
            bar.appendChild(btn);
        });
    }

    /**
     * Render custom user actions with optional delete mode
     */
    function renderUserActions() {
        const list = document.getElementById('user-actions-list');
        if (!list) return;
        list.innerHTML = '';

        (userActions || []).forEach(action => {
            const escapedTemplate = action.template.replace(/'/g, "\\'");
            const delBtn = deleteMode
                ? `<button class="qa-delete" onclick="deleteUserAction(${action.id})" title="Delete">[X]</button>`
                : '';

            list.innerHTML += `
                <div style="display:flex;gap:6px;align-items:center;">
                    <button
                        class="qa-btn qa-${(action.category || 'universal').toLowerCase()}"
                        onclick="handleQuickActionClick('${escapedTemplate}')"
                    >
                        <span class="qa-label">${action.label}</span>
                    </button>
                    ${delBtn}
                </div>
            `;
        });
    }

    /**
     * Handle button click – paste template into chat input with smart cursor
     */
    window.handleQuickActionClick = function(template) {
        const input =
            window.gameState?.userInput ||
            document.querySelector('#chat-input-field');
        if (!input) return;

        let text = template.replace(/\[WeaponTag\]/g, window.twCurrentWeaponTag || '#Unarmed');
        input.value = text;
        input.focus();

        const start = text.indexOf('[');
        const end   = text.indexOf(']', start);
        if (start !== -1 && end > start) {
            input.setSelectionRange(start, end + 1);
        }
    };

    /**
     * Refresh buttons after player tags change
     */
    window.refreshQuickActions = async function() {
        await loadAllData();
    };

    /**
     * Update player tags (called from inventory/deck module)
     */
    window.twUpdatePlayerTags = function(tags) {
        currentPlayerTags = Array.isArray(tags) ? tags.map(t => t.trim()) : [];
        renderQuickActionsUI([...allActions, ...combos]);
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
        const event = ev || window.event;

        document.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
        if (event?.target?.classList) event.target.classList.add('active');

        renderQuickActionsUI([...allActions, ...combos]);
    };

    window.twLoadQuickActions = function() {
        const input  = document.getElementById('qa-search-input');
        const search = (input?.value || '').toLowerCase();

        const filtered = [...allActions, ...combos].filter(a =>
            (a.label    || '').toLowerCase().includes(search) ||
            (a.template || '').toLowerCase().includes(search)
        );
        renderQuickActionsUI(filtered);
    };

    window.saveCustomAction = async function() {
        const label    = document.getElementById('custom-label')?.value    || '';
        const template = document.getElementById('custom-template')?.value || '';
        const category = document.getElementById('custom-category')?.value || 'universal';

        if (!label || !template) { alert('Label and Prompt are required!'); return; }
        if (!supabase || !currentCharId) return;

        const { error } = await supabase.from('cyber_user_actions').insert({
            character_id: currentCharId,
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
            console.error(error);
        }
    };

    window.deleteUserAction = async function(id) {
        if (!supabase || !id) return;
        if (!confirm('Delete custom action?')) return;

        const { error } = await supabase.from('cyber_user_actions')
            .delete()
            .eq('id', id);

        if (!error) await loadAllData();
        else        console.error(error);
    };

    // ── INIT ─────────────────────────────────────────────────────────────────

    $(document).ready(function() {
        loadAllData();

        // Flex-wrap for mobile / many buttons
        const bar      = $('#quick-actions-bar');
        const applyWrap = () => bar.css('flex-wrap', $(window).width() < 768 ? 'wrap' : 'nowrap');
        applyWrap();
        $(window).on('resize', applyWrap);
    });

})(jQuery);
</script>
<?php endif;
