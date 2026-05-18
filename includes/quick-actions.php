<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * NEOWEAVER - QUICK ACTIONS (SERVER-SIDE COOLDOWN)
 * Hook: wp_footer, priorytet 45
 */
add_action( 'wp_footer', function () {
    if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
        return;
    }
    ?>
    <script>
    (function() {
        window.isDeleteMode    = false;
        window.currentQAFilter = 'ALL';

        // ------------------------------------------------------------------
        // UI helpers
        // ------------------------------------------------------------------
        window.toggleQAManager = function() {
            const panel = document.getElementById('qa-manager-panel');
            const btn   = document.getElementById('qa-manager-toggle');
            if (!panel || !btn) return;
            const isOpen        = panel.style.display === 'block';
            panel.style.display = isOpen ? 'none' : 'block';
            btn.innerText       = isOpen ? '[+] CMD_CENTER' : '[-] CLOSE_CENTER';
        };

        // ------------------------------------------------------------------
        // Countdown timer — działa na data-atrybucie, bez localStorage
        // ------------------------------------------------------------------
        let _cdInterval = null;

        function startCooldownUI(btn, seconds) {
            btn.disabled      = true;
            btn.style.opacity = '0.5';
            btn.style.cursor  = 'not-allowed';

            const originalHtml = btn.innerHTML;
            let remaining      = seconds;

            btn.textContent = `⏳ ${remaining}s`;

            if (_cdInterval) clearInterval(_cdInterval);
            _cdInterval = setInterval(() => {
                remaining--;
                if (remaining <= 0) {
                    clearInterval(_cdInterval);
                    _cdInterval = null;
                    // Przycisk odżywa — pełny reload żeby pobrać aktualny stan z serwera
                    if (window.twGameReady) window.twLoadQuickActions();
                } else {
                    btn.textContent = `⏳ ${remaining}s`;
                }
            }, 1000);
        }

        // ------------------------------------------------------------------
        // Główna akcja kliknięcia
        // ------------------------------------------------------------------
        window.handleQAClick = async function(template, isCombo = false, comboId = null, clickedBtn = null) {
    if (window.isDeleteMode) return;

    if (isCombo && comboId) {
        const charId = window.twGameState?.currentCharacterId;
        if (!charId) return;

        const btn = clickedBtn;
        if (btn) {
            btn.disabled = true;
            btn.style.opacity = '0.5';
        }

        const { data, error } = await window.twSupabase.rpc('cyber_use_combo_ability', {
            p_character_id: charId,
            p_combo_key: comboId,
            p_cooldown_secs: 30
        });

        if (error || !data?.success) {
            if (btn) {
                btn.disabled = false;
                btn.style.opacity = '1';
            }
            if (data?.remaining && btn) {
                startCooldownUI(btn, data.remaining);
            }
            return;
        }

        if (btn) startCooldownUI(btn, 30);
    }

    const inputField = document.getElementById('chat-input');
    if (inputField) {
        inputField.value = template;
        inputField.focus();
        if (!template.includes('[')) {
            document.querySelector('.send-button')?.click();
        }
    }
};

        // ------------------------------------------------------------------
        // Helpers
        // ------------------------------------------------------------------
        function normalizeArchetype(str) {
            if (!str) return 'DEFAULT';
            return str.toUpperCase().replace('THE ', '').trim();
        }

        // ------------------------------------------------------------------
        // Ładowanie przycisków z Supabase
        // ------------------------------------------------------------------
        window.twLoadQuickActions = async function() {
    const client = window.twSupabase;
    const charId = window.twGameState?.currentCharacterId;

    if (!client || !charId) {
        console.warn('QA: Waiting for Master Bootstrapper...');
        return;
    }

    const rawArchetype     = window.twGameState?.currentArchetype || 'DEFAULT';
    const currentArchetype = normalizeArchetype(rawArchetype);
    const playerTags       = window.currentPlayerTags || [];

    console.log(`⚡ QA REFRESH | Szukam: "${currentArchetype}" (Oryginał: ${rawArchetype})`);

    try {
        const { data: actionsData, error } = await client
            .from('cyber_quick_actions')
            .select('*')
            .order('action_slot', { ascending: true });

        if (error) throw error;

        const [combosRes] = await Promise.all([
            client.from('cyber_combos').select('*'),
            client.from('cyber_user_actions').select('*').eq('character_id', charId)
        ]);

        const bar = document.getElementById('quick-actions-bar');
        if (!bar) return;

        bar.innerHTML = '';

        if (!bar.dataset.qaBound) {
            bar.addEventListener('click', function(e) {
                const btn = e.target.closest('button[data-template]');
                if (!btn) return;

                const template = btn.dataset.template || '';
                const isCombo  = btn.dataset.isCombo === 'true';
                const comboId  = btn.dataset.comboId || null;

                window.handleQAClick(template, isCombo, comboId, btn);
            });
            bar.dataset.qaBound = 'true';
        }

        [1, 2, 3, 4].forEach(slotNum => {
            const actionsInSlot = (actionsData || []).filter(a => a.action_slot === slotNum);

            const match =
                actionsInSlot.find(a => normalizeArchetype(a.required_archetype) === currentArchetype) ||
                actionsInSlot.find(a => normalizeArchetype(a.required_archetype) === 'DEFAULT');

            if (!match) return;

            let borderColor = 'var(--tw-monitor, #00d2ff)';
            if (normalizeArchetype(match.required_archetype) !== 'DEFAULT') {
                if (currentArchetype === 'JUGGERNAUT') borderColor = '#ff4444';
                else if (currentArchetype === 'GHOST') borderColor = '#d946ef';
                else if (currentArchetype === 'CONDUIT') borderColor = '#8b5cf6';
                else if (currentArchetype === 'ICON') borderColor = '#adff00';
            }

            const glowRgb =
                borderColor === '#ff4444' ? '255,68,68' :
                borderColor === '#d946ef' ? '217,70,239' :
                borderColor === '#8b5cf6' ? '139,92,246' :
                borderColor === '#adff00' ? '173,255,0' :
                '0,210,255';

            const btn = document.createElement('button');
            btn.className = 'qa-btn';
            btn.type = 'button';
            btn.dataset.template = match.template || '';
            btn.dataset.isCombo = 'false';
            btn.textContent = match.label || 'Action';

            btn.style.border = `1px solid ${borderColor}`;
            btn.style.borderRadius = '12px';
            btn.style.padding = '12px 18px';
            btn.style.background = 'rgba(3,7,18,0.8)';
            btn.style.backdropFilter = 'blur(10px)';
            btn.style.color = borderColor;
            btn.style.cursor = 'pointer';
            btn.style.fontWeight = '700';
            btn.style.textTransform = 'uppercase';
            btn.style.fontSize = '11px';
            btn.style.letterSpacing = '1px';
            btn.style.boxShadow = `0 4px 12px rgba(0,0,0,0.6),0 0 12px rgba(${glowRgb},0.3)`;
            btn.style.transition = 'all 0.3s ease';
            btn.style.marginRight = '8px';

            btn.addEventListener('mouseover', () => {
                btn.style.transform = 'scale(1.05)';
            });

            btn.addEventListener('mouseout', () => {
                btn.style.transform = 'scale(1)';
            });

            bar.appendChild(btn);
        });

        const availableCombos = (combosRes.data || []).filter(c => {
            if (normalizeArchetype(c.required_archetype) !== currentArchetype) return false;
            if (!c.required_tags || c.required_tags.length === 0) return true;

            return c.required_tags.some(reqTag =>
                playerTags.some(pt => String(pt).toLowerCase() === String(reqTag).toLowerCase())
            );
        });

        function safeColor(value, fallback = '#adff00') {
            return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(String(value || '').trim())
                ? String(value).trim()
                : fallback;
        }

        availableCombos.forEach(combo => {
            const glow = safeColor(combo.glow_color, '#adff00');

            const btn = document.createElement('button');
            btn.className = 'combo-btn';
            btn.type = 'button';
            btn.dataset.template = combo.template || '';
            btn.dataset.isCombo = 'true';
            btn.dataset.comboId = combo.id || '';
            btn.textContent = `⚡ ${combo.label || 'Combo'}`;

            btn.style.border = `2px double ${glow}`;
            btn.style.boxShadow = `0 0 16px ${glow}`;
            btn.style.borderRadius = '12px';
            btn.style.padding = '12px 18px';
            btn.style.background = 'rgba(3,7,18,0.9)';
            btn.style.color = glow;
            btn.style.cursor = 'pointer';
            btn.style.fontWeight = '700';
            btn.style.textTransform = 'uppercase';
            btn.style.marginRight = '8px';

            bar.appendChild(btn);
        });

        const container = document.getElementById('quick-actions-container');
        if (container) container.style.display = 'block';

    } catch (err) {
        console.error('QA Load Error:', err);
    }
};
        // ------------------------------------------------------------------
        // Eventy
        // ------------------------------------------------------------------
        document.addEventListener('twTagsUpdated', function() {
            if (window.twGameReady) window.twLoadQuickActions();
        });

        window.twUpdatePlayerTags = function(tags) {
            window.currentPlayerTags = Array.isArray(tags) ? tags : [];
            document.dispatchEvent(new CustomEvent('twTagsUpdated', { detail: window.currentPlayerTags }));
        };

        if (window.twGameReady) {
            window.twLoadQuickActions();
        } else {
            document.addEventListener('twGameStateHydrated', window.twLoadQuickActions);
        }
    })();
    </script>
    <?php
}, 45 );
