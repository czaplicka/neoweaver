<?php
function cyber_news_panel_shortcode() {
    // Tutaj powinieneś pobrać world_id aktualnej sesji/gry
    // $world_id = get_current_world_id(); 

    ob_start(); ?>
    
    <div id="cyber-news-trigger" class="cyber-news-icon" title="Sub-Net Feed">
        <div class="glitch-layers">
            <span class="dashicons dashicons-rss"></span>
            <div class="glitch-overlay"></div>
        </div>
    </div>

    <div id="cyber-news-modal" class="cyber-terminal-modal">
        <div class="cyber-modal-content">
            <div class="cyber-modal-header">
                <span class="terminal-prompt">>></span> SUB-NET FEED [UNENCRYPTED]
                <button class="close-cyber-modal">&times;</button>
            </div>
            <div id="cyber-news-list" class="cyber-news-container">
                <div class="loading-text">SCANNING FREQUENCIES...</div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        const modal = $('#cyber-news-modal');
        const trigger = $('#cyber-news-trigger');

        trigger.on('click', function() {
            modal.fadeIn(200);
            fetchNews();
        });

        $('.close-cyber-modal').on('click', function() {
            modal.fadeOut(200);
        });

        function fetchNews() {
            // Tutaj dodasz swój call do API Supabase przez AJAX lub bezpośrednio
            // Poniżej przykład struktury, którą wygeneruje Twoje zapytanie SQL
            const dummyData = [
                { 
                    title: "ENTROPY SPIKE: SECTOR 7", 
                    content: "Reality fabric thinning. Avoid neural links.",
                    news_type: "ENTROPY",
                    source: "System-Admin"
                },
                { 
                    title: "Gossip: The Chrome Shadow", 
                    content: "Seen near the Black Market. Watch your credits.",
                    news_type: "SOCIAL",
                    source: "DarkNet"
                }
            ];

            let html = '';
            dummyData.forEach(news => {
                html += `
                    <div class="news-item type-${news.news_type.toLowerCase()}">
                        <div class="news-meta">[${news.source}]</div>
                        <div class="news-title">${news.title}</div>
                        <div class="news-body">${news.content}</div>
                        <div class="news-divider"></div>
                    </div>
                `;
            });
            $('#cyber-news-list').html(html);
        }
    });
    </script>

    <?php
    return ob_get_clean();
}
add_shortcode('cyber_news_panel', 'cyber_news_panel_shortcode');
