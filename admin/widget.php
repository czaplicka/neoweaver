<?php
/**
 * NeoWeaver Admin Dashboard Widget
 * Displays counts of Worlds (Nodes), Field Agents, and Campaigns (Deployments)
 * from Supabase via REST API using credentials stored in wp-config.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Stats_Widget {

    private string $supabase_url;
    private string $supabase_key;

    public function __construct() {
        // Use the same credential helpers as the rest of the plugin.
        // Falls back to SUPABASE_URL / SUPABASE_KEY constants if helpers absent.
        $this->supabase_url = function_exists( 'tw_supabase_url' )
            ? rtrim( tw_supabase_url(), '/' )
            : ( defined( 'SUPABASE_URL' ) ? rtrim( SUPABASE_URL, '/' ) : '' );

        $this->supabase_key = function_exists( 'tw_supabase_service_key' )
            ? tw_supabase_service_key()
            : ( function_exists( 'tw_supabase_anon_key' )
                ? tw_supabase_anon_key()
                : ( defined( 'SUPABASE_KEY' ) ? SUPABASE_KEY : '' ) );

        add_action( 'wp_dashboard_setup',          [ $this, 'register_widget'     ] );
        add_action( 'wp_ajax_neoweaver_refresh_stats', [ $this, 'ajax_refresh_stats' ] );
        add_action( 'admin_enqueue_scripts',        [ $this, 'enqueue_assets'      ] );
    }

    public function register_widget(): void {
        wp_add_dashboard_widget(
            'neoweaver_stats_widget',
            '⚡ NeoWeaver — Universe Stats',
            [ $this, 'render_widget' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'index.php' ) return;

        wp_add_inline_style( 'dashicons', $this->get_widget_css() );
        wp_add_inline_script( 'jquery', $this->get_widget_js() );
    }

    private function get_count( string $table ): int|string {
        if ( empty( $this->supabase_url ) || empty( $this->supabase_key ) ) {
            return 'N/A';
        }

        $url = "{$this->supabase_url}/rest/v1/{$table}?select=id&limit=1";

        $response = wp_remote_get( $url, [
            'timeout' => 8,
            'headers' => [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Prefer'        => 'count=exact',
                'Range'         => '0-0',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return '—';
        }

        // Supabase returns count in Content-Range header: 0-0/TOTAL
        $content_range = wp_remote_retrieve_header( $response, 'content-range' );
        if ( $content_range && str_contains( $content_range, '/' ) ) {
            $parts = explode( '/', $content_range );
            return (int) end( $parts );
        }

        return '?';
    }

    private function get_all_stats(): array {
        $cache_key = 'neoweaver_stats_counts';
        $cached    = get_transient( $cache_key );

        if ( $cached !== false ) {
            return $cached;
        }

        // Table names must match Supabase schema exactly (prefix cyber_ + underscore)
        $stats = [
            'worlds'    => $this->get_count( 'cyber_worlds' ),
            'agents'    => $this->get_count( 'cyber_characters' ),
            'campaigns' => $this->get_count( 'cyber_campaign' ),  // no trailing 's'
        ];

        set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );

        return $stats;
    }

    public function render_widget(): void {
        $stats = $this->get_all_stats();
        $items = [
            [
                'label'    => 'Worlds',
                'sublabel' => 'Nodes',
                'count'    => $stats['worlds'],
                'icon'     => '🌐',
                'color'    => '#adff00',
            ],
            [
                'label'    => 'Field Agents',
                'sublabel' => 'Characters',
                'count'    => $stats['agents'],
                'icon'     => '🧬',
                'color'    => '#00d4ff',
            ],
            [
                'label'    => 'Deployments',
                'sublabel' => 'Campaigns',
                'count'    => $stats['campaigns'],
                'icon'     => '⚔️',
                'color'    => '#ff6b35',
            ],
        ];
        ?>
        <div class="nw-stats-grid" id="nw-stats-grid">
            <?php foreach ( $items as $item ) : ?>
            <div class="nw-stat-card">
                <span class="nw-stat-icon"><?php echo esc_html( $item['icon'] ); ?></span>
                <span class="nw-stat-count" style="color:<?php echo esc_attr( $item['color'] ); ?>">
                    <?php echo esc_html( $item['count'] ); ?>
                </span>
                <span class="nw-stat-label"><?php echo esc_html( $item['label'] ); ?></span>
                <span class="nw-stat-sublabel"><?php echo esc_html( $item['sublabel'] ); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="nw-stats-footer">
            <button
                class="button button-secondary nw-refresh-btn"
                id="nw-refresh-btn"
                data-nonce="<?php echo esc_attr( wp_create_nonce( 'neoweaver_refresh_stats' ) ); ?>"
            >
                ↻ Refresh
            </button>
            <span class="nw-stats-updated">
                Updated: <?php echo esc_html( current_time( 'H:i' ) ); ?>
            </span>
        </div>
        <?php
    }

    public function ajax_refresh_stats(): void {
        check_ajax_referer( 'neoweaver_refresh_stats', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Forbidden', 403 );
        }

        delete_transient( 'neoweaver_stats_counts' );
        $stats = $this->get_all_stats();

        wp_send_json_success( $stats );
    }

    private function get_widget_css(): string {
        return "
        #neoweaver_stats_widget .inside { padding: 0 12px 12px; }

        .nw-stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 8px;
        }

        .nw-stat-card {
            background: #1a1a1a;
            border: 1px solid #2e2e2e;
            border-radius: 8px;
            padding: 14px 10px;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 4px;
            transition: border-color 0.2s ease;
        }

        .nw-stat-card:hover { border-color: #adff00; }

        .nw-stat-icon { font-size: 22px; line-height: 1; }

        .nw-stat-count {
            font-family: 'Chakra Petch', monospace;
            font-size: 32px;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: -1px;
        }

        .nw-stat-label {
            font-family: 'Chakra Petch', monospace;
            font-size: 12px;
            font-weight: 600;
            color: #e0e0e0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nw-stat-sublabel {
            font-size: 10px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nw-stats-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid #2e2e2e;
        }

        .nw-refresh-btn {
            font-family: 'Chakra Petch', monospace !important;
            font-size: 12px !important;
        }

        .nw-refresh-btn:hover { color: #adff00 !important; border-color: #adff00 !important; }

        .nw-stats-updated {
            font-size: 11px;
            color: #666;
            font-family: 'Chakra Petch', monospace;
        }

        .nw-stat-count.nw-loading { opacity: 0.4; }
        ";
    }

    private function get_widget_js(): string {
        return "
        jQuery(function($){
            $('#nw-refresh-btn').on('click', function(e){
                e.preventDefault();
                var btn   = $(this);
                var nonce = btn.data('nonce');

                btn.prop('disabled', true).text('↻ Loading…');
                $('.nw-stat-count').addClass('nw-loading');

                $.post(ajaxurl, {
                    action: 'neoweaver_refresh_stats',
                    nonce:  nonce
                }, function(response){
                    if (response.success) {
                        var d = response.data;
                        var counts = [d.worlds, d.agents, d.campaigns];
                        $('.nw-stat-count').each(function(i){
                            $(this).text(counts[i]).removeClass('nw-loading');
                        });
                        var now = new Date();
                        var h = String(now.getHours()).padStart(2,'0');
                        var m = String(now.getMinutes()).padStart(2,'0');
                        $('.nw-stats-updated').text('Updated: ' + h + ':' + m);
                    }
                    btn.prop('disabled', false).text('↻ Refresh');
                }).fail(function(){
                    btn.prop('disabled', false).text('↻ Refresh');
                    $('.nw-stat-count').removeClass('nw-loading');
                });
            });
        });
        ";
    }
}

new NeoWeaver_Stats_Widget();
