/**
 * NEOWEAVER — Chat GM Dispatcher v1.0
 *
 * Przechwytuje wysyłanie wiadomości przez gracza, wstrzykuje wywołanie
 * AJAX do backendu WordPress (tw_chat_gm), a po otrzymaniu odpowiedzi:
 *   1. Wyświetla tekst GM w oknie czatu (appendToPlayerChat)
 *   2. Przetwarza tagi gamestate (#HP_CHANGE, #LOC, #GOLD_CHANGE itd.)
 *      i emituje zdarzenie CustomEvent('twTagsReceived') do nasłuchu
 *      przez inne moduły (HUD, mapa, inventory)
 *   3. Opcjonalnie blokuje pole inputu podczas oczekiwania (UX)
 *
 * Wymaga w window:
 *   twChatData.ajax_url  — admin-ajax.php URL (lokalizowany przez PHP)
 *   twChatData.nonce     — nonce tw_chat_nonce
 *   twAdventureData.*    — dane sesji
 *   appendToPlayerChat() — z chat-realtime.php
 *   activeChannelId      — z chat-realtime.php
 */

(function () {
  'use strict';

  // Czekamy aż czat będzie gotowy
  document.addEventListener('twGameStateHydrated', initGMDispatcher);
  // Fallback jeśli zdarzenie już minęło
  if (window.twGameReady) initGMDispatcher();

  function initGMDispatcher() {
    // Nie bindujemy dwa razy
    if (window._twGMDispatcherBound) return;
    window._twGMDispatcherBound = true;

    // Podpinamy się do przycisku wysyłania (ten sam co chat-realtime.php)
    // Używamy event delegation na dokumencie — bezpieczniejsze przy dynamicznym DOM
    document.addEventListener('click', function (e) {
      if (e.target && e.target.id === 'send-btn') {
        e.stopImmediatePropagation(); // nie duplikujemy Supabase insert z chat-realtime
        handleSendWithGM();
      }
    }, true); // capture phase — przed handlerami z chat-realtime

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        const focused = document.activeElement;
        if (focused && focused.id === 'chat-input') {
          e.stopImmediatePropagation();
          e.preventDefault();
          handleSendWithGM();
        }
      }
    }, true);

    console.log('🤖 GM Dispatcher v1.0 bound');
  }

  async function handleSendWithGM() {
    const input     = document.querySelector('#chat-input');
    const sendBtn   = document.querySelector('#send-btn');
    const content   = input?.value?.trim();

    if (!content) return;

    const channelId  = window.activeChannelId;
    const charId     = window.twAdventureData?.active_character_id || window.twAdventureData?.char_id;
    const sessionId  = window.twAdventureData?.active_session_id  || '';
    const campaignId = window.twAdventureData?.active_campaign_id || '';
    const nonce      = window.twChatData?.nonce || '';
    const ajaxUrl    = window.twChatData?.ajax_url || window.ajaxurl || '';

    if (!channelId || !charId || !nonce || !ajaxUrl) {
      console.warn('GM Dispatcher: brak wymaganych danych (channelId/charId/nonce/ajaxUrl)');
      return;
    }

    // --- Wyświetl wiadomość gracza natychmiast (optimistic UI) ---
    if (typeof window.appendToPlayerChat === 'function') {
      window.appendToPlayerChat(content, 'player', { created_at: new Date().toISOString() });
    }

    // --- Zapisz wiadomość gracza do Supabase (jak dotychczas robił chat-realtime) ---
    if (window.twSupabase && channelId) {
      window.twSupabase
        .from('cyber_chat_messages')
        .insert({
          channel_id:   channelId,
          player_id:    charId,
          message_type: 'player',
          content:      content,
        })
        .then(({ error }) => {
          if (error) console.error('GM Dispatcher: błąd zapisu wiadomości gracza', error);
        });
    }

    // --- Wyczyść input, zablokuj na czas odpowiedzi ---
    input.value = '';
    input.style.height = 'auto';
    setInputBusy(true, input, sendBtn);

    // --- Wywołaj backend ---
    try {
      const formData = new FormData();
      formData.append('action',      'tw_chat_gm');
      formData.append('nonce',       nonce);
      formData.append('message',     content);
      formData.append('char_id',     charId);
      formData.append('channel_id',  channelId);
      formData.append('session_id',  sessionId);
      formData.append('campaign_id', campaignId);

      const response = await fetch(ajaxUrl, {
        method:      'POST',
        credentials: 'same-origin',
        body:        formData,
      });

      if (!response.ok) throw new Error('HTTP ' + response.status);

      const json = await response.json();

      if (!json.success) {
        console.error('GM Dispatcher: błąd backendu', json.data?.message);
        showGMError(json.data?.message || 'GM nie odpowiada — spróbuj ponownie.');
        return;
      }

      const gmText  = json.data?.text     || '';
      const gmTags  = json.data?.tags     || [];
      const protocol = json.data?.protocol || 'UNKNOWN';

      // --- Wyświetl odpowiedź GM ---
      // Uwaga: GM zapisał wiadomość do Supabase, Realtime ją odbierze automatycznie.
      // appendToPlayerChat wywołujemy TYLKO jeśli Realtime nie jest aktywny (fallback).
      if (!window.chatSubscription) {
        if (typeof window.appendToPlayerChat === 'function') {
          window.appendToPlayerChat(gmText, 'gm', { created_at: new Date().toISOString() });
        }
      }

      // --- Procesuj tagi gamestate ---
      if (gmTags.length > 0) {
        processTags(gmTags, charId);
      }

      console.log('🎲 GM response received | protocol:', protocol, '| tags:', gmTags);

    } catch (err) {
      console.error('GM Dispatcher: błąd fetch', err);
      showGMError('Błąd połączenia z GM. Spróbuj ponownie.');
    } finally {
      setInputBusy(false, input, sendBtn);
    }
  }

  /**
   * Przetwarza tagi gamestate zwrócone przez ai-engine.php.
   * Emituje CustomEvent 'twTagsReceived' — inne moduły (HUD, mapa)
   * mogą go nasłuchiwać i reagować na zmiany stanu.
   *
   * Tagi obsługiwane bezpośrednio tu (UI-only):
   *   #HP_CHANGE:N       — odświeża pasek HP
   *   #GOLD_CHANGE:N     — odświeża licznik złota
   *   #STATUS_ADD:name   — dodaje status badge
   *   #LOC:uuid          — zmienia lokację (dispatch do mapy)
   *   #ENTROPY_UP:N      — aktualizuje entropy bar
   *   #SESSION_END       — kończy sesję
   *
   * Faktyczne zmiany w Supabase robi backend (parser w ai-engine.php
   * zwraca tagi, a kolejna warstwa — np. Make.com lub osobny AJAX —
   * je aplikuje). Tu tylko UI.
   */
  function processTags(tags, charId) {
    document.dispatchEvent(new CustomEvent('twTagsReceived', {
      detail: { tags, charId }
    }));

    tags.forEach(({ tag, val }) => {
      switch (tag) {

        case 'HP_CHANGE': {
          const delta = parseInt(val, 10);
          if (!isNaN(delta)) updateHUDBar('hp', delta);
          break;
        }

        case 'GOLD_CHANGE': {
          const delta = parseInt(val, 10);
          if (!isNaN(delta)) updateHUDValue('gold', delta);
          break;
        }

        case 'ENTROPY_UP': {
          const delta = parseInt(val, 10);
          if (!isNaN(delta)) updateHUDBar('entropy', delta);
          break;
        }

        case 'STATUS_ADD':
          if (val) addStatusBadge(val);
          break;

        case 'STATUS_REMOVE':
          if (val) removeStatusBadge(val);
          break;

        case 'LOC':
          // Dispatch do modułu mapy — ten moduł decyduje co zrobić
          document.dispatchEvent(new CustomEvent('twLocationChange', { detail: { locationId: val } }));
          break;

        case 'SESSION_END':
          document.dispatchEvent(new CustomEvent('twSessionEnd'));
          break;

        default:
          // Nieznany tag — emitujemy, inne moduły mogą obsłużyć
          break;
      }
    });
  }

  // --- Pomocnicze UI ---

  function setInputBusy(busy, input, sendBtn) {
    if (input)   input.disabled   = busy;
    if (sendBtn) sendBtn.disabled = busy;
    if (sendBtn) sendBtn.classList.toggle('is-loading', busy);
  }

  function showGMError(msg) {
    if (typeof window.appendToPlayerChat === 'function') {
      window.appendToPlayerChat(
        '⚠️ ' + msg,
        'system',
        { created_at: new Date().toISOString() }
      );
    }
  }

  function updateHUDBar(type, delta) {
    // Szuka elementów z data-hud="hp" lub data-hud="entropy"
    const bar = document.querySelector(`[data-hud="${type}"]`);
    if (!bar) return;
    const current = parseInt(bar.dataset.current || bar.textContent, 10);
    if (!isNaN(current)) {
      const newVal = current + delta;
      bar.dataset.current = newVal;
      // Jeśli to <progress> lub pasek z szerokością — zaktualizuj
      if (bar.tagName === 'PROGRESS') bar.value = newVal;
      else bar.textContent = newVal;
    }
  }

  function updateHUDValue(type, delta) {
    const el = document.querySelector(`[data-hud="${type}"]`);
    if (!el) return;
    const current = parseInt(el.textContent.replace(/[^\d-]/g, ''), 10);
    if (!isNaN(current)) el.textContent = current + delta;
  }

  function addStatusBadge(status) {
    const container = document.querySelector('[data-hud="status-badges"]');
    if (!container) return;
    if (container.querySelector(`[data-status="${status}"]`)) return; // już istnieje
    const badge = document.createElement('span');
    badge.className = 'tw-status-badge';
    badge.dataset.status = status;
    badge.textContent = status.toLowerCase().replace(/_/g, ' ');
    container.appendChild(badge);
  }

  function removeStatusBadge(status) {
    const badge = document.querySelector(`[data-status="${status}"]`);
    if (badge) badge.remove();
  }

})();
