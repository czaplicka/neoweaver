// 1. Ustawienia czasu (upewnij się, że window.gameState istnieje w grze)
const NEO_TIME = {
    day: window.gameState?.day || 1,
    hour: window.gameState?.hour || 12,
    season: window.gameState?.season || 'SPRING'
};

// 2. Główna funkcja otwierająca terminal i ładująca newsy
function openNewsTerminal() {
    const container = jQuery('#cyber-news-list');
    jQuery('#cyber-news-modal').fadeIn(200);
    container.html('<div class="loading-text">CONNECTING TO SUB-NET...</div>');
    
    jQuery.post('/wp-admin/admin-ajax.php', {
        action: 'get_cyber_news',
        world_id: window.currentWorldId,
        character_id: window.currentCharacterId,
        current_day: NEO_TIME.day,
        current_hour: NEO_TIME.hour,
        clearance: window.playerClearance || 0
    }, function(response) {
        if (!response.news || response.news.length === 0) {
            container.html('<div class="no-data">[SIGNAL LOST: NO DATA FRAGMENTS FOUND]</div>');
            return;
        }
        renderNewsList(response.news);
    }).fail(function() {
        container.html('<div class="error">[CRITICAL ERROR: TERMINAL OFFLINE]</div>');
    });
}

// 3. Renderowanie listy z uwzględnieniem ikon i statusu "new"
function renderNewsList(newsArray) {
    let html = '';
    newsArray.forEach(item => {
        const isNew = item.is_new ? 'is-new-item' : '';
        
        // Dobór ikony
        let icon = '⚡'; 
        if (item.npc_id) icon = '👤';
        if (item.event_id) icon = '⚠️';
        if (item.location_id) icon = '📍';
        if (item.news_type === 'SOCIAL_MEDIA') icon = '📱';

        html += `
            <div class="news-item ${isNew}" data-id="${item.id}">
                <div class="news-header">
                    <span class="news-icon">${icon}</span>
                    <span class="news-game-time">D:${item.game_day} | H:${item.game_hour} | ${item.game_season}</span>
                    <span class="news-source">${item.source_channel || 'ANONYMOUS'}</span>
                </div>
                <div class="news-title">${item.title}</div>
                <div class="news-body">${item.content}</div>
                ${item.tags ? `<div class="news-tags">${item.tags.map(t => `#${t}`).join(' ')}</div>` : ''}
                <div class="news-divider"></div>
            </div>
        `;
    });
    jQuery('#cyber-news-list').html(html);
}

// 4. Mechanizm sprawdzania powiadomień (wywoływany np. przy starcie lokacji)
function checkNewsNotifications() {
    jQuery.post('/wp-admin/admin-ajax.php', {
        action: 'get_cyber_news',
        world_id: window.currentWorldId,
        character_id: window.currentCharacterId,
        current_day: NEO_TIME.day,
        current_hour: NEO_TIME.hour
    }, function(response) {
        if (response.unread_count > 0) {
            jQuery('#cyber-news-trigger').addClass('has-unread');
            jQuery('#news-badge').text(response.unread_count).show();
        } else {
            jQuery('#cyber-news-trigger').removeClass('has-unread');
            jQuery('#news-badge').hide();
        }
    });
}

// 5. Oznaczanie jako przeczytane
function markNewsAsRead(newsId) {
    if (!window.currentCharacterId) return;

    jQuery.post('/wp-admin/admin-ajax.php', {
        action: 'mark_news_read',
        news_id: newsId,
        char_id: window.currentCharacterId
    });
}

// 6. OBSŁUGA ZDARZEŃ (Event Listeners)
jQuery(document).ready(function($) {
    // Kliknięcie w ikonę otwiera terminal
    $('#cyber-news-trigger').on('click', function() {
        openNewsTerminal();
    });

    // Zamykanie modala + AUTO-READ
    $('.close-cyber-modal').on('click', function() {
        $('.news-item.is-new-item').each(function() {
            const newsId = $(this).data('id');
            markNewsAsRead(newsId);
            $(this).removeClass('is-new-item'); // Usuń podświetlenie od razu w UI
        });
        
        $('#cyber-news-modal').fadeOut(200);
        $('#cyber-news-trigger').removeClass('has-unread');
        $('#news-badge').hide();
    });
});
