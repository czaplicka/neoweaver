(function() {
        // OPT 2: bail out of the entire IIFE immediately if the terminal element
        // is not on this page — avoids registering a DOMContentLoaded listener
        // on every page load when the shortcode isn't present.
        if (!document.getElementById('neoweave-join-terminal')) return;

        function initJoinTerminal() {
            const box = document.getElementById('neoweave-join-terminal');

            // BUG-FIX 3: read nonce + ajax url from data attributes (safe values).
            const ajaxUrl = box.getAttribute('data-ajax-url');
            const nonce   = box.getAttribute('data-nonce');

            const codeInput   = document.getElementById('neoweave-join-code');
            const charSelect  = document.getElementById('neoweave-join-character');
            const button      = document.getElementById('neoweave-join-button');
            const msgEl       = document.getElementById('neoweave-join-message');

            if (!ajaxUrl || !nonce) {
                if (msgEl) {
                    msgEl.textContent = 'CONFIG ERROR: JOIN TERMINAL OFFLINE.';
                    msgEl.classList.add('error');
                }
                return;
            }

            function setMessage(text, type) {
                if (!msgEl) return;
                msgEl.textContent = text;
                msgEl.classList.remove('error', 'success');
                if (type) msgEl.classList.add(type);
            }

            async function handleJoin() {
                // BUG-FIX 8: disable immediately so rapid clicks cannot queue
                // multiple concurrent requests before validation even runs.
                button.disabled = true;

                const rawCode     = (codeInput?.value  || '').trim().toUpperCase();
                const characterId = (charSelect?.value || '').trim();

                if (!rawCode) {
                    setMessage('NO CODE DETECTED. TRY AGAIN.', 'error');
                    button.disabled = false;
                    return;
                }
                if (!characterId) {
                    setMessage('SELECT A FIELD AGENT BEFORE LINKING.', 'error');
                    button.disabled = false;
                    return;
                }

                setMessage('SCANNING DEPLOYMENT REGISTRY...', 'success');

                // BUG-FIX 7: add a 10-second timeout via AbortController so the
                // button does not hang forever if admin-ajax.php is unresponsive.
                const controller = new AbortController();
                const timeoutId  = setTimeout(() => controller.abort(), 10000);

                try {
                    const fd = new FormData();
                    fd.append('action',       'tw_join_campaign');
                    fd.append('nonce',        nonce);
                    fd.append('join_code',    rawCode);
                    fd.append('character_id', characterId);

                    const res  = await fetch(ajaxUrl, {
                        method:      'POST',
                        body:        fd,
                        credentials: 'same-origin',
                        signal:      controller.signal   // BUG-FIX 7
                    });
                    clearTimeout(timeoutId);

                    const json = await res.json();

                    if (!json.success) {
                        const msg = json.data?.message || 'CHECK CODE OR CONNECTION.';
                        setMessage('JOIN FAILED: ' + msg, 'error');
                        button.disabled = false;
                        return;
                    }

                    const campaignId = json.data?.campaign_id;
                    const status     = json.data?.status;

                    // BUG-FIX 9: each outcome sets its own message and triggers
                    // its own redirect explicitly. No shared fall-through.
                    if (status === 'already_joined') {
                        setMessage('LINK RESTORED. REDIRECTING TO LOBBY...', 'success');
                    } else {
                        setMessage('LINK STABLE. REDIRECTING TO LOBBY...', 'success');
                    }

                    // Button stays disabled intentionally — page is about to redirect.
                    setTimeout(function() {
                        window.location.href = '/lobby/?campaign_id=' + encodeURIComponent(campaignId);
                    }, 1200);

                } catch (e) {
                    clearTimeout(timeoutId);
                    console.error('JOIN TERMINAL ERROR', e);
                    const msg = e?.name === 'AbortError'
                        ? 'JOIN FAILED: REQUEST TIMED OUT.'
                        : 'JOIN FAILED: NETWORK ERROR.';
                    setMessage(msg, 'error');
                    button.disabled = false;
                }
            }

            if (button) {
                button.addEventListener('click', handleJoin);
            }

            if (codeInput) {
                codeInput.addEventListener('keydown', function(ev) {
                    if (ev.key === 'Enter') {
                        ev.preventDefault();
                        handleJoin();
                    }
                });
            }
        }

        document.addEventListener('DOMContentLoaded', initJoinTerminal);
    })();
