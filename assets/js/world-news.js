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
    // Nie wysyłaj zapytania, jeśli nie mamy ID postaci
    if (!window.currentCharacterId) return;

    jQuery.ajax({
        url: '/wp-admin/admin-ajax.php',
        type: 'POST',
        data: {
            action: 'mark_news_read', // To musi odpowiadać add_action w PHP
            news_id: newsId,
            char_id: window.currentCharacterId
        },
        success: function(response) {
            console.log(`News ${newsId} marked as read.`);
            
            // Opcjonalnie: usuń klasę wizualną "nowości" z elementu w locie
            jQuery(`.news-item[data-id="${newsId}"]`).removeClass('is-new-item');
            
            // Sprawdź, czy zostały jeszcze jakieś nieprzeczytane newsy w modalu
            if (jQuery('.news-item.is-new-item').length === 0) {
                jQuery('#cyber-news-trigger').removeClass('has-unread');
            }
        }
    });
}
jQuery('.close-cyber-modal').on('click', function() {
    jQuery('.news-item.is-new-item').each(function() {
        const newsId = jQuery(this).data('id');
        markNewsAsRead(newsId);
    });
    jQuery('#cyber-news-modal').fadeOut(200);
});
// Zakładamy, że te zmienne są dostępne globalnie w Twoim pluginie
const NEO_TIME = {
    day: 12,
    hour: 14,
    season: 'WINTER'
};

// Funkcja otwierająca i ładująca newsy
function openNewsTerminal() {
    jQuery('#cyber-news-modal').fadeIn(200);
    
    jQuery.post('/wp-admin/admin-ajax.php', {
        action: 'get_cyber_news',
        world_id: window.currentWorldId,
        character_id: window.currentCharacterId,
        current_day: NEO_TIME.day,
        current_hour: NEO_TIME.hour
    }, function(response) {
        renderNewsList(response.news);
    });
}

// Renderowanie listy z uwzględnieniem czasu
function renderNewsList(newsArray) {
    let html = '';
    newsArray.forEach(item => {
        const isNew = item.is_new ? 'is-new-item' : '';
        
        html += `
            <div class="news-item ${isNew}" data-id="${item.id}">
                <div class="news-header">
                    <span class="news-game-time">D:${item.game_day} | H:${item.game_hour} | ${item.game_season}</span>
                    <span class="news-source">${item.source_channel}</span>
                </div>
                <div class="news-title">${item.title}</div>
                <div class="news-body">${item.content}</div>
                <div class="news-divider"></div>
            </div>
        `;
    });
    jQuery('#cyber-news-list').html(html);
}

// AUTO-READ: Przy zamykaniu modala
jQuery('.close-cyber-modal').on('click', function() {
    // Znajdź wszystkie nieprzeczytane newsy w aktualnym widoku
    jQuery('.news-item.is-new-item').each(function() {
        const newsId = jQuery(this).data('id');
        
        jQuery.post('/wp-admin/admin-ajax.php', {
            action: 'mark_news_read',
            news_id: newsId,
            char_id: window.currentCharacterId
        });
    });
    
    jQuery('#cyber-news-modal').fadeOut(200);
    // Usuń powiadomienie z ikony
    jQuery('#cyber-news-trigger').removeClass('has-unread');
});
