<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_action( 'wp_footer', function () {
    if ( ! is_page_template( 'templates/adventure.php' ) ) return;
    $user_id = get_current_user_id();
    if ( ! $user_id ) return;
    ?>
    <script>
// ========================================================
// TALE WEAVER REALTIME CHAT v1.4.1 - SYNCHRONIZED VERSION
// ========================================================
let activeChannelId = null;
let chatSubscription = null;
let currentChatTab = 'player-chat';
let twReconnectLock = false;

// --- INICJALIZACJA SUPABASE I STANU GRY ---
document.addEventListener('DOMContentLoaded', function () {
  (function () {
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
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/@supabase/supabase-js@2.48.0/dist/umd/supabase.js';
      s.async = true;
      s.onload = initSupabaseAndSync;
      document.head.appendChild(s);
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

    async function syncSessionForUser(client) {
      const wpUserId = window.twAdventureData.wp_user_id || null;
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

      if (error) return console.error('Session lookup error', error);
      if (!rows?.length) return console.warn('No active session for wp_user_id', wpUserId);

      const sRow = rows[0];
      window.twAdventureData.active_session_id  = sRow.id;
      window.twAdventureData.active_campaign_id = sRow.campaign_id;
      window.twAdventureData.active_character_id = sRow.character_id;

      console.log('✓ Active session synced', sRow);
    }

    async function syncCharacterFromSession(client) {
      const charId = window.twAdventureData.active_character_id;
      if (!charId) return console.warn('No character_id to sync');

      const { data: char, error } = await client
        .from('cyber_characters')
        .select('id, name, avatar, wp_user_id')
        .eq('id', charId)
        .maybeSingle();

      if (error) return console.error('Character lookup error', error);
      if (!char) return console.warn('Character not found for id', charId);

      window.twAdventureData.char_id          = char.id;
      window.twAdventureData.char_name        = char.name;
      window.twAdventureData.char_avatar      = char.avatar || null;
      window.twAdventureData.char_wp_user_id  = char.wp_user_id || null;

      console.log('✓ Character synced', char);
    }

    function hydrateTwGameState() {
      const data = window.twAdventureData;
      window.twGameState = window.twGameState || {};

      window.twGameState.currentSessionId      = data.active_session_id   ?? null;
      window.twGameState.currentCampaignId     = data.active_campaign_id  ?? null;
      window.twGameState.currentCharacterId    = data.active_character_id ?? null;
      window.twGameState.currentCharacterName  = data.char_name           || null;
      window.twGameState.currentCharacterTags  = data.char_tags           || [];

      window.currentPlayerId = window.twGameState.currentCharacterId || null;

      document.dispatchEvent(new Event('twGameStateHydrated'));
      window.twGameReady = true;

      console.log('✓ twGameState hydrated', window.twGameState);
    }
  })();
});

// --- CHAT BOOTSTRAP ---
function afterSupabaseReady(client) {
  console.log('🎮 afterSupabaseReady → czekam na twGameState...');

  if (window.twGameReady) {
    initRealtimeChat();
  } else {
    document.addEventListener('twGameStateHydrated', initRealtimeChat);
  }
}

function initRealtimeChat() {
  const supabase = window.twSupabase;
  if (!supabase) {
    console.error('Supabase not ready');
    return;
  }

  const sendBtn = document.querySelector('#send-btn');
  const input   = document.querySelector('#chat-input');

  if (!sendBtn || !input) return;

  if (!sendBtn.dataset.twBound) {
    sendBtn.addEventListener('click', sendChatMessage);
    sendBtn.dataset.twBound = '1';
  }
  if (!input.dataset.twBound) {
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendChatMessage();
      }
    });
    input.dataset.twBound = '1';
  }

  document.querySelectorAll('.chat-tab').forEach(tab => {
    if (tab.dataset.twBound) return;
    tab.addEventListener('click', (e) => {
      const target = e.target.getAttribute('data-chat-target');
      switchChatTab(target);
      joinChatChannel('mission');
    });
    tab.dataset.twBound = '1';
  });

  joinChatChannel('mission');
}

// --- RENDER MSG ---
window.appendToPlayerChat = function(message, type = 'system', meta = {}, chatWindowParam) {
  const container = chatWindowParam || document.querySelector('#player-chat, .chat-window.is-active');
  if (!container) return;

  if (meta.id && container.querySelector(`[data-msg-id="${meta.id}"]`)) return;

  const createdAt = meta.created_at ? new Date(meta.created_at) : new Date();
  const now       = new Date();
  const minsAgo   = Math.max(0, Math.floor((now - createdAt) / 60000));
  const hhmm      = createdAt.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit' });
  const ago       = minsAgo === 0 ? 'now' : (minsAgo === 1 ? '1 min ago' : `${minsAgo} min ago`);

  const safeText = String(message ?? '')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\\\\n/g, '<br>');

  const isPlayer  = (type === 'player');
  const name      = isPlayer ? (window.twAdventureData?.char_name || 'You') : 'GM';
  const avatarUrl = isPlayer
    ? (window.twAdventureData?.char_avatar || '')
    : 'https://cyber.nieodparady.pl/wp-content/uploads/2026/01/Gemini_Generated_Image_p3uzrop3uzrop3uz.png';

  const wrap = document.createElement('div');
  wrap.className = `tw-msg-row ${isPlayer ? 'is-player' : 'is-gm'}`;
  if (meta.id) wrap.dataset.msgId = meta.id;

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

// --- JOIN CHANNEL ---
async function joinChatChannel(channelType = 'mission') {
  const supabase = window.twSupabase;
  if (!supabase) return;

  const campaignId = window.twAdventureData?.active_campaign_id;
  const wpUserId   = window.twAdventureData?.wp_user_id;

  if (!campaignId || !wpUserId) return;

  if (chatSubscription) {
    supabase.removeChannel(chatSubscription);
    chatSubscription = null;
  }

  // BUG-FIX: cyber_chat_channels has no player_id column.
  // The schema uses campaign_id + wp_user_id to identify a channel.
  // The previous code filtered and inserted with player_id (= characterId),
  // which always failed because that column does not exist, leaving
  // activeChannelId null and silently breaking all chat for new sessions.
  // Fixed: use wp_user_id (integer, from twAdventureData) for both the
  // lookup filter and the insert payload.
  const { data: existing, error: chanErr } = await supabase
    .from('cyber_chat_channels')
    .select('id')
    .eq('campaign_id',  campaignId)
    .eq('wp_user_id',   wpUserId)
    .eq('channel_type', channelType)
    .maybeSingle();

  if (chanErr) return console.error('Channel lookup error:', chanErr);

  let channelRow = existing;
  if (!channelRow) {
    const { data: inserted, error: insertErr } = await supabase
      .from('cyber_chat_channels')
      .insert({
        channel_type: channelType,
        campaign_id:  campaignId,
        wp_user_id:   wpUserId,
      })
      .select('id')
      .single();

    if (insertErr) return console.error('Channel insert error:', insertErr);
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
  if (targetContainer) targetContainer.innerHTML = '';

  messages?.forEach(m => window.appendToPlayerChat(m.content, m.message_type || 'system', m));

  chatSubscription = supabase
    .channel(`chat:${activeChannelId}`)
    .on('postgres_changes', {
      event:  'INSERT',
      schema: 'public',
      table:  'cyber_chat_messages',
      filter: `channel_id=eq.${activeChannelId}`
    }, payload => {
      window.appendToPlayerChat(payload.new.content, payload.new.message_type || 'system', payload.new);
    })
    .subscribe((status, err) => {
      if ((status === 'CHANNEL_ERROR' || status === 'CLOSED') && !twReconnectLock) {
        twReconnectLock = true;
        setTimeout(() => {
          twReconnectLock = false;
          joinChatChannel(channelType);
        }, 3000);
      }
    });
}

function sendChatMessage() {
    const supabase = window.twSupabase;
    if (!supabase || !activeChannelId) return;

    const input = document.querySelector('#chat-input');
    const content = input?.value?.trim();
    if (!content) return;

    const characterId = window.twAdventureData?.active_character_id;
    if (!characterId) return;

    supabase
        .from('cyber_chat_messages')
        .insert({
            channel_id:   activeChannelId,
            player_id:    characterId,
            message_type: 'player',
            content
        })
        .then(({ error }) => {
            if (!error) {
                input.value = '';
                input.style.height = 'auto';
                setTimeout(() => { if (typeof refreshTwClock === 'function') refreshTwClock(); }, 500);
            }
        });
}

function switchChatTab(targetId) {
  if (!targetId) return;
  document.querySelectorAll('.chat-tab, .chat-window').forEach(el => el.classList.remove('is-active'));
  const tab = document.querySelector(`[data-chat-target="${targetId}"]`);
  const win = document.getElementById(targetId);
  if (tab) tab.classList.add('is-active');
  if (win) win.classList.add('is-active');
  currentChatTab = targetId;
}

console.log('🎮 Tale Weaver Realtime Chat v1.4.1 - SYNCHRONIZED LOADED');
    </script>
    <?php
}, 20 );
