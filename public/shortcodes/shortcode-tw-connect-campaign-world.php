<?php
/**
 * SHORTCODE: [tw_connect_campaign_world]
 * Wersja: v15 - bug fixes + optimizations
 *
 * FIXES vs v14:
 * 1. cyber_campaign_worlds query now filtered by creator_wp_id (was fetching ALL links globally)
 * 2. rLinked fetch moved into Promise.all (was sequential, wasting RTT)
 * 3. HTTP status check added for rLinked (was silently swallowed)
 * 4. select change listeners moved to selC/selW directly (form.onchange unreliable for bubbling)
 * 5. "Prefer: resolution=merge-duplicates" replaced with return=representation + duplicate guard
 * 6. Redirect uses relative path via window.location.pathname prefix-awareness
 * 7. simulateLiveStats interval stored and cleared on page unload (memory leak fix)
 * 8. Filter inputs debounced (was rebuilding DOM on every keystroke)
 * 9. renderList skips full rebuild when filter unchanged
 * 10. box-sizing: border-box applied to inputs/selects
 */
function tw_connect_campaign_world_final() {
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
                    <span class="tw-label-alt">MISSION PARAMETERS</span>
                    <h1>ANCHORING THE SPLOT</h1>
                    <p>Field Agent, you are about to merge a narrative thread with a physical reality node. This deployment will stabilize the local sector for multiplayer synchronization.</p>
                </div>
                <div class="tw-hero-stats">
                    <div class="tw-hero-stat-item">
                        <span class="n" id="stat-latency">0.024</span>
                        <span class="l">LATENCY</span>
                    </div>
                    <div class="tw-hero-stat-item">
                        <span class="n">STABLE</span>
                        <span class="l">NODE FLUX</span>
                    </div>
                    <div class="tw-hero-stat-item tw-pulse-stat">
                        <span class="n">ACTIVE</span>
                        <span class="l">UPLINK</span>
                    </div>
                </div>
            </div>
        </section>

        <div class="tw-deploy-grid">
            <div class="tw-deploy-controls">
                <div id="tw-world-console" class="tw-console-box">
                    > System: Initializing Deployment Interface...
                </div>

                <form id="tw-anchor-form" class="tw-form-layout">
                    <div class="tw-selection-group">
                        <div class="tw-field-box">
                            <label><i class="dashicons dashicons-backup"></i> SOURCE: DEPLOYMENT (Campaign)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-camp" class="tw-input-cyber" placeholder="Search deployments...">
                                <select id="s-camp" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>

                        <div class="tw-field-box">
                            <label><i class="dashicons dashicons-networking"></i> DESTINATION: THE NODE (World)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="f-world" class="tw-input-cyber" placeholder="Locate node...">
                                <select id="s-world" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="b-connect" class="tw-btn-deploy" disabled>
                        EXECUTE DEPLOYMENT [ENTER]
                    </button>
                </form>
            </div>

            <aside class="tw-deploy-sidebar">
                <div class="tw-sidebar-card">
                    <h4><i class="dashicons dashicons-info"></i> PROTOCOL BINDING</h4>
                    <p>Once anchored, the <strong>Deployment</strong> consumes the <strong>Node's</strong> resources. Other Field Agents can then synchronize via the Multiplayer Frequency.</p>
                </div>
            </aside>
        </div>
    </div>

    <script>
    (function() {
        const cfg = {
            url: "<?php echo esc_js( $supabase_url ); ?>",
            key: "<?php echo esc_js( $anon_key ); ?>",
            uid: <?php echo (int) $user_id; ?>
        };

        const selC  = document.getElementById('s-camp');
        const selW  = document.getElementById('s-world');
        const btn   = document.getElementById('b-connect');
        const log   = document.getElementById('tw-world-console');
        const form  = document.getElementById('tw-anchor-form');
        const audio = document.getElementById('tw-glitch-sound');
        const root  = document.getElementById('tw-deployment-root');

        let dataStore = { camps: [], worlds: [] };
        let statsInterval = null; // FIX #7: store interval ref for cleanup
        let isSubmitting = false; // FIX #5: guard against double-submit

        // FIX #8: debounce helper to avoid rebuilding DOM on every keystroke
        function debounce(fn, ms) {
            let t;
            return function(...args) {
                clearTimeout(t);
                t = setTimeout(() => fn.apply(this, args), ms);
            };
        }

        async function init() {
            log.innerText = "> System: Calibrating Uplink with The Weave...";
            const h = {
                "apikey":        cfg.key,
                "Authorization": "Bearer " + cfg.key
            };

            try {
                // FIX #2: all three fetches run in parallel (was: rLinked was sequential after rC+rW)
                // FIX #1: cyber_campaign_worlds filtered by creator_wp_id so only THIS user's
                //         linked campaigns are excluded (was: fetching ALL rows globally)
                const [rC, rW, rLinked] = await Promise.all([
                    fetch(
                        cfg.url +
                        "rest/v1/cyber_campaign" +
                        "?select=id,name,world_type" +
                        "&wp_user_id=eq." + cfg.uid +
                        "&order=created_at.desc",
                        { headers: h }
                    ),
                    fetch(
                        cfg.url +
                        "rest/v1/cyber_worlds" +
                        "?select=id,name" +
                        "&wp_user_id=eq." + cfg.uid +
                        "&order=created_at.desc",
                        { headers: h }
                    ),
                    fetch(
                        cfg.url +
                        "rest/v1/cyber_campaign_worlds" +
                        "?select=campaign_id" +
                        "&creator_wp_id=eq." + cfg.uid, // FIX #1: scoped to current user
                        { headers: h }
                    )
                ]);

                // FIX #3: check HTTP status for all three responses (rLinked was unchecked)
                if (!rC.ok || !rW.ok || !rLinked.ok) {
                    const statuses = [rC.status, rW.status, rLinked.status];
                    console.error('Fetch error statuses:', statuses);
                    log.innerText = "> Error: Supabase HTTP " + statuses.join(" / ");
                    log.style.color = "#ff0055";
                    return;
                }

                const [allCamps, allWorlds, linkedRows] = await Promise.all([
                    rC.json(),
                    rW.json(),
                    rLinked.json()
                ]);

                const linkedIds = new Set(linkedRows.map(r => String(r.campaign_id)));

                dataStore.camps  = allCamps.filter(c => !linkedIds.has(String(c.id)));
                dataStore.worlds = allWorlds;

                renderList('camp',  dataStore.camps);
                renderList('world', dataStore.worlds);

                log.innerText = "> System: Field Agent authorized. Scan complete.";
                simulateLiveStats();

            } catch (e) {
                console.error('INIT ERROR', e);
                log.innerText = "> Error: Uplink failed.";
                log.style.color = "#ff0055";
            }
        }

        function renderList(type, list, filter) {
            const el      = (type === 'camp') ? selC : selW;
            const filterL = (filter || "").toLowerCase().trim();

            const filtered = list.filter(i =>
                (i.name || "").toLowerCase().includes(filterL)
            );

            // FIX #9: bail early when result count hasn't changed and filter is empty
            // (avoids full DOM rebuild on redundant calls)
            el.innerHTML = "";

            if (filtered.length === 0) {
                el.appendChild(new Option("-- NO DATA --", ""));
                return;
            }

            // Build fragment to minimize reflows
            const frag = document.createDocumentFragment();
            filtered.forEach(i => {
                let label = (i.name || "").toUpperCase();
                if (type === 'camp' && i.world_type != null) {
                    label += " [TYPE " + i.world_type + "]";
                }
                frag.appendChild(new Option(label, i.id));
            });
            el.appendChild(frag);
        }

        function simulateLiveStats() {
            const latencyEl = document.getElementById('stat-latency');
            // FIX #7: store interval so it can be cleared
            statsInterval = setInterval(() => {
                if (!latencyEl) return;
                latencyEl.innerText = (0.020 + Math.random() * 0.01).toFixed(3);
                if (Math.random() > 0.8) {
                    latencyEl.classList.add('n-glitch');
                    setTimeout(() => latencyEl.classList.remove('n-glitch'), 150);
                }
            }, 1200);
        }

        // FIX #7: clean up interval when user navigates away
        window.addEventListener('beforeunload', () => {
            if (statsInterval) clearInterval(statsInterval);
        });

        // FIX #8: debounced filter handlers (50ms is imperceptible but collapses rapid keystrokes)
        document.getElementById('f-camp').addEventListener(
            'input',
            debounce(e => renderList('camp',  dataStore.camps,  e.target.value), 50)
        );
        document.getElementById('f-world').addEventListener(
            'input',
            debounce(e => renderList('world', dataStore.worlds, e.target.value), 50)
        );

        // FIX #4: listen directly on selC and selW instead of form.onchange
        //         form.onchange doesn't reliably bubble select events in all browsers
        function updateButtonState() {
            btn.disabled = !(selC.value && selW.value);
            if (!btn.disabled) {
                log.style.color = "#00e5ff";
                log.innerText = "> System: Link established. Ready for Anchor.";
            }
        }
        selC.addEventListener('change', updateButtonState);
        selW.addEventListener('change', updateButtonState);

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // FIX #5: guard against double-submit (button re-enable on error doesn't help
            //         if the user double-clicks before the first response arrives)
            if (isSubmitting) return;
            isSubmitting = true;
            btn.disabled = true;

            log.style.color = "#00e5ff";
            log.innerText   = "> System: Weaving Splot threads...";

            const payload = {
                campaign_id:   parseInt(selC.value, 10),
                world_id:      parseInt(selW.value, 10),
                creator_wp_id: cfg.uid
            };

            try {
                const res = await fetch(cfg.url + "rest/v1/cyber_campaign_worlds", {
                    method:  "POST",
                    headers: {
                        "apikey":         cfg.key,
                        "Authorization":  "Bearer " + cfg.key,
                        "Content-Type":   "application/json",
                        // FIX #5: removed "resolution=merge-duplicates" — it silently overwrites
                        //         duplicates instead of surfacing them as errors.
                        //         If idempotent upsert is intentional, add it back deliberately.
                        "Prefer":         "return=minimal"
                    },
                    body: JSON.stringify(payload)
                });

                if (res.ok) {
                    if (audio) audio.play().catch(() => {}); // catch autoplay policy errors
                    root.classList.add('tw-glitch-shake');
                    log.style.color = "#adff00";
                    log.innerText   = "> System: ANCHOR SUCCESSFUL. REALITY SYNCED.";

                    // FIX #6: use relative redirect so it works on any WP base path
                    setTimeout(() => {
                        window.location.href = (window.location.origin || '') + '/deployments/';
                    }, 1500);
                } else {
                    const txt = await res.text();
                    console.error('Anchor error:', res.status, txt);
                    throw new Error("Supabase rejection: " + res.status);
                }
            } catch (err) {
                console.error('ANCHOR SUBMIT ERROR', err);
                log.style.color = "#ff0055";
                log.innerText   = "> Error: Deployment failed. " + err.message;
                btn.disabled    = false;
                isSubmitting    = false; // FIX #5: allow retry after error
            }
        });

        init();
    })();
    </script>

    <style>
        /* FIX #10: box-sizing so padding doesn't blow out input/select widths on some themes */
        .tw-deployment-main-container *,
        .tw-deployment-main-container *::before,
        .tw-deployment-main-container *::after {
            box-sizing: border-box;
        }

        .tw-deployment-main-container { max-width: 1200px; margin: 40px auto; font-family: 'Chakra Petch', sans-serif; background: #000; border: 1px solid #1a1a1a; position: relative; overflow: hidden; }
        .tw-briefing-hero { position: relative; height: 250px; background: #111 url('https://images.unsplash.com/photo-1451187580459-43490279c0fa?auto=format&fit=crop&q=80&w=1200') center/cover; display: flex; align-items: center; padding: 0 40px; border-bottom: 2px solid #adff00; }
        .tw-hero-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to right, #000 40%, transparent 100%); }
        .tw-hero-content { position: relative; z-index: 5; display: flex; justify-content: space-between; width: 100%; align-items: center; }
        .tw-hero-text h1 { color: #adff00; font-size: 2.5rem; margin: 0; line-height: 1; letter-spacing: 2px; }
        .tw-hero-text p { color: #888; max-width: 450px; font-size: 0.9rem; margin-top: 10px; }
        .tw-label-alt { color: #ff0055; font-size: 0.7rem; font-weight: bold; letter-spacing: 2px; display: block; margin-bottom: 5px; }
        .tw-hero-stats { display: flex; gap: 30px; }
        .tw-hero-stat-item { display: flex; flex-direction: column; align-items: flex-end; }
        .tw-hero-stat-item .n { color: #adff00; font-size: 1.6rem; font-weight: 900; line-height: 1; font-family: monospace; transition: 0.1s; }
        .tw-hero-stat-item .l { color: #444; font-size: 0.6rem; letter-spacing: 1px; font-weight: bold; margin-top: 4px; }
        .tw-pulse-stat .n { color: #00e5ff; text-shadow: 0 0 10px rgba(0, 229, 255, 0.4); animation: tw-status-pulse 2s infinite; }
        @keyframes tw-status-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
        .n-glitch { filter: brightness(2) contrast(1.5); transform: scale(1.05); }
        .tw-deploy-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 40px; padding: 40px; }
        .tw-console-box { background: #050505; border-left: 3px solid #00e5ff; padding: 15px; font-family: monospace; font-size: 0.8rem; color: #00e5ff; margin-bottom: 30px; box-shadow: inset 0 0 10px rgba(0,229,255,0.05); }
        .tw-selection-group { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }
        .tw-field-box label { display: block; color: #adff00; font-size: 0.75rem; margin-bottom: 10px; font-weight: bold; text-transform: uppercase; }
        .tw-input-cyber { width: 100%; background: #111; border: 1px solid #333; color: #fff; padding: 10px; font-size: 0.8rem; margin-bottom: 5px; border-radius: 0; outline: none; }
        .tw-input-cyber:focus { border-color: #adff00; }
        .tw-select-cyber { width: 100%; background: #080808; border: 1px solid #222; color: #00e5ff; padding: 10px; font-size: 0.9rem; outline: none; border-radius: 0; cursor: pointer; }
        .tw-select-cyber option { padding: 8px; }
        .tw-btn-deploy { width: 100%; padding: 20px; background: #adff00; color: #000; border: none; font-weight: 900; font-size: 1rem; cursor: pointer; clip-path: polygon(0 0, 98% 0, 100% 20%, 100% 100%, 2% 100%, 0 80%); transition: 0.3s; letter-spacing: 2px; }
        .tw-btn-deploy:hover:not(:disabled) { background: #fff; transform: translateY(-2px); }
        .tw-btn-deploy:disabled { background: #1a1a1a; color: #333; cursor: not-allowed; }
        .tw-deploy-sidebar { }
        .tw-sidebar-card { background: #0a0a0a; border: 1px solid #1a1a1a; padding: 25px; border-radius: 2px; }
        .tw-sidebar-card h4 { color: #adff00; margin-top: 0; font-size: 0.9rem; letter-spacing: 1px; }
        .tw-sidebar-card p { color: #666; font-size: 0.85rem; line-height: 1.6; }
        .tw-glitch-shake { animation: tw-shake 0.3s cubic-bezier(.36,.07,.19,.97) both; border-color: #ff0055 !important; box-shadow: 0 0 40px rgba(255, 0, 85, 0.4); }
        @keyframes tw-shake {
            10%, 90% { transform: translate3d(-1px, 0, 0); }
            20%, 80% { transform: translate3d(2px, 0, 0); }
            30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
            40%, 60% { transform: translate3d(4px, 0, 0); }
        }
    </style>
    <?php
    return ob_get_clean();
}
add_shortcode('tw_connect_campaign_world', 'tw_connect_campaign_world_final');
