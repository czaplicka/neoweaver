<?php
/**
 * NEOWEAVE LOBBY SHORTCODE + AJAX USER LABELS + AVATARS + ONLINE DOT + LAUNCH/READY + AUTO-JOIN
 *
 * SECURITY FIX (Bug #4):
 *   - neoweave_launch_campaign : check_ajax_referer( 'neoweave_launch', 'nonce' )
 *   - neoweave_user_labels     : check_ajax_referer( 'neoweave_labels', 'nonce' )
 *                                + removed wp_ajax_nopriv_ (no reason to expose display
 *                                  names to unauthenticated users)
 *   Both nonces are injected into the lobby element as data-* attributes by the
 *   shortcode so the JS never needs a hard-coded value.
 *
 * BUG #5 FIX — online dot now uses last_seen_at heartbeat:
 *   - JS sends POST neoweave_lobby_heartbeat every 20 s (see ajax-lobby-heartbeat.php)
 *   - enrichSignups() reads s.last_seen_at instead of s.created_at
 *   - Online threshold: last_seen_at within the last 90 s (3 missed heartbeats)
 *   - fetchSignups() selects last_seen_at from cyber_campaign_signups
 *   - Nonce neoweave_heartbeat injected as data-nonce-heartbeat
 */

add_shortcode( 'neoweave_lobby', 'neoweave_lobby_terminal' );

/**
 * Bug 7 fix: @import inside a <body>-level <style> is invalid per CSS spec and
 * handled inconsistently across browsers. Fonts must be enqueued via WordPress
 * so they land in <head> before any rendering begins.
 *
 * Guard with has_shortcode() so the extra HTTP request only fires on pages that
 * actually render the lobby, not on every page of the site.
 */
add_action( 'wp_enqueue_scripts', 'neoweave_lobby_enqueue_fonts' );
function neoweave_lobby_enqueue_fonts() {
	global $post;
	if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'neoweave_lobby' ) ) {
		return;
	}
	wp_enqueue_style(
		'neoweave-lobby-fonts',
		'https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;700&family=Share+Tech+Mono&display=swap',
		[],
		null  // null version = no ?ver= query string, matching Google's canonical URL.
	);
}

function neoweave_lobby_terminal() {
	$user_id = get_current_user_id();
	if ( ! $user_id ) {
		return '<div class="neoweave-terminal">ERROR: OPERATOR NOT IDENTIFIED. ACCESS DENIED.</div>';
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		return '<div class="neoweave-terminal">ERROR: SUPABASE LINK OFFLINE. CHECK TW_SUPABASE_* IN WP-CONFIG.</div>';
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	$campaign_id = isset( $_GET['campaign_id'] ) ? intval( $_GET['campaign_id'] ) : 0;
	if ( $campaign_id <= 0 ) {
		return '<div class="neoweave-terminal">ERROR: INVALID DEPLOYMENT REFERENCE.</div>';
	}

	// kampania: nazwa + host_id
	$campaign_name    = 'UNKNOWN';
	$campaign_host_id = 0;
	$camp_url = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=name,wp_user_id';
	$camp_res = wp_remote_get( $camp_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );
	if ( ! is_wp_error( $camp_res ) ) {
		$camp_data = json_decode( wp_remote_retrieve_body( $camp_res ), true );
		if ( is_array( $camp_data ) && ! empty( $camp_data[0] ) ) {
			$campaign_name    = $camp_data[0]['name']       ?? 'UNKNOWN';
			$campaign_host_id = intval( $camp_data[0]['wp_user_id'] ?? 0 );
		}
	}

	$ajax_url = admin_url( 'admin-ajax.php' );

	// Per-request nonces.
	$nonce_launch    = wp_create_nonce( 'neoweave_launch' );
	$nonce_labels    = wp_create_nonce( 'neoweave_labels' );
	$nonce_heartbeat = wp_create_nonce( 'neoweave_heartbeat' ); // Bug #5
	$nonce_leave     = wp_create_nonce( 'neoweave_leave' );     // Bug #3

	$user_map      = [];
	$current_user  = wp_get_current_user();
	if ( $current_user && $current_user->ID ) {
		$user_map[ $current_user->ID ] = $current_user->display_name;
	}
	$user_map_json = esc_attr( wp_json_encode( $user_map ) );

	ob_start();
	?>
	<style>
	/* Bug 8 fix: all rules scoped to #neoweave-lobby to prevent collision with
	   other shortcodes and theme components on the same page. @keyframes are
	   global by CSS spec and intentionally left without a scope prefix. */

	#neoweave-lobby {
		background-color: #0a0c00; color: #adff00; font-family: 'Share Tech Mono', monospace;
		padding: 30px; border: 2px solid #adff00; position: relative; max-width: 700px; margin: 20px auto;
		text-transform: uppercase; box-shadow: 0 0 20px rgba(173, 255, 0, 0.2);
	}
	#neoweave-lobby .terminal-header { border-bottom: 1px solid #adff00; margin-bottom: 20px; padding-bottom: 10px; }
	#neoweave-lobby .terminal-title { font-family: 'Chakra Petch', sans-serif; font-size: 1.2rem; font-weight: bold; }
	#neoweave-lobby .terminal-status { margin-top: 5px; font-size: 0.9rem; }
	#neoweave-lobby .blink { animation: blinker 1s linear infinite; }
	@keyframes blinker { 50% { opacity: 0; } }

	#neoweave-lobby .squad-grid {
		display: grid;
		grid-template-columns: repeat(2, 1fr);
		gap: 15px;
		margin-top: 20px;
	}
	#neoweave-lobby .squad-slot {
		border: 1px solid #adff00;
		padding: 12px;
		min-height: 80px;
		display: flex;
		align-items: center;
		position: relative;
	}
	#neoweave-lobby .slot-body {
		font-size: 0.8rem;
		width: 100%;
		text-align: left;
		display: flex;
		align-items: center;
		gap: 10px;
	}
	#neoweave-lobby .slot-empty { opacity: 0.6; }
	#neoweave-lobby .slot-avatar {
		width: 60px; height: 60px; border-radius: 50%; border: 0px solid #adff00;
		object-fit: cover; background: #050600; flex-shrink: 0;
	}
	#neoweave-lobby .slot-avatar.placeholder {
		display: flex; align-items: center; justify-content: center;
		font-size: 0.5rem; color: #555;
	}
	#neoweave-lobby .slot-text-block { display: flex; flex-direction: column; gap: 2px; }
	#neoweave-lobby .slot-text-line  { line-height: 1.2; }
	#neoweave-lobby .online-dot {
		width: 8px; height: 8px; border-radius: 50%; background: #00ff55;
		box-shadow: 0 0 6px #00ff55; margin-left: auto;
		animation: onlinePulse 1.2s infinite; flex-shrink: 0;
	}
	#neoweave-lobby .online-dot.offline { background: #444; box-shadow: none; animation: none; }
	@keyframes onlinePulse {
		0%   { transform: scale(1);   opacity: 1; }
		50%  { transform: scale(1.4); opacity: 0.4; }
		100% { transform: scale(1);   opacity: 1; }
	}
	#neoweave-lobby .terminal-button {
		background: #adff00; color: #0a0c00; border: none; padding: 12px 20px;
		margin-top: 20px; width: 100%; font-family: 'Chakra Petch', sans-serif; font-weight: bold;
		cursor: pointer; text-align: center; text-decoration: none; display: inline-block;
	}
	#neoweave-lobby .terminal-actions { display: flex; gap: 10px; margin-top: 25px; }
	#neoweave-lobby .terminal-button.secondary {
		background: #0a0c00; color: #adff00; border: 1px solid #adff00;
	}
	</style>

	<div class="neoweave-terminal" id="neoweave-lobby"
		 data-campaign-id="<?php echo esc_attr( $campaign_id ); ?>"
		 data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
		 data-user-map="<?php echo $user_map_json; ?>"
		 data-current-user="<?php echo esc_attr( get_current_user_id() ); ?>"
		 data-host-id="<?php echo esc_attr( $campaign_host_id ); ?>"
		 data-nonce-launch="<?php echo esc_attr( $nonce_launch ); ?>"
		 data-nonce-labels="<?php echo esc_attr( $nonce_labels ); ?>"
		 data-nonce-heartbeat="<?php echo esc_attr( $nonce_heartbeat ); ?>"
		 data-nonce-leave="<?php echo esc_attr( $nonce_leave ); ?>">
		<div class="terminal-header">
			<div class="terminal-title">SQUAD DEPLOYMENT: ID_<?php echo esc_html( $campaign_id ); ?></div>
			<div class="terminal-status">
				SCANNING FOR AGENT SIGNALS...<span class="blink">_</span><br>
				> NODE: [<?php echo esc_html( $campaign_name ); ?>]<br>
				> PROTOCOL: NEURAL_LINK_4_WAY
			</div>
		</div>

		<div class="squad-grid">
			<div class="squad-slot" id="squad-slot-1"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-2"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-3"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
			<div class="squad-slot" id="squad-slot-4"><div class="slot-body slot-empty">// WAITING FOR SIGNAL //</div></div>
		</div>

		<div class="terminal-actions">
			<button type="button" class="terminal-button" id="neoweave-launch-button">LAUNCH DEPLOYMENT</button>
			<button type="button" class="terminal-button secondary" id="neoweave-leave-button">LEAVE LOBBY</button>
		</div>
	</div>

	<script>
	(function() {

		/**
		 * Online threshold: player is considered online if last_seen_at
		 * is within the last 90 seconds (= 3 missed 20-second heartbeats).
		 */
		const ONLINE_THRESHOLD_MS   = 90 * 1000;
		const HEARTBEAT_INTERVAL_MS = 20 * 1000;

		function initLobbyWithClient(client) {
			const lobbyEl = document.getElementById('neoweave-lobby');
			if (!lobbyEl) return;

			const campaignId     = lobbyEl.getAttribute('data-campaign-id');
			const ajaxUrl        = lobbyEl.getAttribute('data-ajax-url');
			const currentUserId  = lobbyEl.getAttribute('data-current-user');
			const hostId         = lobbyEl.getAttribute('data-host-id');
			const nonceLaunch    = lobbyEl.getAttribute('data-nonce-launch');
			const nonceLabels    = lobbyEl.getAttribute('data-nonce-labels');
			const nonceHeartbeat = lobbyEl.getAttribute('data-nonce-heartbeat');
			const nonceLeave     = lobbyEl.getAttribute('data-nonce-leave');

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

			// ─────────────────────────────────────────────────────────────────────
			// HEARTBEAT
			// Fires immediately on load, then every 20 s.
			// Updates last_seen_at on cyber_campaign_signups so other players
			// see a green online dot.
			// Bug 5 fix: use let so heartbeatTimer can be reassigned on tab restore.
			// ─────────────────────────────────────────────────────────────────────
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
			let heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);

			// Stop heartbeat when tab hidden; restart (saving new id) when visible.
			document.addEventListener('visibilitychange', () => {
				if (document.hidden) {
					clearInterval(heartbeatTimer);
				} else {
					sendHeartbeat();
					heartbeatTimer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS);
				}
			});

			// ─────────────────────────────────────────────────────────────────────
			// Opt 2: cache enrich results (character names, avatars, user labels).
			// These never change during a lobby session. Only is_ready and
			// last_seen_at change per poll, so we fetch them lightly after the
			// first full enrich and merge in the stable cached data.
			// ─────────────────────────────────────────────────────────────────────
			let enrichCache = null; // { charsById, userMap } — populated once

			async function doFullEnrich(rawSignups) {
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
				} catch (e) { console.error('LOBBY: enrichSignups char exception', e); }

				try {
					if (userIds.length && ajaxUrl) {
						const fd = new FormData();
						fd.append('action', 'neoweave_user_labels');
						fd.append('nonce', nonceLabels);
						userIds.forEach(id => fd.append('ids[]', id));
						const res  = await fetch(ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success && json.data && json.data.map) {
							Object.assign(userMap, json.data.map);
						} else {
							console.error('LOBBY: user labels response error', json);
						}
					}
				} catch (e) { console.error('LOBBY: enrichSignups user exception', e); }

				// Cache stable data so subsequent polls skip these two requests.
				enrichCache = { charsById, userMap: Object.assign({}, userMap) };
				return enrichCache;
			}

			function applyEnrich(rawSignups, cache) {
				const now = Date.now();
				return rawSignups.map(s => {
					const seenAt   = s.last_seen_at ? new Date(s.last_seen_at).getTime() : 0;
					const isOnline = seenAt > 0 && (now - seenAt) < ONLINE_THRESHOLD_MS;
					const charInfo = cache.charsById[s.character_id];
					return {
						...s,
						character_name:   charInfo?.name   || null,
						character_avatar: charInfo?.avatar || '',
						user_name:        cache.userMap[String(s.wp_user_id)] || null,
						_isOnline:        isOnline,
					};
				});
			}

			async function enrichSignups(rawSignups) {
				if (!Array.isArray(rawSignups) || !rawSignups.length) return [];
				const cache = enrichCache ? enrichCache : await doFullEnrich(rawSignups);
				return applyEnrich(rawSignups, cache);
			}

			// ─────────────────────────────────────────────────────────────────────
			// Opt 4: diffing renderSlots — only update slots where data changed.
			// Previously body.innerHTML = '' wiped and rebuilt all four slots every
			// 3 s, causing layout thrash and flickering avatars. We now store a
			// snapshot of the last rendered state per slot and skip the update when
			// the visible content is identical.
			// ─────────────────────────────────────────────────────────────────────
			const lastRender = [null, null, null, null]; // one snapshot per slot

			function slotSignature(signup) {
				if (!signup) return '__empty__';
				// Include every field that affects what the slot displays.
				return [
					signup.character_id,
					signup.character_name,
					signup.character_avatar,
					signup.wp_user_id,
					signup.user_name,
					signup.is_ready ? '1' : '0',
					signup._isOnline ? '1' : '0',
				].join('|');
			}

			function renderOneSlot(slot, body, signup) {
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

			function renderSlots(signups) {
				signups.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));

				for (let i = 0; i < 4; i++) {
					const slot = slotEls[i];
					if (!slot) continue;
					const body = slot.querySelector('.slot-body');
					if (!body) continue;

					const signup = signups[i] || null;
					const sig    = slotSignature(signup);

					// Opt 4: skip DOM update if nothing visible has changed.
					if (lastRender[i] === sig) continue;
					lastRender[i] = sig;

					renderOneSlot(slot, body, signup);
				}
			}

			// ─────────────────────────────────────────────────────────────────────
			// Opt 3: merge watchForSessionAndRedirect into the fetchSignups loop.
			// Previously two independent polling intervals (3 s + 4 s) fired
			// separate Supabase queries. The session-started check is now a second
			// query inside the same async tick as fetchSignups, sharing the same
			// polling cadence and avoiding a redundant interval.
			// ─────────────────────────────────────────────────────────────────────
			async function fetchSignups() {
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

				// Opt 3: check for an active session in the same polling tick.
				// Non-host players need this to detect when the host has launched.
				// The host is redirected immediately by handleLaunchAsHost() on success.
				if (String(currentUserId) !== String(hostId)) {
					try {
						const { data: sessions, error: sessErr } = await client
							.from('cyber_game_sessions')
							.select('id')
							.eq('campaign_id', campaignId)
							.eq('wp_user_id', currentUserId)
							.eq('status', 'active')
							.limit(1);
						if (!sessErr && sessions && sessions.length) {
							window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
						}
					} catch (e) { console.error('LOBBY: session watch error', e); }
				}
			}

			fetchSignups();
			setInterval(fetchSignups, 3000);

			// ─────────────────────────────────────────────────────────────────────
			// Launch / Ready button
			// ─────────────────────────────────────────────────────────────────────
			async function handleLaunchAsHost() {
				if (!ajaxUrl) { alert('LAUNCH FAILED: missing AJAX URL.'); return; }

				const formData = new FormData();
				formData.append('action',      'neoweave_launch_campaign');
				formData.append('campaign_id', campaignId);
				formData.append('nonce',       nonceLaunch);

				try {
					const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
					const json = await res.json();
					if (json && json.success) {
						window.location.href = '/terminal/?campaign_id=' + encodeURIComponent(campaignId);
					} else {
						const msg = (json && (json.data?.message || json.message)) || 'UNKNOWN ERROR';
						alert('LAUNCH FAILED: ' + msg);
					}
				} catch (e) {
					console.error('LAUNCH ERROR', e);
					alert('LAUNCH FAILED: CLIENT EXCEPTION');
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
						try {
							const { error } = await client
								.from('cyber_campaign_signups')
								.update({ is_ready: newReady })
								.eq('campaign_id', campaignId)
								.eq('wp_user_id', currentUserId);
							if (error) { console.error('READY TOGGLE ERROR', error); return; }
							localReady = newReady;
							launchBtn.textContent = newReady ? 'READY ✓' : 'READY';
						} catch (e) { console.error('READY TOGGLE EXCEPTION', e); }
					});
				}
			}

			// ─────────────────────────────────────────────────────────────────────
			// Leave lobby button
			// ─────────────────────────────────────────────────────────────────────
			const leaveBtn = document.getElementById('neoweave-leave-button');
			if (leaveBtn && ajaxUrl) {
				leaveBtn.addEventListener('click', async function() {
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
						formData.append('campaign_id', campaignId);
						formData.append('nonce',       nonceLeave);

						const res  = await fetch(ajaxUrl, { method: 'POST', body: formData, credentials: 'same-origin' });
						const json = await res.json();
						if (json && json.success) { window.location.href = '/'; }
						else { console.error('NEOWEAVE LOBBY: leave failed', json); }
					} catch (e) { console.error('NEOWEAVE LOBBY: leave exception', e); }
				});
			}
		}

		// Bug 4 fix: cap retries so we don't poll forever if window.twSupabase never
		// initialises (CDN blocked, head-injection failed, JS error, etc.).
		// After 20 attempts (~10 s) show a visible error instead of leaking timers.
		let twSupabaseRetries = 0;
		function waitForTwSupabase() {
			if (window.twSupabase) {
				console.log('NEOWEAVE LOBBY: binding to global Supabase client');
				initLobbyWithClient(window.twSupabase);
			} else if (twSupabaseRetries++ < 20) {
				setTimeout(waitForTwSupabase, 500);
			} else {
				const el = document.getElementById('neoweave-lobby');
				if (el) el.innerHTML = '<p style="color:#ff5577;font-family:\'Share Tech Mono\',monospace;padding:20px;">UPLINK FAILED: SUPABASE CLIENT OFFLINE. RELOAD TO RETRY.</p>';
			}
		}

		waitForTwSupabase();
	})();
	</script>
	<?php
	<?php
	return ob_get_clean();
}

/**
 * AJAX: player leaves lobby — removes their signup row.
 *
 * SECURITY: requires a valid nonce (neoweave_leave).
 * nopriv not registered — login required.
 */
add_action( 'wp_ajax_neoweave_leave_lobby', 'neoweave_leave_lobby' );

function neoweave_leave_lobby() {
	check_ajax_referer( 'neoweave_leave', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	$campaign_id     = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
	$current_user_id = get_current_user_id();

	if ( $campaign_id <= 0 || ! $current_user_id ) {
		wp_send_json_error( [ 'message' => 'invalid_params' ] );
		return;
	}

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();

	// Delete the signup row for this player.
	$delete_url = $supabase_rest . 'cyber_campaign_signups'
		. '?campaign_id=eq.' . $campaign_id
		. '&wp_user_id=eq.'  . $current_user_id;

	$delete_res = wp_remote_request( $delete_url, [
		'method'  => 'DELETE',
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
		],
	] );

	if ( is_wp_error( $delete_res ) || wp_remote_retrieve_response_code( $delete_res ) >= 300 ) {
		error_log( 'TW Supabase error: neoweave_leave_lobby DELETE failed for campaign ' . $campaign_id . ', user ' . $current_user_id );
		wp_send_json_error( [ 'message' => 'leave_failed' ] );
		return;
	}

	wp_send_json_success( [ 'message' => 'left' ] );
}

/**
 * AJAX: mapa wp_user_id -> display_name dla lobby
 *
 * SECURITY: requires a valid nonce (neoweave_labels).
 * nopriv removed — display names must not leak to unauthenticated callers.
 */
add_action( 'wp_ajax_neoweave_user_labels', 'neoweave_user_labels' );
// add_action( 'wp_ajax_nopriv_neoweave_user_labels', ... ) intentionally omitted.

function neoweave_user_labels() {
	check_ajax_referer( 'neoweave_labels', 'nonce' );

	// BUG-FIX 1: added return after every early wp_send_json_error/success.
	if ( empty( $_POST['ids'] ) || ! is_array( $_POST['ids'] ) ) {
		wp_send_json_error( [ 'message' => 'NO_IDS' ] );
		return;
	}

	$ids = array_unique( array_filter( array_map( 'intval', $_POST['ids'] ) ) );

	if ( empty( $ids ) ) {
		wp_send_json_success( [ 'map' => [] ] );
		return;
	}

	$users = get_users( [
		'include' => $ids,
		'fields'  => [ 'ID', 'display_name' ],
	] );

	$map = [];
	foreach ( $users as $u ) {
		$map[ $u->ID ] = $u->display_name;
	}

	wp_send_json_success( [ 'map' => $map ] );
}

/**
 * AJAX: host LAUNCH — creates cyber_game_sessions from campaign_signups
 *
 * SECURITY: requires a valid nonce (neoweave_launch).
 * nopriv not registered (login required by is_user_logged_in check below).
 */
add_action( 'wp_ajax_neoweave_launch_campaign', 'neoweave_launch_campaign' );

function neoweave_launch_campaign() {
	check_ajax_referer( 'neoweave_launch', 'nonce' );

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( [ 'message' => 'not_logged_in' ] );
		return;
	}

	$campaign_id = isset( $_POST['campaign_id'] ) ? intval( $_POST['campaign_id'] ) : 0;
	if ( $campaign_id <= 0 ) {
		wp_send_json_error( [ 'message' => 'invalid_campaign' ] );
		return;
	}

	$current_user_id = get_current_user_id();

	if ( ! function_exists( 'tw_supabase_url' ) || ! function_exists( 'tw_supabase_anon_key' ) ) {
		wp_send_json_error( [ 'message' => 'supabase_config_missing' ] );
		return;
	}

	$supabase_rest = trailingslashit( tw_supabase_url() ) . 'rest/v1/';
	$supabase_key  = tw_supabase_anon_key();
	$headers       = [
		'apikey'        => $supabase_key,
		'Authorization' => 'Bearer ' . $supabase_key,
	];

	// Opt 1: parallelise HTTP calls with curl_multi to reduce wall-clock time.
	// Chain: (1) campaign host-check alone → must pass before we proceed.
	//        (2+4) world_id + signups in parallel (both depend only on campaign_id).
	//        (3) start location — needs world_id from step 2, so fires after.
	// Each request carries an explicit 10 s timeout (matching tw_ajax_join_campaign).

	/**
	 * Helper: fire an array of URLs in parallel via curl_multi and return
	 * an array of decoded JSON bodies in the same order as $urls.
	 * Returns null for any request that fails or times out.
	 */
	$curl_multi_get = static function ( array $urls ) use ( $headers ): array {
		$multi   = curl_multi_init();
		$handles = [];
		foreach ( $urls as $i => $url ) {
			$ch = curl_init( $url );
			curl_setopt_array( $ch, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 10,
				CURLOPT_HTTPHEADER     => [
					'apikey: '        . $headers['apikey'],
					'Authorization: ' . $headers['Authorization'],
				],
				CURLOPT_SSL_VERIFYPEER => true,
			] );
			curl_multi_add_handle( $multi, $ch );
			$handles[ $i ] = $ch;
		}
		do {
			$status = curl_multi_exec( $multi, $running );
			if ( $running ) { curl_multi_select( $multi ); }
		} while ( $running && $status === CURLM_OK );
		$results = [];
		foreach ( $handles as $i => $ch ) {
			$body        = curl_multi_getcontent( $ch );
			$results[$i] = ( curl_errno( $ch ) === 0 && $body )
				? json_decode( $body, true )
				: null;
			curl_multi_remove_handle( $multi, $ch );
			curl_close( $ch );
		}
		curl_multi_close( $multi );
		return $results;
	};

	// ── Step 1: campaign host check (serial; must validate before proceeding) ──
	$camp_url  = $supabase_rest . 'cyber_campaign?id=eq.' . $campaign_id . '&select=wp_user_id';
	$step1     = $curl_multi_get( [ $camp_url ] );
	$camp_data = $step1[0];
	if ( ! is_array( $camp_data ) ) {
		wp_send_json_error( [ 'message' => 'campaign_fetch_error' ] );
		return;
	}
	$host_id = isset( $camp_data[0]['wp_user_id'] ) ? intval( $camp_data[0]['wp_user_id'] ) : 0;
	if ( $host_id !== $current_user_id ) {
		wp_send_json_error( [ 'message' => 'not_host' ] );
		return;
	}

	// ── Step 2+4: world_id and signups in parallel ────────────────────────────
	$world_url  = $supabase_rest . 'cyber_campaign_worlds?campaign_id=eq.' . $campaign_id . '&select=world_id';
	$signup_url = $supabase_rest . 'cyber_campaign_signups?campaign_id=eq.' . $campaign_id . '&select=wp_user_id,character_id';
	$step2      = $curl_multi_get( [ $world_url, $signup_url ] );

	// Process world_id (step 2a) — UUID; do NOT intval().
	$world_data = $step2[0];
	$world_id   = null;
	if ( is_array( $world_data ) && ! empty( $world_data[0]['world_id'] ) ) {
		// Sanitize: allow only UUID-safe characters (hex digits and hyphens).
		$world_id = preg_replace( '/[^a-f0-9\-]/i', '', (string) $world_data[0]['world_id'] );
		if ( ! $world_id ) { $world_id = null; }
	}
	if ( ! $world_id ) {
		wp_send_json_error( [ 'message' => 'no_world_linked' ] );
		return;
	}

	// Process signups (step 4).
	$signups = $step2[1];
	if ( ! is_array( $signups ) || ! count( $signups ) ) {
		wp_send_json_error( [ 'message' => 'no_signups' ] );
		return;
	}

	// ── Step 3: start location — needs world_id, so fires after step 2 ────────
	$loc_url  = $supabase_rest
		. 'cyber_world_map?world_id=eq.' . $world_id
		. '&coord_x=eq.0&coord_y=eq.0&select=id&limit=1';
	$step3    = $curl_multi_get( [ $loc_url ] );
	$loc_data = $step3[0];
	$location_id = ( is_array( $loc_data ) && ! empty( $loc_data[0]['id'] ) )
		? intval( $loc_data[0]['id'] )
		: null;
	if ( ! $location_id ) {
		wp_send_json_error( [ 'message' => 'no_start_location' ] );
		return;
	}

	// 2b) pause existing active sessions for these users
	$user_ids = array_filter( array_unique( array_map(
		static function ( $s ) { return intval( $s['wp_user_id'] ); },
		$signups
	) ) );

	if ( ! empty( $user_ids ) ) {
		$ids_list    = implode( ',', $user_ids );
		$cleanup_url = $supabase_rest . 'cyber_game_sessions'
			. '?wp_user_id=in.(' . $ids_list . ')&status=eq.active';
		wp_remote_request( $cleanup_url, [
			'method'  => 'PATCH',
			'headers' => [
				'apikey'        => $supabase_key,
				'Authorization' => 'Bearer ' . $supabase_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [ 'status' => 'paused' ] ),
		] );
	}

	// 3) insert new active sessions
	$sessions_payload = [];
	foreach ( $signups as $s ) {
		// wp_user_id is a genuine integer; character_id is a UUID — do NOT intval() it.
		$sessions_payload[] = [
			'campaign_id'  => $campaign_id,
			'wp_user_id'   => intval( $s['wp_user_id'] ),
			'character_id' => preg_replace( '/[^a-f0-9\-]/i', '', (string) ( $s['character_id'] ?? '' ) ),
			'world_id'     => $world_id,
			'location_id'  => $location_id,
			'status'       => 'active',
		];
	}

	$session_url = $supabase_rest . 'cyber_game_sessions';
	$session_res = wp_remote_post( $session_url, [
		'headers' => [
			'apikey'        => $supabase_key,
			'Authorization' => 'Bearer ' . $supabase_key,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $sessions_payload ),
	] );

	if ( is_wp_error( $session_res ) || wp_remote_retrieve_response_code( $session_res ) >= 300 ) {
		wp_send_json_error( [ 'message' => 'session_insert_error' ] );
		return;
	}

	wp_send_json_success( [ 'message' => 'launched' ] );
}
