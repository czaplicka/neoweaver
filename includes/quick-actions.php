<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TALE WEAVER - QUICK ACTIONS (LOOM SYNCED)
 * Przyciski szybkich akcji, combo system z cooldown.
 * Hook: wp_footer, priorytet 45 (po inventory-system.php który ma 40).
 */
add_action( 'wp_footer', function () {
	if ( ! is_page_template( 'templates/adventure.php' ) || ! get_current_user_id() ) {
		return;
	}
	?>
	<script>
	(function() {
	    const COMBO_COOLDOWN_TIME  = 30;
	    window.isDeleteMode        = false;
	    window.currentQAFilter     = 'ALL';

	    window.toggleQAManager = function() {
	        const panel = document.getElementById('qa-manager-panel');
	        const btn   = document.getElementById('qa-manager-toggle');
	        if (!panel || !btn) return;
	        const isOpen        = panel.style.display === 'block';
	        panel.style.display = isOpen ? 'none' : 'block';
	        btn.innerText       = isOpen ? '[+] CMD_CENTER' : '[-] CLOSE_CENTER';
	    };

	    window.handleQAClick = function(template, isCombo = false, comboId = null) {
	        if (window.isDeleteMode) return;

	        if (isCombo && comboId) {
	            const now   = Date.now();
	            const cdKey = `combo_cd_${comboId}`;
	            const last  = localStorage.getItem(cdKey);
	            if (last && (now - parseInt(last, 10)) < COMBO_COOLDOWN_TIME * 1000) return;
	            localStorage.setItem(cdKey, now.toString());
	            window.twLoadQuickActions();
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

	    function normalizeArchetype(str) {
	        if (!str) return 'DEFAULT';
	        return str.toUpperCase().replace('THE ', '').trim();
	    }

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

	            const [combosRes, userRes] = await Promise.all([
	                client.from('cyber_combos').select('*'),
	                client.from('cyber_user_actions').select('*').eq('character_id', charId)
	            ]);

	            let finalActionsHtml = '';
	            const slots = [1, 2, 3, 4];

	            slots.forEach(slotNum => {
	                const actionsInSlot = (actionsData || []).filter(a => a.action_slot === slotNum);

	                let match = actionsInSlot.find(a =>
	                    normalizeArchetype(a.required_archetype) === currentArchetype
	                );
	                if (!match) {
	                    match = actionsInSlot.find(a =>
	                        normalizeArchetype(a.required_archetype) === 'DEFAULT'
	                    );
	                }

	                if (match) {
	                    let borderColor = 'var(--tw-monitor, #00d2ff)';
	                    if (currentArchetype === 'JUGGERNAUT') borderColor = '#ff4444';
	                    else if (currentArchetype === 'GHOST')   borderColor = '#d946ef';
	                    else if (currentArchetype === 'CONDUIT') borderColor = '#8b5cf6';
	                    else if (currentArchetype === 'ICON')    borderColor = '#adff00';
	                    if (normalizeArchetype(match.required_archetype) === 'DEFAULT') {
	                        borderColor = 'var(--tw-monitor, #00d2ff)';
	                    }
	                    const glowRgb = borderColor === '#ff4444' ? '255,68,68' : '0,210,255';

	                    finalActionsHtml += `
	                    <button
	                        class="qa-btn"
	                        onclick="window.handleQAClick('${match.template}')"
	                        style="
	                            border: 1px solid ${borderColor};
	                            border-radius: 12px; padding: 12px 18px;
	                            background: rgba(3,7,18,0.8); backdrop-filter: blur(10px);
	                            color: ${borderColor}; cursor: pointer;
	                            font-weight: 700; text-transform: uppercase; font-size: 11px;
	                            letter-spacing: 1px;
	                            box-shadow: 0 4px 12px rgba(0,0,0,0.6), 0 0 12px rgba(${glowRgb},0.3);
	                            transition: all 0.3s ease; margin-right: 8px;
	                        "
	                        onmouseover="this.style.transform='scale(1.05)'"
	                        onmouseout="this.style.transform='scale(1)'"
	                    >
	                        ${match.label}
	                    </button>`;
	                }
	            });

	            const availableCombos = (combosRes.data || []).filter(c => {
	                if (normalizeArchetype(c.required_archetype) !== currentArchetype) return false;
	                if (!c.required_tags || c.required_tags.length === 0) return true;
	                return c.required_tags.some(reqTag =>
	                    playerTags.some(pt => pt.toLowerCase() === reqTag.toLowerCase())
	                );
	            });

	            const combosHtml = availableCombos.map(combo => {
	                const key         = `combo_cd_${combo.id}`;
	                const lastUsed    = localStorage.getItem(key);
	                const cdRemaining = lastUsed
	                    ? COMBO_COOLDOWN_TIME * 1000 - (Date.now() - parseInt(lastUsed, 10))
	                    : 0;

	                if (cdRemaining > 0) {
	                    return `
	                    <button style="opacity:0.5; cursor:not-allowed; padding: 10px;
	                        border: 1px solid #555; background: #222; color: #888;
	                        border-radius: 12px;" disabled>
	                        ⏳ ${Math.ceil(cdRemaining / 1000)}s
	                    </button>`;
	                }

	                const glow = combo.glow_color || '#adff00';
	                return `
	                <button
	                    class="combo-btn"
	                    onclick="window.handleQAClick('${combo.template}', true, '${combo.id}')"
	                    style="
	                        border: 2px double ${glow}; box-shadow: 0 0 16px ${glow};
	                        border-radius: 12px; padding: 12px 18px;
	                        background: rgba(3,7,18,0.9); color: ${glow};
	                        cursor: pointer; font-weight: 700; text-transform: uppercase;
	                        margin-right: 8px;
	                    "
	                >
	                    ⚡ ${combo.label}
	                </button>`;
	            }).join('');

	            const bar = document.getElementById('quick-actions-bar');
	            if (bar) bar.innerHTML = finalActionsHtml + combosHtml;

	            const container = document.getElementById('quick-actions-container');
	            if (container) container.style.display = 'block';

	        } catch (err) {
	            console.error('QA Load Error:', err);
	        }
	    };

	    window.twUpdatePlayerTags = function(tags) {
	        window.currentPlayerTags = Array.isArray(tags) ? tags : [];
	        if (window.twGameReady) window.twLoadQuickActions();
	    };

	    setInterval(() => {
	        if (document.querySelector('button[disabled]')) window.twLoadQuickActions();
	    }, 1000);

	    if (window.twGameReady) {
	        window.twLoadQuickActions();
	    } else {
	        document.addEventListener('twGameStateHydrated', window.twLoadQuickActions);
	    }
	})();
	</script>
	<?php
}, 45 );
