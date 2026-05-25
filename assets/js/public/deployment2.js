    (function() {
        'use strict';
const config = {
    url: twDeploymentConfig.supabaseUrl,
    key: twDeploymentConfig.supabaseKey,
    uid: twDeploymentConfig.userId
};
        (function autoScroll() {
            var el = document.getElementById('tw-deployment-root');
            var hasCampaignParam = new URLSearchParams(window.location.search).has('campaign_id');
            var hasHash = window.location.hash === '#tw-deployment-root';
            if (el && (hasCampaignParam || hasHash)) {
                setTimeout(function () {
                    el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }, 300);
            }
        })();

        /* ── DOM refs ── */
        const selCamp  = document.getElementById('select-camp-char');
        const selChar  = document.getElementById('select-char');
        const btn      = document.getElementById('btn-connect-char');
        const status   = document.getElementById('tw-char-status-console');
        const form     = document.getElementById('tw-char-connect-form');
        const audio    = document.getElementById('tw-glitch-sound');
        const root     = document.getElementById('tw-deployment-root');
        const overlay  = document.getElementById('tw-inject-overlay');
        const spnMsg   = document.getElementById('tw-spinner-msg');

        /* ── State ── */
        let store        = { campaigns: [], characters: [] };
        let isValidating = false;
        let redirectTimer = null;

        /* ── Latency ticker ── */
        setInterval(() => {
            const l = document.getElementById('stat-latency');
            if (l) l.textContent = (0.020 + Math.random() * 0.010).toFixed(3);
        }, 3000);

        /* ── Helpers ── */
        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function setBtn(enabled) {
            btn.disabled = !enabled;
            btn.setAttribute('aria-disabled', String(!enabled));
        }

        function setStatus(msg, color = '#00e5ff') {
            status.style.color = color;
            status.textContent = msg;
        }

        function showOverlay(msg) {
            spnMsg.textContent = msg;
            overlay.hidden = false;
        }
        function hideOverlay() {
            overlay.hidden = true;
        }

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

            const frag = document.createDocumentFragment();

            if (filtered.length === 0) {
                const opt = new Option('-- NO DATA --', '');
                opt.disabled = true;
                frag.appendChild(opt);
            } else {
                filtered.forEach(i => {
                    let label = escapeHtml(i.name).toUpperCase();
                    if (type === 'char') {
                        const raceLabel  = escapeHtml(i.race_name  || i.race_id  || '-');
                        const classLabel = escapeHtml(i.class_name || i.class_id || '-');
                        label += ` [${raceLabel} | ${classLabel}]`;
                    }
                    const opt = new Option(label, String(i.id));
                    frag.appendChild(opt);
                });
            }

            el.innerHTML = '';
            el.appendChild(frag);
        }

        /* ── Initialise ── */
        function init() {
            store = { campaigns: [], characters: [] };
            setBtn(false);

            const initialData = (twDeploymentConfig.initialData) || {};
            const rawCamps    = Array.isArray(initialData.campaigns)  ? initialData.campaigns  : [];
            const rawChars    = Array.isArray(initialData.characters) ? initialData.characters : [];

            store.campaigns = rawCamps.map(item => ({
                id:       String(item.id),
                name:     item.name,
                world_id: item.world_id != null ? String(item.world_id) : null
            }));

            store.characters = rawChars.map(ch => ({
                id:         String(ch.id),
                name:       ch.name,
                race_id:    ch.race_id,
                class_id:   ch.class_id,
                race_name:  ch.race_name  || null,
                class_name: ch.class_name || null
            }));

            render('camp', store.campaigns);
            render('char', store.characters);

            if (store.campaigns.length === 0 && store.characters.length === 0) {
                setStatus('> Warning: No assets found in The Weave. Log in or create data first.', '#ff9900');
            } else {
                setStatus('> System: Assets synchronized. Ready for Injection.');
            }
        }

        /* ── Search inputs (debounced) ── */
        document.getElementById('search-camp-char').addEventListener(
            'input',
            debounce(e => render('camp', store.campaigns, e.target.value), 150)
        );
        document.getElementById('search-char').addEventListener(
            'input',
            debounce(e => render('char', store.characters, e.target.value), 150)
        );

        /* ── World-Lock validation ── */
        async function onSelectionChange() {
            setBtn(false);

            const campVal = selCamp.value;
            const charVal = selChar.value;

            if (!campVal || !charVal) return;
            if (isValidating) return;
            isValidating = true;

            setStatus('> System: Validating World-Lock constraints...');

            const selectedCamp = store.campaigns.find(c => c.id === campVal);
            const headers      = { 'apikey': config.key, 'Authorization': `Bearer ${config.key}` };

            if (!selectedCamp || selectedCamp.world_id == null) {
                allow('> System: No World-Lock constraint. Neural bridge open.');
                return;
            }

            try {
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

                const firstCampaignId = String(links[0].campaign_id);

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

                const agentWorldId = String(worldRows[0].world_id);

                if (agentWorldId !== selectedCamp.world_id) {
                    setStatus('> Violation: Agent is locked to another World Node.', '#ff0055');
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
            clearTimeout(redirectTimer);

            const campVal = selCamp.value;
            const charVal = selChar.value;

            setStatus('> System: Injecting Agent data into Matrix...');
            showOverlay('INJECTING AGENT INTO MATRIX…');

            const payload = {
                campaign_id:  campVal,
                character_id: charVal,
                wp_user_id:   config.uid
            };

            try {
                const res = await fetch(config.url + 'rest/v1/cyber_campaign_characters', {
                    method: 'POST',
                    headers: {
                        'apikey':        config.key,
                        'Authorization': `Bearer ${config.key}`,
                        'Content-Type':  'application/json',
                        'Prefer':        'resolution=merge-duplicates,return=representation'
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    const txt = await res.text();
                    console.error('Injection error:', res.status, txt);
                    throw new Error('Rejection');
                }

                spnMsg.textContent = 'SYNCHRONISING WORLD NODE…';
                setStatus('> System: Synchronising World Node…');

                const pollHeaders = { 'apikey': config.key, 'Authorization': `Bearer ${config.key}` };
                let confirmed = false;

                for (let attempt = 0; attempt < 8; attempt++) {
                    await new Promise(r => setTimeout(r, 400));
                    try {
                        const check = await fetch(
                            config.url + 'rest/v1/cyber_campaign_characters' +
                            '?campaign_id=eq.'  + encodeURIComponent(campVal) +
                            '&character_id=eq.' + encodeURIComponent(charVal) +
                            '&select=campaign_id&limit=1',
                            { headers: pollHeaders }
                        );
                        if (check.ok) {
                            const rows = await check.json();
                            if (rows.length > 0) { confirmed = true; break; }
                        }
                    } catch (pollErr) {
                        console.warn('Poll attempt', attempt, 'failed:', pollErr);
                    }
                }

                if (!confirmed) {
                    console.warn('World-node sync timeout – redirecting anyway.');
                }

                root.classList.add('tw-glitch-shake');
                setStatus('> System: INJECTION SUCCESSFUL. AGENT LINKED.', '#adff00');
                spnMsg.textContent = confirmed
                    ? 'INJECTION CONFIRMED. BRIDGING TO DEPLOYMENT…'
                    : 'SYNC TIMEOUT. BRIDGING ANYWAY…';

                if (audio) audio.play().catch(err => console.warn('Audio autoplay blocked:', err));

                redirectTimer = setTimeout(() => {
                    hideOverlay();
                    window.location.href = '/deployments/';
                }, 1200);

            } catch (err) {
                console.error('Submit error:', err);
                hideOverlay();
                setStatus('> Error: Injection failed. Entity rejection.', '#ff0055');
                setBtn(true);
            }
        });

        window.addEventListener('pagehide', () => clearTimeout(redirectTimer));

        init();
    })();
