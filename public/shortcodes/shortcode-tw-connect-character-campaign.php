<?php
/**
 * SHORTCODE: [tw_connect_character_campaign]
 * NEOWEAVE AGENT INJECTION (World-Lock Protocol)
 * World-Lock: agent locked to world via cyber_campaign_worlds junction table
 *
 * Fixed & optimised – see changelog in comments below.
 *
 * CHANGELOG
 * ---------
 * 1. Race-condition guard: `isValidating` flag prevents concurrent world-lock
 *    requests when the user changes both selects quickly.
 * 2. Consistent ID typing: all IDs are kept as strings throughout to avoid
 *    mixed parseInt / loose-equality bugs (was: parseInt in submit but == in
 *    world-lock compare, causing subtle mismatches on large IDs).
 * 3. Debounced search inputs (150 ms) – no more full re-renders on every
 *    keystroke.
 * 4. audio.play() wrapped in Promise.catch() – silences the unhandled-
 *    rejection DOMException thrown when autoplay is blocked by the browser.
 * 5. Redirect stored in a variable and cleared on page-hide so the 1 500 ms
 *    timer can't fire after the user has already navigated away.
 * 6. Empty/placeholder options given value="" and filtered out before
 *    enabling the submit button, closing the gap where value="" passed the
 *    falsy check but still reached the API.
 * 7. Option labels sanitised with a small escapeHtml() helper – prevents
 *    stored-XSS if a character/campaign name ever contains HTML entities.
 * 8. aria-disabled kept in sync with the button's disabled state for
 *    screen-readers (clip-path hides the default disabled look visually).
 * 9. store reset to [] at the top of init() so a manual re-init doesn't
 *    leave stale data.
 *10. Single onchange handler replaced with explicit onchange on each select –
 *    avoids the handler firing for the text inputs inside the same <form>.
 */
function tw_connect_character_campaign_direct_v2() {
    if ( ! is_user_logged_in() ) {
        return '<p class="tw-message">UPLINK REQUIRED. IDENTIFY YOURSELF, FIELD AGENT.</p>';
    }

    $user_id      = get_current_user_id();
    $supabase_url = trailingslashit( tw_supabase_url() );
    $anon_key     = tw_supabase_anon_key();

    ob_start(); ?>
    <div id="tw-deployment-root" class="tw-deployment-main-container">

        <audio id="tw-glitch-sound" src="https://cyber.nieodparady.pl/wp-content/uploads/2026/02/soundreality-glitch-177348.mp3" preload="auto"></audio>

        <section class="tw-briefing-hero">
            <div class="tw-hero-overlay"></div>
            <div class="tw-hero-content">
                <div class="tw-hero-text">
                    <span class="tw-label-alt">AGENT INJECTION PROTOCOL</span>
                    <h1>DEPLOYING THE AGENT</h1>
                    <p>Operator, select a verified Agent entity to inhabit the targeted Deployment matrix.
                       Note: Agents are locked to the World Node of their first deployment.</p>
                </div>
                <div class="tw-hero-stats">
                    <div class="tw-hero-stat-item"><span class="n" id="stat-latency">0.024</span><span class="l">LATENCY</span></div>
                    <div class="tw-hero-stat-item"><span class="n">STABLE</span><span class="l">NODE SYNC</span></div>
                    <div class="tw-hero-stat-item tw-pulse-stat"><span class="n">READY</span><span class="l">INJECTION</span></div>
                </div>
            </div>
        </section>

        <div class="tw-deploy-grid">
            <div class="tw-deploy-controls">
                <div id="tw-char-status-console" class="tw-console-box">
                    > System: Initializing Agent Assignment Interface...
                </div>

                <form id="tw-char-connect-form" class="tw-form-layout">
                    <div class="tw-selection-group">
                        <div class="tw-field-box">
                            <label for="select-camp-char"><i class="dashicons dashicons-backup"></i> TARGET: DEPLOYMENTS (without agent)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="search-camp-char" class="tw-input-cyber" placeholder="Filter matrices..." autocomplete="off">
                                <select id="select-camp-char" class="tw-select-cyber" size="6" required aria-label="Select deployment"></select>
                            </div>
                        </div>

                        <div class="tw-field-box">
                            <label for="select-char"><i class="dashicons dashicons-admin-users"></i> SUBJECT: AGENTS (Persona)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="search-char" class="tw-input-cyber" placeholder="Locate entity..." autocomplete="off">
                                <select id="select-char" class="tw-select-cyber" size="6" required aria-label="Select agent"></select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="btn-connect-char" class="tw-btn-deploy" disabled aria-disabled="true">
                        EXECUTE INJECTION [ENTER]
                    </button>

                    <div class="tw-world-lock-note">
                        <h4>WORLD-LOCK PROTOCOL</h4>
                        <p>
                            An Agent can join multiple Deployments only within its origin World Node.
                            Cross-world injection will be rejected by Neoweave safety protocols.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    (function() {
        'use strict';

        const config = {
            url: "<?php echo esc_js( $supabase_url ); ?>",
            key: "<?php echo esc_js( $anon_key ); ?>",
            uid: <?php echo (int) $user_id; ?>
        };

        /* ── DOM refs ── */
        const selCamp = document.getElementById('select-camp-char');
        const selChar = document.getElementById('select-char');
        const btn     = document.getElementById('btn-connect-char');
        const status  = document.getElementById('tw-char-status-console');
        const form    = document.getElementById('tw-char-connect-form');
        const audio   = document.getElementById('tw-glitch-sound');
        const root    = document.getElementById('tw-deployment-root');

        /* ── State ── */
        let store        = { campaigns: [], characters: [] };
        let isValidating = false;  // FIX #1 – race-condition guard
        let redirectTimer = null;  // FIX #5 – clearable redirect

        /* ── Latency ticker ── */
        setInterval(() => {
            const l = document.getElementById('stat-latency');
            if (l) l.textContent = (0.020 + Math.random() * 0.010).toFixed(3);
        }, 3000);

        /* ── Helpers ── */

        // FIX #7 – XSS-safe label builder
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        // FIX #8 – keep aria-disabled in sync
        function setBtn(enabled) {
            btn.disabled   = !enabled;
            btn.setAttribute('aria-disabled', String(!enabled));
        }

        function setStatus(msg, color = '#00e5ff') {
            status.style.color = color;
            status.textContent = msg;
        }

        // FIX #3 – debounce for search inputs
        function debounce(fn, delay) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), delay);
            };
        }

        /* ── Render helpers ── */
        function render(type, list, filter = '') {
            const el = (type === 'camp') ? selCamp : selChar;
            const lc = filter.toLowerCase();
            const filtered = list.filter(i => i.name.toLowerCase().includes(lc));

            // Build fragment off-DOM for one reflow
            const frag = document.createDocumentFragment();

            if (filtered.length === 0) {
                const opt = new Option('-- NO DATA --', '');
                opt.disabled = true;
                frag.appendChild(opt);
            } else {
                filtered.forEach(i => {
                    // FIX #7 – escape names before building label
                    let label = escapeHtml(i.name).toUpperCase();
                    if (type === 'char') {
                        const raceLabel  = escapeHtml(i.race_name  || i.race_id  || '-');
                        const classLabel = escapeHtml(i.class_name || i.class_id || '-');
                        label += ` [${raceLabel} | ${classLabel}]`;
                    }
                    // FIX #2 – keep IDs as strings; no parseInt here
                    const opt = new Option(label, String(i.id));
                    frag.appendChild(opt);
                });
            }

            el.innerHTML = '';
            el.appendChild(frag);
        }

        /* ── Initialise ── */
        async function init() {
            // FIX #9 – reset store before re-init
            store = { campaigns: [], characters: [] };
            setBtn(false);
            setStatus('> System: Calibrating Uplink with The Weave...');

            const headers = {
                'apikey':        config.key,
                'Authorization': `Bearer ${config.key}`
            };

            try {
                const [rC, rCh] = await Promise.all([
                    fetch(
                        config.url + 'rest/v1/cyber_campaigns_ready_for_agent?select=id,name,world_id&order=created_at.desc',
                        { headers }
                    ),
                    fetch(
                        config.url + 'rest/v1/cyber_characters?select=id,name,race_id,class_id,cyber_races(name),cyber_classes(name)&wp_user_id=eq.' + config.uid,
                        { headers }
                    )
                ]);

                if (!rC.ok || !rCh.ok) {
                    const cText  = !rC.ok  ? await rC.text()  : '';
                    const chText = !rCh.ok ? await rCh.text() : '';
                    console.error('Supabase error campaigns:',  rC.status,  cText);
                    console.error('Supabase error characters:', rCh.status, chText);
                    setStatus('> Error: Supabase HTTP ' + rC.status + ' / ' + rCh.status, '#ff0055');
                    return;
                }

                const rawCamps = await rC.json();
                store.campaigns = rawCamps.map(item => ({
                    id:       String(item.id),  // FIX #2 – keep as string
                    name:     item.name,
                    world_id: item.world_id != null ? String(item.world_id) : null
                }));

                const rawChars = await rCh.json();
                store.characters = rawChars.map(ch => ({
                    id:         String(ch.id),  // FIX #2
                    name:       ch.name,
                    race_id:    ch.race_id,
                    class_id:   ch.class_id,
                    race_name:  ch.cyber_races   ? ch.cyber_races.name   : null,
                    class_name: ch.cyber_classes ? ch.cyber_classes.name : null
                }));

                render('camp', store.campaigns);
                render('char', store.characters);
                setStatus('> System: Assets synchronized. Ready for Injection.');
            } catch (e) {
                console.error('INIT ERROR', e);
                setStatus('> Error: Uplink rejected. Interference detected.', '#ff0055');
            }
        }

        /* ── Search inputs (debounced) ── */
        // FIX #3
        document.getElementById('search-camp-char').addEventListener(
            'input',
            debounce(e => render('camp', store.campaigns, e.target.value), 150)
        );
        document.getElementById('search-char').addEventListener(
            'input',
            debounce(e => render('char', store.characters, e.target.value), 150)
        );

        /* ── World-Lock validation ── */
        // FIX #10 – listen on individual selects, not the whole form
        async function onSelectionChange() {
            setBtn(false);

            const campVal = selCamp.value;
            const charVal = selChar.value;

            // FIX #6 – guard against empty/placeholder options
            if (!campVal || !charVal) return;

            // FIX #1 – prevent concurrent validation
            if (isValidating) return;
            isValidating = true;

            setStatus('> System: Validating World-Lock constraints...');

            const selectedCamp = store.campaigns.find(c => c.id === campVal); // FIX #2 – strict ===
            const headers      = { 'apikey': config.key, 'Authorization': `Bearer ${config.key}` };

            // No world_id on campaign → no lock needed
            if (!selectedCamp || selectedCamp.world_id == null) {
                allow('> System: No World-Lock constraint. Neural bridge open.');
                return;
            }

            try {
                // Step 1: has this agent ever been deployed?
                const resLinks = await fetch(
                    config.url + 'rest/v1/cyber_campaign_characters' +
                    '?character_id=eq.' + encodeURIComponent(charVal) +
                    '&select=campaign_id&limit=1',
                    { headers }
                );

                if (!resLinks.ok) {
                    console.error('World-lock step 1 error:', resLinks.status, await resLinks.text());
                    allowWithSkip();
                    return;
                }

                const links = await resLinks.json();

                if (links.length === 0) {
                    allow('> System: Neural bridge stable. World-Lock verified.');
                    return;
                }

                const firstCampaignId = String(links[0].campaign_id); // FIX #2

                // Step 2: get world_id of the agent's existing campaign
                const resWorld = await fetch(
                    config.url + 'rest/v1/cyber_campaign_worlds' +
                    '?campaign_id=eq.' + encodeURIComponent(firstCampaignId) +
                    '&select=world_id&limit=1',
                    { headers }
                );

                if (!resWorld.ok) {
                    console.error('World-lock step 2 error:', resWorld.status, await resWorld.text());
                    allowWithSkip();
                    return;
                }

                const worldRows = await resWorld.json();

                if (worldRows.length === 0 || worldRows[0].world_id == null) {
                    allow('> System: Neural bridge stable. World-Lock verified.');
                    return;
                }

                // Step 3: compare world IDs (both normalised to string – FIX #2)
                const agentWorldId = String(worldRows[0].world_id);

                if (agentWorldId !== selectedCamp.world_id) {
                    setStatus('> Violation: Agent is locked to another World Node.', '#ff0055');
                    // btn stays disabled
                    isValidating = false;
                    return;
                }

                allow('> System: Neural bridge stable. World-Lock verified.');

            } catch (err) {
                console.error('World-lock general error:', err);
                allowWithSkip();
            }

            function allow(msg) {
                isValidating = false;
                setStatus(msg);
                setBtn(true);
            }

            function allowWithSkip() {
                isValidating = false;
                setStatus('> System: World-Lock check skipped. Proceeding.');
                setBtn(true);
            }
        }

        selCamp.addEventListener('change', onSelectionChange);
        selChar.addEventListener('change', onSelectionChange);

        /* ── Submit ── */
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            setBtn(false);
            setStatus('> System: Injecting Agent data into Matrix...');

            // FIX #5 – cancel any previous stale redirect
            clearTimeout(redirectTimer);

            const payload = {
                campaign_id:   selCamp.value,   // FIX #2 – stay as string (Supabase handles int coercion)
                character_id:  selChar.value,
                creator_wp_id: config.uid
            };

            try {
                const res = await fetch(config.url + 'rest/v1/cyber_campaign_characters', {
                    method: 'POST',
                    headers: {
                        'apikey':        config.key,
                        'Authorization': `Bearer ${config.key}`,
                        'Content-Type':  'application/json',
                        'Prefer':        'resolution=merge-duplicates'
                    },
                    body: JSON.stringify(payload)
                });

                if (res.ok) {
                    // FIX #4 – guard against autoplay policy rejection
                    if (audio) {
                        audio.play().catch(err => console.warn('Audio autoplay blocked:', err));
                    }
                    root.classList.add('tw-glitch-shake');
                    setStatus('> System: INJECTION SUCCESSFUL. AGENT LINKED.', '#adff00');

                    // FIX #5 – store timer reference so it can be cancelled
                    redirectTimer = setTimeout(() => {
                        window.location.href = '/deployments/';
                    }, 1500);
                } else {
                    const txt = await res.text();
                    console.error('Injection error:', res.status, txt);
                    throw new Error('Rejection');
                }
            } catch (err) {
                setStatus('> Error: Injection failed. Entity rejection.', '#ff0055');
                setBtn(true);
            }
        });

        // FIX #5 – cancel redirect if user navigates away before it fires
        window.addEventListener('pagehide', () => clearTimeout(redirectTimer));

        init();
    })();
    </script>

    <style>
        .tw-deployment-main-container { max-width: 1200px; margin: 40px auto; font-family: 'Chakra Petch', sans-serif; background: #000; border: 1px solid #1a1a1a; position: relative; }
        .tw-briefing-hero { position: relative; height: 250px; background: #111 url('https://images.unsplash.com/photo-1550745165-9bc0b252726f?auto=format&fit=crop&q=80&w=1200') center/cover; display: flex; align-items: center; padding: 0 40px; border-bottom: 2px solid #adff00; }
        .tw-hero-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to right, #000 40%, transparent 100%); }
        .tw-hero-content { position: relative; z-index: 5; display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .tw-hero-text h1 { color: #adff00; font-size: 2.5rem; margin: 0; text-transform: uppercase; }
        .tw-hero-text p { color: #888; max-width: 450px; font-size: 0.9rem; }
        .tw-label-alt { color: #ff0055; font-size: 0.7rem; font-weight: bold; letter-spacing: 2px; }
        .tw-hero-stats { display: flex; gap: 20px; }
        .tw-hero-stat-item { background: rgba(255,255,255,0.05); padding: 10px 20px; border-left: 2px solid #adff00; display: flex; flex-direction: column; min-width: 100px; }
        .tw-hero-stat-item .n { color: #fff; font-weight: bold; font-size: 1.2rem; }
        .tw-hero-stat-item .l { color: #555; font-size: 0.6rem; text-transform: uppercase; }
        .tw-deploy-grid { display: block; padding: 40px; }
        .tw-console-box { background: #050505; border-left: 3px solid #00e5ff; padding: 15px; font-family: monospace; font-size: 0.8rem; color: #00e5ff; margin-bottom: 30px; min-height: 50px; }
        .tw-selection-group { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .tw-field-box label { display: block; color: #adff00; font-size: 0.75rem; margin-bottom: 10px; font-weight: bold; }
        .tw-input-cyber { width: 100%; background: #111; border: 1px solid #333; color: #fff; padding: 10px; font-size: 0.8rem; border-bottom: none; box-sizing: border-box; }
        .tw-select-cyber { width: 100%; background: #080808; border: 1px solid #222; color: #00e5ff; padding: 10px; font-size: 0.8rem; outline: none; box-sizing: border-box; }
        .tw-select-cyber option { font-size: 0.8rem; }
        .tw-btn-deploy { width: 100%; padding: 20px; background: #adff00; color: #000; border: none; font-weight: 900; cursor: pointer; clip-path: polygon(0 0, 98% 0, 100% 20%, 100% 100%, 2% 100%, 0 80%); transition: background 0.3s; text-transform: uppercase; }
        .tw-btn-deploy:disabled { background: #1a1a1a; color: #333; cursor: not-allowed; }
        .tw-btn-deploy:hover:not(:disabled) { background: #fff; }
        .tw-pulse-stat { animation: tw-pulse 2s infinite; }
        @keyframes tw-pulse { 0% { opacity: 1; } 50% { opacity: 0.5; } 100% { opacity: 1; } }
        .tw-glitch-shake { animation: tw-shake 0.3s cubic-bezier(.36,.07,.19,.97) both; border-color: #ff0055 !important; box-shadow: 0 0 40px rgba(255, 0, 85, 0.4); }
        @keyframes tw-shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
            40%, 60% { transform: translate3d(4px, 0, 0); }
        }
        .tw-world-lock-note { margin-top: 16px; padding: 14px 18px; border: 1px solid #333; background: #050505; font-size: 0.8rem; color: #777; }
        .tw-world-lock-note h4 { margin: 0 0 6px; font-size: 0.8rem; color: #adff00; letter-spacing: 1px; }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('tw_connect_character_campaign', 'tw_connect_character_campaign_direct_v2');
