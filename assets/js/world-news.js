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
