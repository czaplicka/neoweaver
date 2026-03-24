function fetchNews() {
    const container = jQuery('#cyber-news-list');
    container.html('<div class="loading-text">CONNECTING TO SUB-NET...</div>');

    jQuery.ajax({
        url: '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: {
            action: 'get_cyber_news',
            world_id: window.currentWorldId, // Musisz mieć to ID dostępne w JS
            clearance: window.playerClearance || 0
        },
        success: function(response) {
            if (!response || response.length === 0) {
                container.html('<div class="no-data">[SIGNAL LOST: NO DATA FRAGMENTS FOUND]</div>');
                return;
            }

            let html = '';
            response.forEach(news => {
                // Dobór ikony na podstawie powiązań
                let icon = '⚡'; 
                if (news.npc_id) icon = '👤';
                if (news.event_id) icon = '⚠️';
                if (news.location_id) icon = '📍';
                if (news.news_type === 'SOCIAL_MEDIA') icon = '📱';

                html += `
                    <div class="news-item type-${news.news_type ? news.news_type.toLowerCase() : 'general'}">
                        <div class="news-header">
                            <span class="news-icon">${icon}</span>
                            <span class="news-source">${news.source_channel || 'ANONYMOUS'}</span>
                            <span class="news-time">${new Date(news.created_at).toLocaleTimeString()}</span>
                        </div>
                        <div class="news-title">${news.title}</div>
                        <div class="news-body">${news.content}</div>
                        ${news.tags ? `<div class="news-tags">${news.tags.map(t => `#${t}`).join(' ')}</div>` : ''}
                        <div class="news-divider"></div>
                    </div>
                `;
            });
            container.html(html);
        },
        error: function() {
            container.html('<div class="error">[CRITICAL ERROR: TERMINAL OFFLINE]</div>');
        }
    });
}
// Funkcja odświeżająca status powiadomień (wywoływana np. co zmianę lokacji)
function checkNewsNotifications() {
    jQuery.post('/wp-admin/admin-ajax.php', {
        action: 'get_cyber_news',
        world_id: window.currentWorldId,
        character_id: window.currentCharacterId,
        current_day: window.gameState.day, // Twoja zmienna czasu gry
        current_hour: window.gameState.hour
    }, function(response) {
        if (response.unread_count > 0) {
            jQuery('#cyber-news-trigger').addClass('has-unread');
            // Opcjonalnie: dodaj licznik nad ikoną
            jQuery('#news-badge').text(response.unread_count).show();
        } else {
            jQuery('#cyber-news-trigger').removeClass('has-unread');
            jQuery('#news-badge').hide();
        }
    });
}

// Oznaczanie jako przeczytane po otwarciu modala
function markNewsAsRead(newsId) {
    // Tutaj wysyłasz szybki UPDATE do Supabase dodający character_id do tablicy read_by
    // AI GM może to robić automatycznie przy otwarciu modala
}
