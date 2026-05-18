(function () {
	let activeChannelId = null;
	let chatSubscription = null;
	let currentChatTab = 'player-chat';
	let twReconnectLock = false;

	document.addEventListener(
		'DOMContentLoaded',
		function () {
			bootstrapSupabaseAndGameState();
		},
		{ once: true }
	);

	function bootstrapSupabaseAndGameState() {
		if (!window.twAdventureData) {
			console.warn('twAdventureData missing (Supabase block)');
			return;
		}

		if (window.twSupabase) {
			console.log('Supabase client already exists');
			afterSupabaseReady(window.twSupabase);
			return;
		}

		const supabaseUrl = window.twAdventureData.supabase_url;
		const supabaseKey = window.twAdventureData.supabase_anon_key;

		if (!supabaseUrl || !supabaseKey) {
			console.error('Supabase config missing (url/key)');
			return;
		}

		if (!window.supabase) {
			const script = document.createElement('script');
			script.src = 'https://unpkg.com/@supabase/supabase-js@2.48.0/dist/umd/supabase.js';
			script.async = true;
			script.onload = initSupabaseAndSync;
			document.head.appendChild(script);
		} else {
			initSupabaseAndSync();
		}

		async function initSupabaseAndSync() {
			if (!window.supabase || !window.supabase.createClient) {
				console.error('Supabase library not loaded');
				return;
			}

			const client = window.supabase.createClient(supabaseUrl, supabaseKey);
			window.twSupabase = client;
			console.log('✓ Supabase client created');

			await syncSessionForUser(client);
			await syncCharacterFromSession(client);
			hydrateTwGameState();

			afterSupabaseReady(client);
		}
	}

	async function syncSessionForUser(client) {
		const wpUserId = window.twAdventureData?.wp_user_id || null;
		if (!wpUserId) {
			console.warn('No wp_user_id for session sync');
			return;
		}

		const { data: rows, error } = await client
			.from('cyber_game_sessions')
			.select('id, campaign_id, character_id, status, created_at')
			.eq('wp_user_id', wpUserId)
			.eq('status', 'active')
			.order('created_at', { ascending: false })
			.limit(1);

		if (error) {
			console.error('Session lookup error', error);
			return;
		}

		if (!rows?.length) {
			console.warn('No active session for wp_user_id', wpUserId);
			return;
		}

		const sessionRow = rows[0];
		window.twAdventureData.active_session_id = sessionRow.id;
		window.twAdventureData.active_campaign_id = sessionRow.campaign_id;
		window.twAdventureData.active_character_id = sessionRow.character_id;

		console.log('✓ Active session synced', sessionRow);
	}

	async function syncCharacterFromSession(client) {
		const charId = window.twAdventureData?.active_character_id;
		if (!charId) {
			console.warn('No character_id to sync');
			return;
		}

		const { data: char, error } = await client
			.from('cyber_characters')
			.select('id, name, avatar, wp_user_id')
			.eq('id', charId)
			.maybeSingle();

		if (error) {
			console.error('Character lookup error', error);
			return;
		}

		if (!char) {
			console.warn('Character not found for id', charId);
			return;
		}

		window.twAdventureData.char_id = char.id;
		window.twAdventureData.char_name = char.name;
		window.twAdventureData.char_avatar = char.avatar || null;
		window.twAdventureData.char_wp_user_id = char.wp_user_id || null;

		console.log('✓ Character synced', char);
	}

	function hydrateTwGameState() {
		const data = window.twAdventureData || {};
		window.twGameState = window.twGameState || {};

		window.twGameState.currentSessionId = data.active_session_id ?? null;
		window.twGameState.currentCampaignId = data.active_campaign_id ?? null;
		window.twGameState.currentCharacterId = data.active_character_id ?? null;
		window.twGameState.currentCharacterName = data.char_name || null;
		window.twGameState.currentCharacterTags = data.char_tags || [];

		window.currentPlayerId = window.twGameState.currentCharacterId || null;
		window.twGameReady = true;

		document.dispatchEvent(new Event('twGameStateHydrated'));

		console.log('✓ twGameState hydrated', window.twGameState);
	}

	function afterSupabaseReady(client) {
		console.log('🎮 afterSupabaseReady → czekam na twGameState...');

		if (window.twGameReady) {
			initRealtimeChat();
		} else {
			document.addEventListener('twGameStateHydrated', initRealtimeChat, { once: true });
		}
	}

	function initRealtimeChat() {
		const supabase = window.twSupabase;
		if (!supabase) {
			console.error('Supabase not ready');
			return;
		}

		const sendBtn = document.querySelector('#send-btn');
		const input = document.querySelector('#chat-input');

		if (!sendBtn || !input) {
			return;
		}

		if (!sendBtn.dataset.twBound) {
			sendBtn.addEventListener('click', sendChatMessage);
			sendBtn.dataset.twBound = '1';
		}

		if (!input.dataset.twBound) {
			input.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && !e.shiftKey) {
					e.preventDefault();
					sendChatMessage();
				}
			});
			input.dataset.twBound = '1';
		}

		document.querySelectorAll('.chat-tab').forEach(function (tab) {
			if (tab.dataset.twBound) {
				return;
			}

			tab.addEventListener('click', function (e) {
				const target = e.currentTarget.getAttribute('data-chat-target');
				switchChatTab(target);
				joinChatChannel('mission');
			});

			tab.dataset.twBound = '1';
		});

		joinChatChannel('mission');
	}

	window.appendToPlayerChat = function (message, type, meta, chatWindowParam) {
		const resolvedType = type || 'system';
		const resolvedMeta = meta || {};
		const container = chatWindowParam || document.querySelector('#player-chat, .chat-window.is-active');

		if (!container) {
			return;
		}

		if (resolvedMeta.id && container.querySelector(`[data-msg-id="${resolvedMeta.id}"]`)) {
			return;
		}

		const createdAt = resolvedMeta.created_at ? new Date(resolvedMeta.created_at) : new Date();
		const now = new Date();
		const minsAgo = Math.max(0, Math.floor((now - createdAt) / 60000));
		const hhmm = createdAt.toLocaleTimeString('en-US', {
			hour12: false,
			hour: '2-digit',
			minute: '2-digit'
		});
		const ago = minsAgo === 0 ? 'now' : (minsAgo === 1 ? '1 min ago' : `${minsAgo} min ago`);

		const safeText = String(message ?? '')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/\\\\n/g, '<br>');

		const isPlayer = resolvedType === 'player';
		const name = isPlayer ? (window.twAdventureData?.char_name || 'You') : 'GM';
		const avatarUrl = isPlayer
			? (window.twAdventureData?.char_avatar || '')
			: 'https://cyber.nieodparady.pl/wp-content/uploads/2026/01/Gemini_Generated_Image_p3uzrop3uzrop3uz.png';

		const wrap = document.createElement('div');
		wrap.className = `tw-msg-row ${isPlayer ? 'is-player' : 'is-gm'}`;

		if (resolvedMeta.id) {
			wrap.dataset.msgId = resolvedMeta.id;
		}

		wrap.innerHTML = `
			<div class="tw-msg-avatar">
				${avatarUrl ? `<img src="${avatarUrl}" alt="${name}">` : ''}
			</div>
			<div class="tw-msg-body">
				<div class="tw-msg-bubble">${safeText}</div>
				<div class="tw-msg-meta">
					<span class="tw-msg-name">${name}</span>
					<span class="tw-msg-time"> · ${hhmm} · ${ago}</span>
				</div>
			</div>
		`;

		container.appendChild(wrap);
		container.scrollTop = container.scrollHeight;
	};

	async function joinChatChannel(channelType) {
		const resolvedChannelType = channelType || 'mission';
		const supabase = window.twSupabase;

		if (!supabase) {
			return;
		}

		const campaignId = window.twAdventureData?.active_campaign_id;
		const wpUserId = window.twAdventureData?.wp_user_id;

		if (!campaignId || !wpUserId) {
			return;
		}

		if (chatSubscription) {
			supabase.removeChannel(chatSubscription);
			chatSubscription = null;
		}

		const { data: existing, error: chanErr } = await supabase
			.from('cyber_chat_channels')
			.select('id')
			.eq('campaign_id', campaignId)
			.eq('wp_user_id', wpUserId)
			.eq('channel_type', resolvedChannelType)
			.maybeSingle();

		if (chanErr) {
			console.error('Channel lookup error:', chanErr);
			return;
		}

		let channelRow = existing;

		if (!channelRow) {
			const { data: inserted, error: insertErr } = await supabase
				.from('cyber_chat_channels')
				.insert({
					channel_type: resolvedChannelType,
					campaign_id: campaignId,
					wp_user_id: wpUserId
				})
				.select('id')
				.single();

			if (insertErr) {
				console.error('Channel insert error:', insertErr);
				return;
			}

			channelRow = inserted;
		}

		activeChannelId = channelRow.id;
		console.log('📡 Joined channel:', activeChannelId);

		const { data: messages } = await supabase
			.from('cyber_chat_messages')
			.select('id, content, message_type, created_at')
			.eq('channel_id', activeChannelId)
			.order('created_at', { ascending: true })
			.limit(50);

		const targetContainer = document.querySelector('#player-chat, .chat-window.is-active');
		if (targetContainer) {
			targetContainer.innerHTML = '';
		}

		messages?.forEach(function (msg) {
			window.appendToPlayerChat(msg.content, msg.message_type || 'system', msg);
		});

		chatSubscription = supabase
			.channel(`chat:${activeChannelId}`)
			.on(
				'postgres_changes',
				{
					event: 'INSERT',
					schema: 'public',
					table: 'cyber_chat_messages',
					filter: `channel_id=eq.${activeChannelId}`
				},
				function (payload) {
					window.appendToPlayerChat(
						payload.new.content,
						payload.new.message_type || 'system',
						payload.new
					);
				}
			)
			.subscribe(function (status) {
				if ((status === 'CHANNEL_ERROR' || status === 'CLOSED') && !twReconnectLock) {
					twReconnectLock = true;
					setTimeout(function () {
						twReconnectLock = false;
						joinChatChannel(resolvedChannelType);
					}, 3000);
				}
			});
	}

	function sendChatMessage() {
		const supabase = window.twSupabase;
		if (!supabase || !activeChannelId) {
			return;
		}

		const input = document.querySelector('#chat-input');
		const content = input?.value?.trim();

		if (!content) {
			return;
		}

		const characterId = window.twAdventureData?.active_character_id;
		if (!characterId) {
			return;
		}

		supabase
			.from('cyber_chat_messages')
			.insert({
				channel_id: activeChannelId,
				player_id: characterId,
				message_type: 'player',
				content: content
			})
			.then(function ({ error }) {
				if (!error) {
					input.value = '';
					input.style.height = 'auto';

					setTimeout(function () {
						if (typeof refreshTwClock === 'function') {
							refreshTwClock();
						}
					}, 500);
				}
			});
	}

	function switchChatTab(targetId) {
		if (!targetId) {
			return;
		}

		document.querySelectorAll('.chat-tab, .chat-window').forEach(function (el) {
			el.classList.remove('is-active');
		});

		const tab = document.querySelector(`[data-chat-target="${targetId}"]`);
		const win = document.getElementById(targetId);

		if (tab) {
			tab.classList.add('is-active');
		}

		if (win) {
			win.classList.add('is-active');
		}

		currentChatTab = targetId;
	}

	console.log('🎮 Tale Weaver Realtime Chat - external JS loaded');
})();
