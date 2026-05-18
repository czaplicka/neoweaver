(function() {

		const ONLINE_THRESHOLD_MS   = 90 * 1000;
		const HEARTBEAT_INTERVAL_MS = 20 * 1000;

		function initLobbyWithClient(client) {
			const lobbyEl = document.getElementById('neoweave-lobby');
			if (!lobbyEl) return;

			const campaignId      = lobbyEl.getAttribute('data-campaign-id');
			const ajaxUrl         = lobbyEl.getAttribute('data-ajax-url');
			const currentUserId   = lobbyEl.getAttribute('data-current-user');
			const hostId          = lobbyEl.getAttribute('data-host-id');
			const nonceLaunch     = lobbyEl.getAttribute('data-nonce-launch');
			const nonceLabels     = lobbyEl.getAttribute('data-nonce-labels');
			const nonceHeartbeat  = lobbyEl.getAttribute('data-nonce-heartbeat');

			const statusEl = document.getElementById('launch-status');

			// [FIX-4] Show status message inline instead of alert()
			function showStatus(msg, type = 'info') {
				if (!statusEl) return;
				statusEl.textContent = msg;
				statusEl.className   = type; // 'info' | 'error' | 'success'
			}

			const userMapAttr = lobbyEl.getAttribute('data-user-map');
			let userMap = {};
			if (userMapAttr) {
				try { userMap = JSON.parse(userMapAttr); }
				catch (e) { console.error('LOBBY: failed to parse user map', e); }
			}

			const slotEls = [
				document.getElementById('squad-slot-1'),
				document.getElementById('squad-slot-2'),
				document.getElementById('squad-slot-3'),
				document.getElementById('squad-slot-4'),
			];

			// ─────────────────────────────────────────────
			// [FIX-2] HEARTBEAT — timer stored as let so visibilitychange
			// can clear and re-create without leaking.
			// ─────────────────────────────────────────────
			function sendHeartbeat() {
				if (!ajaxUrl || !campaignId) return;
				const fd = new FormData();
				fd.append('action',      'neoweave_lobby_heartbeat');
				fd.append('nonce',       nonceHeartbeat);
				fd.append('campaign_id', campaignId);
				fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
					.catch(e => console.warn('LOBBY: heartbeat failed', e));
			}

			sendHeartbeat();
			// [FIX-2] let (not const) so we can reassign on visibility change
			let heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);

			// [FIX-2] Always clear before creating a new interval
			document.addEventListener('visibilitychange', () => {
				clearInterval(heartbeatTimer);
				if (!document.hidden) {
					sendHeartbeat();
					heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
				}
			});

			// ─────────────────────────────────────────────
			// [FIX-3] Store interval refs for cleanup before redirect
			// ─────────────────────────────────────────────
			let fetchTimer = null;
			let watchTimer = null;

			function stopAllTimers() {
				clearInterval(heartbeatTimer);
				clearInterval(fetchTimer);
				clearInterval(watchTimer);
			}

			function renderSlots(signups) {
				signups.sort((a, b) => {
					// [FIX: null created_at safety] Fall back to epoch so null doesn't NaN-sort
					const ta = a.created_at ? new Date(a.created_at).getTime() : 0;
					const tb = b.created_at ? new Date(b.created_at).getTime() : 0;
					return ta - tb;
				});

				for (let i = 0; i < 4; i++) {
					const slot = slotEls[i];
					if (!slot) continue;
					const body = slot.querySelector('.slot-body');
					if (!body) continue;

					const signup = signups[i];
					if (signup) {
						slot.classList.remove('slot-empty');
						body.classList.remove('slot-empty');
						body.innerHTML = '';

						const charName   = signup.character_name || ('#' + signup.character_id);
						const userName   = signup.user_name      || ('USER_' + signup.wp_user_id);
						const readyLabel = signup.is_ready ? ' [READY]' : ' [IDLE]';

						const avatarUrl = signup.character_avatar || '';
						let avatarEl = document.createElement('div');
						avatarEl.className = 'slot-avatar placeholder';
						if (avatarUrl) {
							avatarEl = document.createElement('img');
							avatarEl.className = 'slot-avatar';
							avatarEl.src = avatarUrl;
							avatarEl.alt = charName;
						} else {
							avatarEl.textContent = 'AV';
						}

						const textBlock = document.createElement('div');
						textBlock.className = 'slot-text-block';

						const line1 = document.createElement('div');
						line1.className   = 'slot-text-line';
						line1.textContent = 'AGENT ' + charName + readyLabel;

						const line2 = document.createElement('div');
						line2.className   = 'slot-text-line';
						line2.textContent = 'OPERATOR ' + userName;

						textBlock.appendChild(line1);
						textBlock.appendChild(line2);

						const dot = document.createElement('div');
						dot.className = 'online-dot';
						if (signup._isOnline === false) { dot.classList.add('offline'); }

						body.appendChild(avatarEl);
						body.appendChild(textBlock);
						body.appendChild(dot);
					} else {
						slot.classList.add('slot-empty');
						body.classList.add('slot-empty');
						body.textContent = '// WAITING FOR SIGNAL //';
					}
				}
			}

			async function enrichSignups(rawSignups) {
				if (!Array.isArray(rawSignups) || !rawSignups.length) return [];

				const charIds = [...new Set(rawSignups.map(s => s.character_id).filter(Boolean))];
				const userIds = [...new Set(rawSignups.map(s => s.wp_user_id).filter(Boolean))];

				let charsById = {};

				try {
					if (charIds.length) {
						const { data: chars, error: charErr } = await client
							.from('cyber_characters')
							.select('id,name,avatar')
							.in('id', charIds);
						if (!charErr && Array.isArray(chars)) {
							chars.forEach(c => { charsById[c.id] = c; });
						} else {
							console.error('LOBBY: char lookup error', charErr);
						}
					}
				} catch (e) {
					console.error('LOBBY: enrichSignups char exception', e);
				}

				try {
					if (userIds.length && ajaxUrl) {
						const formData = new FormData();
						formData.append('action', 'neoweave_user_labels');
						formData.append('nonce', nonceLabels);
						userIds.forEach(id => formData.append('ids[]', id));

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success && json.data && json.data.map) {
							Object.assign(userMap, json.data.map);
						} else {
							console.error('LOBBY: user labels response error', json);
						}
					}
				} catch (e) {
					console.error('LOBBY: enrichSignups user exception', e);
				}

				const now = Date.now();

				return rawSignups.map(s => {
					const seenAt   = s.last_seen_at ? new Date(s.last_seen_at).getTime() : 0;
					const isOnline = seenAt > 0 && (now - seenAt) < ONLINE_THRESHOLD_MS;
					return {
						...s,
						character_name:   charsById[s.character_id]?.name   || null,
						character_avatar: charsById[s.character_id]?.avatar || '',
						user_name:        userMap[String(s.wp_user_id)]    || null,
						_isOnline:        isOnline,
					};
				});
			}

			// [FIX-7] Skip fetch when tab is in background
			async function fetchSignups() {
				if (document.hidden) return;
				try {
					const { data, error } = await client
						.from('cyber_campaign_signups')
						.select('campaign_id, wp_user_id, character_id, created_at, is_ready, last_seen_at')
						.eq('campaign_id', campaignId);
					if (error) { console.error('NEOWEAVE LOBBY: signups fetch error', error); return; }
					renderSlots(await enrichSignups(data || []));
				} catch (e) {
					console.error('NEOWEAVE LOBBY: exception while fetching signups', e);
				}
			}

			fetchSignups();
			// [FIX-3] Store ref
			fetchTimer = setInterval(fetchSignups, 3000);

			// [FIX-6] Redirect flag — prevents double redirect
			let redirecting = false;

			async function watchForSessionAndRedirect() {
				// [FIX-6] Guard: don't fire again if redirect already in progress
				if (redirecting) return;
				// [FIX-7] Skip when tab hidden
				if (document.hidden) return;
				try {
					const { data, error } = await client
						.from('cyber_game_sessions')
						.select('id, status')
						.eq('campaign_id', campaignId)
						.eq('wp_user_id', currentUserId)
						.eq('status', 'active')
						.limit(1);
					if (!error && data && data.length) {
						redirecting = true;
						// [FIX-3] Stop all timers before navigating away
						stopAllTimers();
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					}
				} catch (e) { console.error('SESSION WATCH ERROR', e); }
			}
			// [FIX-3] Store ref
			watchTimer = setInterval(watchForSessionAndRedirect, 4000);

			async function handleLaunchAsHost() {
				if (!ajaxUrl) { showStatus('LAUNCH FAILED: MISSING AJAX URL.', 'error'); return; }

				const launchBtn = document.getElementById('neoweave-launch-button');

				// [FIX-5] Disable button during request
				if (launchBtn) {
					launchBtn.disabled   = true;
					launchBtn.textContent = 'LAUNCHING...';
				}
				showStatus('> INITIALIZING DEPLOYMENT SEQUENCE...', 'info');

				const formData = new FormData();
				formData.append('action',      'neoweave_launch_campaign');
				formData.append('campaign_id', campaignId);
				formData.append('nonce',       nonceLaunch);

				try {
					const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
					const json = await res.json();
					if (json && json.success) {
						showStatus('> DEPLOYMENT SUCCESSFUL. ENTERING TERMINAL...', 'success');
						redirecting = true;
						stopAllTimers();
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					} else {
						const msg = (json && (json.data?.message || json.message)) || 'UNKNOWN_ERROR';
						showStatus('LAUNCH FAILED: ' + msg, 'error');
						if (launchBtn) {
							launchBtn.disabled    = false;
							launchBtn.textContent = 'LAUNCH DEPLOYMENT';
						}
					}
				} catch (e) {
					console.error('LAUNCH ERROR', e);
					showStatus('LAUNCH FAILED: CLIENT_EXCEPTION', 'error');
					if (launchBtn) {
						launchBtn.disabled    = false;
						launchBtn.textContent = 'LAUNCH DEPLOYMENT';
					}
				}
			}

			const launchBtn = document.getElementById('neoweave-launch-button');
			if (launchBtn) {
				if (String(currentUserId) === String(hostId)) {
					launchBtn.textContent = 'LAUNCH DEPLOYMENT';
					launchBtn.addEventListener('click', handleLaunchAsHost);
				} else {
					launchBtn.textContent = 'READY';
					let localReady = false;
					launchBtn.addEventListener('click', async function() {
						if (!campaignId || !currentUserId) return;
						const newReady = !localReady;
						launchBtn.disabled = true;
						try {
							const { error } = await client
								.from('cyber_campaign_signups')
								.update({ is_ready: newReady })
								.eq('campaign_id', campaignId)
								.eq('wp_user_id', currentUserId);
							if (error) {
								console.error('READY TOGGLE ERROR', error);
								showStatus('READY TOGGLE FAILED.', 'error');
							} else {
								localReady            = newReady;
								launchBtn.textContent = newReady ? 'READY ✓' : 'READY';
							}
						} catch (e) {
							console.error('READY TOGGLE EXCEPTION', e);
							showStatus('READY TOGGLE EXCEPTION.', 'error');
						} finally {
							launchBtn.disabled = false;
						}
					});
				}
			}

			const leaveBtn = document.getElementById('neoweave-leave-button');
			if (leaveBtn && ajaxUrl) {
				leaveBtn.addEventListener('click', async function() {
					leaveBtn.disabled = true;
					try {
						if (campaignId && currentUserId) {
							try {
								const { error: readyErr } = await client
									.from('cyber_campaign_signups')
									.update({ is_ready: false })
									.eq('campaign_id', campaignId)
									.eq('wp_user_id', currentUserId);
								if (readyErr) { console.error('LEAVE: ready reset error', readyErr); }
							} catch (e) { console.error('LEAVE: ready reset exception', e); }
						}

						const formData = new FormData();
						formData.append('action',      'neoweave_leave_lobby');
						formData.append('nonce',       nonceHeartbeat);
						formData.append('campaign_id', campaignId);

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success) {
							stopAllTimers();
							window.location.href = '/';
						} else {
							console.error('NEOWEAVE LOBBY: leave failed', json);
							showStatus('LEAVE FAILED: ' + (json?.data?.message || 'UNKNOWN_ERROR'), 'error');
							leaveBtn.disabled = false;
						}
					} catch (e) {
						console.error('NEOWEAVE LOBBY: leave exception', e);
						showStatus('LEAVE FAILED: CLIENT_EXCEPTION', 'error');
						leaveBtn.disabled = false;
					}
				});
			}
		}

		function waitForTwSupabase() {
			if (window.twSupabase) {
				console.log('NEOWEAVE LOBBY: binding to global Supabase client');
				initLobbyWithClient(window.twSupabase);
			} else {
				setTimeout(waitForTwSupabase, 500);
			}
		}

		waitForTwSupabase();
	})();
