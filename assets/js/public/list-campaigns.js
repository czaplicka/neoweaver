jQuery(document).ready(function($) {

            const GLOBAL_NONCE      = '<?php echo esc_js( $game_nonce ); ?>';
            const REST_NONCE        = '<?php echo esc_js( $rest_nonce ); ?>';
            const SESSION_START_URL = '<?php echo esc_js( $session_rest_url ); ?>';

            function resetBtn(btn, label) {
                btn.prop('disabled', false).text(label).css('opacity', '1');
            }

            $('.tw-delete-campaign-btn').on('click', async function(e) {
                e.preventDefault();
                const btn      = $(this);
                const campId   = btn.data('id');
                const campName = btn.data('name');
                if (!confirm('CONFIRM TERMINATION OF DEPLOYMENT: ' + campName + ' ?')) return;
                btn.prop('disabled', true).text('TERMINATING...');
                if (!window.twSupabase) { alert('SUPABASE CLIENT OFFLINE.'); resetBtn(btn, 'TERMINATE'); return; }
                try {
                    const { error } = await window.twSupabase.rpc('fn_delete_campaign', { p_campaign_id: campId });
                    if (error) { alert('TERMINATION FAILED: ' + (error.message || 'Grid Denied.')); resetBtn(btn, 'TERMINATE'); return; }
                    $('#campaign-card-' + campId).css({ opacity: '0', 'pointer-events': 'none' });
                    setTimeout(() => window.location.reload(), 1200);
                } catch (err) { alert('TERMINATION FAILED: CLIENT EXCEPTION'); resetBtn(btn, 'TERMINATE'); }
            });

            $('.enter-matrix').on('click', function(e) {
                e.preventDefault();
                const btn         = $(this);
                const campId      = btn.data('id');
                const characterId = btn.data('character') || null;
                const mode        = String(btn.data('mode') || 'SOLO').toUpperCase();
                if (!campId) { alert('DEPLOYMENT ERROR: Missing campaign ID.'); return; }

                if (mode === 'SOLO') {
                    btn.text('INITIALIZING...').css('opacity', '0.7');
                    const fd = new FormData();
                    fd.append('campaign_id',  campId);
                    fd.append('character_id', characterId || '');
                    fd.append('security',     GLOBAL_NONCE);
                    fetch(SESSION_START_URL, {
                        method: 'POST', headers: { 'X-WP-Nonce': REST_NONCE }, body: fd, credentials: 'same-origin',
                    })
                    .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
                    .then(response => {
                        if (response.success) {
                            window.location.href = '<?php echo esc_js( home_url( '/terminal/' ) ); ?>';
                        } else {
                            const data = response.data || {};
                            if (data.message === 'no_character') {
                                window.location.href = '/agents/?campaign_id=' + campId;
                            } else {
                                alert('SESSION INIT FAILED: ' + (data.message || 'Unknown interference'));
                                resetBtn(btn, 'ENTER MATRIX');
                            }
                        }
                    })
                    .catch(err => { alert('SESSION INIT FAILED: network error'); resetBtn(btn, 'ENTER MATRIX'); });
                } else {
                    btn.text('LINKING...').css('opacity', '0.7');
                    if (!window.twSupabase) { alert('SUPABASE CLIENT OFFLINE.'); resetBtn(btn, 'ENTER MATRIX'); return; }
                    const adv = window.twAdventureData || {};
                    const currentWpUserId = adv.wp_user_id || adv.userid || null;
                    if (!currentWpUserId) { alert('SIGNUP FAILED: Cannot detect operator ID.'); resetBtn(btn, 'ENTER MATRIX'); return; }
                    (async () => {
                        try {
                            if (!characterId) { window.location.href = '/agents/?campaign_id=' + campId; return; }
                            const { data: ex, error: exErr } = await window.twSupabase.from('cyber_campaign_signups').select('id').eq('campaign_id', campId).eq('wp_user_id', currentWpUserId).limit(1);
                            if (exErr) { alert('SIGNUP FAILED: Cannot verify link.'); resetBtn(btn, 'ENTER MATRIX'); return; }
                            if (!ex || !ex.length) {
                                const { error: sigErr } = await window.twSupabase.from('cyber_campaign_signups').insert({ campaign_id: campId, character_id: characterId, wp_user_id: currentWpUserId });
                                if (sigErr) { alert('SIGNUP FAILED: ' + (sigErr.message || 'Unknown')); resetBtn(btn, 'ENTER MATRIX'); return; }
                            }
                            window.location.href = '<?php echo esc_js( home_url( '/lobby/?campaign_id=' ) ); ?>' + campId;
                        } catch (err) { alert('SIGNUP FAILED: EXCEPTION'); resetBtn(btn, 'ENTER MATRIX'); }
                    })();
                }
            });

            $('.tw-copy-join-btn').on('click', async function(e) {
                e.preventDefault();
                const btn  = $(this);
                const code = btn.data('code');
                if (!code) { alert('NO HASH DETECTED.'); return; }
                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(code);
                    } else {
                        const temp = $('<input>'); $('body').append(temp); temp.val(code).select(); document.execCommand('copy'); temp.remove();
                    }
                    btn.text('HASH COPIED');
                    setTimeout(() => btn.text('COPY HASH'), 2000);
                } catch (err) { alert('COPY FAILED.'); }
            });
        });
