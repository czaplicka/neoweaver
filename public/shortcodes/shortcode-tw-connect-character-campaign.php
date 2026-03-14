<?php
/**
 * SHORTCODE: [tw_connect_character_campaign]
 * NEOWEAVE AGENT INJECTION (World-Lock Protocol)
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
                            <label><i class="dashicons dashicons-backup"></i> TARGET: DEPLOYMENTS (without agent)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="search-camp-char" class="tw-input-cyber" placeholder="Filter matrices...">
                                <select id="select-camp-char" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>

                        <div class="tw-field-box">
                            <label><i class="dashicons dashicons-admin-users"></i> SUBJECT: AGENTS (Persona)</label>
                            <div class="tw-input-wrapper">
                                <input type="text" id="search-char" class="tw-input-cyber" placeholder="Locate entity...">
                                <select id="select-char" class="tw-select-cyber" size="6" required></select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" id="btn-connect-char" class="tw-btn-deploy" disabled>
                        EXECUTE INJECTION [ENTER]
                    </button>

                    <!-- WORLD-LOCK POD PRZYCISKIEM -->
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
        const config = {
            url: "<?php echo esc_js( $supabase_url ); ?>",
            key: "<?php echo esc_js( $anon_key ); ?>",
            uid: <?php echo (int) $user_id; ?>
        };

        const selCamp = document.getElementById('select-camp-char');
        const selChar = document.getElementById('select-char');
        const btn     = document.getElementById('btn-connect-char');
        const status  = document.getElementById('tw-char-status-console');
        const form    = document.getElementById('tw-char-connect-form');
        const audio   = document.getElementById('tw-glitch-sound');
        const root    = document.getElementById('tw-deployment-root');

        let store = { campaigns: [], characters: [] };

        // Dynamic latency
        setInterval(() => {
            const l = document.getElementById('stat-latency');
            if(l) l.innerText = (0.020 + Math.random() * 0.010).toFixed(3);
        }, 3000);

        async function init() {
            status.innerText = "> System: Calibrating Uplink with The Weave...";
            const headers = { "apikey": config.key, "Authorization": `Bearer ${config.key}` };
            
            try {
                const [rC, rCh] = await Promise.all([
                    // BUG-FIX #5: fetch world_id (FK to cyber_worlds) instead of
                    // world_type (difficulty integer). world_type cannot identify
                    // a unique World Node -- two campaigns in different worlds can
                    // share the same difficulty value.
                    fetch(
                        config.url + "rest/v1/cyber_campaigns_without_characters?select=id,name,world_id&order=created_at.desc",
                        { headers }
                    ),
                    fetch(
                        config.url + "rest/v1/cyber_characters?select=id,name,race_id,class_id,cyber_races(name),cyber_classes(name)&wp_user_id=eq." + config.uid,
                        { headers }
                    )
                ]);

                if (!rC.ok || !rCh.ok) {
                    const cText  = await rC.text();
                    const chText = await rCh.text();
                    console.error('Supabase error campaigns:', rC.status, cText);
                    console.error('Supabase error characters:', rCh.status, chText);
                    status.innerText = "> Error: Supabase HTTP " + rC.status + " / " + rCh.status;
                    status.style.color = "#ff0055";
                    return;
                }

                const rawCamps = await rC.json();
                store.campaigns = rawCamps.map(item => ({
                    id:       item.id,
                    name:     item.name,
                    world_id: item.world_id   // BUG-FIX #5: was world_type
                }));

                const rawChars = await rCh.json();
                store.characters = rawChars.map(ch => ({
                    id: ch.id,
                    name: ch.name,
                    race_id: ch.race_id,
                    class_id: ch.class_id,
                    race_name:  ch.cyber_races  ? ch.cyber_races.name  : null,
                    class_name: ch.cyber_classes ? ch.cyber_classes.name : null
                }));

                render('camp', store.campaigns);
                render('char', store.characters);

                status.innerText = "> System: Assets synchronized. Ready for Injection.";
            } catch (e) {
                console.error('INIT ERROR', e);
                status.innerText = "> Error: Uplink rejected. Interference detected.";
                status.style.color = "#ff0055";
            }
        }

        function render(type, list, filter = "") {
            const el = (type === 'camp') ? selCamp : selChar;
            el.innerHTML = "";
            const filtered = list.filter(i => i.name.toLowerCase().includes(filter.toLowerCase()));
            
            if(filtered.length === 0) {
                el.appendChild(new Option("-- NO DATA --", ""));
            } else {
                filtered.forEach(i => {
                    let label = i.name.toUpperCase();
                    if(type === 'char') {
                        const raceLabel  = i.race_name  || (i.race_id  ?? '-');
                        const classLabel = i.class_name || (i.class_id ?? '-');
                        label += ` [${raceLabel} | ${classLabel}]`;
                    }
                    const opt = new Option(label, i.id);
                    el.appendChild(opt);
                });
            }
        }

        document.getElementById('search-camp-char').oninput = (e) => render('camp', store.campaigns, e.target.value);
        document.getElementById('search-char').oninput     = (e) => render('char', store.characters, e.target.value);
        
        form.onchange = async () => {
            btn.disabled = true;
            if (!selCamp.value || !selChar.value) return;

            status.style.color = "#00e5ff";
            status.innerText = "> System: Validating World-Lock constraints...";

            const selectedCharId = parseInt(selChar.value);
            const selectedCamp   = store.campaigns.find(c => c.id == selCamp.value);

            const headers = { "apikey": config.key, "Authorization": `Bearer ${config.key}` };

            try {
                // Step 1: find the first campaign this agent has already joined
                const resChars = await fetch(
                    config.url +
                    "rest/v1/cyber_campaign_characters" +
                    "?character_id=eq." + selectedCharId +
                    "&select=campaign_id" +
                    "&limit=1",
                    { headers }
                );

                if (!resChars.ok) {
                    const txt = await resChars.text();
                    console.error('World-lock check error (chars):', resChars.status, txt);
                    status.style.color = "#ff0055";
                    status.innerText = "> Error: World-Lock verification failed.";
                    return;
                }

                const links = await resChars.json();

                // Agent has never been deployed -- no World-Lock yet, allow
                if (links.length === 0) {
                    status.style.color = "#00e5ff";
                    status.innerText = "> System: Neural bridge stable. World-Lock verified.";
                    btn.disabled = false;
                    return;
                }

                const firstCampaignId = links[0].campaign_id;

                // Step 2: fetch world_id (FK to cyber_worlds) from that campaign
                // BUG-FIX #5: was selecting world_type (difficulty int), which
                // is NOT unique per World Node. world_id is the actual FK.
                const resWorld = await fetch(
                    config.url +
                    "rest/v1/cyber_campaign" +
                    "?id=eq." + firstCampaignId +
                    "&select=world_id" +
                    "&limit=1",
                    { headers }
                );

                if (!resWorld.ok) {
                    const txt = await resWorld.text();
                    console.error('World-lock check error (world):', resWorld.status, txt);
                    status.style.color = "#ff0055";
                    status.innerText = "> Error: World-Lock verification failed.";
                    return;
                }

                const worldRows = await resWorld.json();

                // If world_id is missing, no lock can be applied -- allow
                if (worldRows.length === 0 || worldRows[0].world_id == null) {
                    status.style.color = "#00e5ff";
                    status.innerText = "> System: Neural bridge stable. World-Lock verified.";
                    btn.disabled = false;
                    return;
                }

                // Step 3: compare world_id values -- must be the same Node
                const agentWorldId = worldRows[0].world_id;

                if (agentWorldId !== selectedCamp.world_id) {
                    status.style.color = "#ff0055";
                    status.innerText = "> Violation: Agent is locked to another World Node.";
                    return;
                }

                status.style.color = "#00e5ff";
                status.innerText = "> System: Neural bridge stable. World-Lock verified.";
                btn.disabled = false;
            } catch (err) {
                console.error('World-lock general error:', err);
                status.style.color = "#ff0055";
                status.innerText = "> Error: World-Lock verification failed.";
            }
        };

        form.onsubmit = async (e) => {
            e.preventDefault();
            btn.disabled = true;
            status.innerText = "> System: Injecting Agent data into Matrix...";

            const payload = {
                campaign_id: parseInt(selCamp.value),
                character_id: parseInt(selChar.value),
                creator_wp_id: config.uid
            };

            try {
                const res = await fetch(config.url + "rest/v1/cyber_campaign_characters", {
                    method: "POST",
                    headers: { 
                        "apikey": config.key, 
                        "Authorization": `Bearer ${config.key}`,
                        "Content-Type": "application/json",
                        "Prefer": "resolution=merge-duplicates" 
                    },
                    body: JSON.stringify(payload)
                });

                if (res.ok) {
                    if(audio) audio.play();
                    root.classList.add('tw-glitch-shake');
                    status.style.color = "#adff00";
                    status.innerText = "> System: INJECTION SUCCESSFUL. AGENT LINKED.";
                    setTimeout(() => window.location.href = '/deployments/', 1500);
                } else {
                    const txt = await res.text();
                    console.error('Injection error:', res.status, txt);
                    throw new Error("Rejection");
                }
            } catch (err) {
                status.style.color = "#ff0055";
                status.innerText = "> Error: Injection failed. Entity rejection.";
                btn.disabled = false;
            }
        };

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
        .tw-input-cyber { width: 100%; background: #111; border: 1px solid #333; color: #fff; padding: 10px; font-size: 0.8rem; border-bottom: none; }
        .tw-select-cyber { width: 100%; background: #080808; border: 1px solid #222; color: #00e5ff; padding: 10px; font-size: 0.8rem; outline: none; }
        .tw-select-cyber option { font-size: 0.8rem; }
        .tw-btn-deploy { width: 100%; padding: 20px; background: #adff00; color: #000; border: none; font-weight: 900; cursor: pointer; clip-path: polygon(0 0, 98% 0, 100% 20%, 100% 100%, 2% 100%, 0 80%); transition: 0.3s; text-transform: uppercase; }
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
