<?php
/**
 * Template Name: Public Character Profile
 */

$char_id = isset( $_GET['char_id'] ) ? intval( $_GET['char_id'] ) : 0;

if ( ! $char_id ) {
    status_header( 404 );
    get_header();
    ?>
    <div class="neo-monitor-frame neo-crt">
        <div class="neo-scanlines"></div>
        <div class="neo-noise"></div>

        <div class="neo-terminal-wrapper">
            <header class="neo-os-header">
                <div class="neo-os-header-left">
                    <span class="neo-status-dot neo-blink"></span> 
                    <span class="neo-os-brand">NEO_WEAVE_OS_1.0.0</span>
                </div>
                <div class="neo-os-header-right">
                    <span class="neo-node-id">SYSTEM_STREAM: CHARACTER_TRACE</span>
                </div>
            </header>

            <main class="neo-os-content">
                <div class="neo-status-bar">
                    <span class="neo-sys-path">
                        UPLINK_PATH: <span class="neo-accent">CORE://LEGEND/LOOKUP</span>
                    </span>
                </div>

                <div class="neo-content-area">
                    <p>> QUERY: FIELD_AGENT_SIGNATURE</p>
                    <p class="neo-accent">> RESULT: NO MATCH FOUND</p>
                    <p>> STATUS: CHARACTER PROFILE NOT FOUND OR ACCESS LOCKED</p>
                    <p>> HINT: VERIFY LINK OR REQUEST NEW UPLINK FROM GAME MASTER</p>
                </div>
            </main>

            <footer class="neo-os-footer">
                <div class="neo-progress-container">
                    <div class="neo-progress-bar"></div>
                </div>
                <div class="neo-os-footer-meta">
                    <div class="neo-meta-item">
                        <span class="status-dot"></span><span class="neo-label"> SESSION:</span> 
                        <span class="neo-value neo-accent">IDLE</span>
                    </div>
                    <div class="neo-meta-item">
                        <span class="neo-label">SYNC:</span> 
                        <span id="sync-value" class="neo-value">00.0%</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    <?php
    get_footer();
    exit;
}
if ( ! $char_id ) {
    status_header( 403 );
    get_header();
    ?>
    <div class="neo-monitor-frame neo-crt">
        <div class="neo-scanlines"></div>
        <div class="neo-noise"></div>

        <div class="neo-terminal-wrapper">
            <header class="neo-os-header">
                <div class="neo-os-header-left">
                    <span class="neo-status-dot neo-blink"></span> 
                    <span class="neo-os-brand">NEO_WEAVE_OS_1.0.0</span>
                </div>
                <div class="neo-os-header-right">
                    <span class="neo-node-id">SYSTEM_STREAM: CHARACTER_TRACE</span>
                </div>
            </header>

            <main class="neo-os-content">
                <div class="neo-status-bar">
                    <span class="neo-sys-path">
                        UPLINK_PATH: <span class="neo-accent">CORE://LEGEND/LOOKUP</span>
                    </span>
                </div>

                <div class="neo-content-area">
                    <p>> QUERY: FIELD_AGENT_SIGNATURE</p>
                    <p class="neo-accent">> RESULT: NO MATCH FOUND</p>
                    <p>> STATUS: ACCESS_DENIED / PROFILE_ENCRYPTED</p>
                    <p>> HINT: VERIFY LINK OR REQUEST NEW UPLINK FROM GAME MASTER</p>
                </div>
            </main>

            <footer class="neo-os-footer">
                <div class="neo-progress-container">
                    <div class="neo-progress-bar"></div>
                </div>
                <div class="neo-os-footer-meta">
                    <div class="neo-meta-item">
                        <span class="status-dot"></span><span class="neo-label"> SESSION:</span> 
                        <span class="neo-value neo-accent">IDLE</span>
                    </div>
                    <div class="neo-meta-item">
                        <span class="neo-label">SYNC:</span> 
                        <span id="sync-value" class="neo-value">00.0%</span>
                    </div>
                </div>
            </footer>
        </div>
    </div>
    <?php
    get_footer();
    exit;
}

// P1 FIX: Resolve credentials once at the top; pass as arguments to helpers
// instead of calling tw_supabase_url() / tw_supabase_anon_key() repeatedly.
$supabase_url = tw_supabase_url();
$anon_key     = tw_supabase_anon_key();

if ( empty( $supabase_url ) || empty( $anon_key ) ) {
    wp_die( 'Configuration error.', '', [ 'response' => 503 ] );
}

// ── SHARED HELPER: build Supabase auth headers with timeout (P2 FIX) ──────────
function tw_supabase_args( string $anon_key ): array {
    return [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ],
        'timeout' => 10, // P2 FIX: was missing; prevents indefinite hangs
    ];
}

// ── Helper: fetch the public character with class and race ────────────────────
// P5 NOTE: These helpers belong in functions.php for a production plugin.
// The function_exists() guard prevents fatal errors when the template is
// included more than once (e.g. during full-page caching warmup).
if ( ! function_exists( 'get_public_character_data' ) ) {
    function get_public_character_data( int $id, string $supabase_url, string $anon_key ): ?array {
        $url = add_query_arg(
            [
                'id'        => 'eq.' . $id,
                'is_public' => 'eq.true',
                'select'    => '*,cyber_classes(name),cyber_races(name)',
                'limit'     => 1,
            ],
            trailingslashit( $supabase_url ) . 'rest/v1/cyber_characters'
        );

        $response = wp_remote_get( $url, tw_supabase_args( $anon_key ) );
        if ( is_wp_error( $response ) ) return null;
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return null;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $data[0] ) ? $data[0] : null;
    }
}

// ── Helper: fetch public inventory (no mechanical numbers) ────────────────────
if ( ! function_exists( 'get_public_character_inventory' ) ) {
    function get_public_character_inventory( int $char_id, string $supabase_url, string $anon_key ): array {
        $url = add_query_arg(
            [
                'character_id' => 'eq.' . $char_id,
                'select'       => 'is_equipped,equipped_slot,custom_name,cyber_items(name,description,rarity,slot,img_url)',
            ],
            trailingslashit( $supabase_url ) . 'rest/v1/cyber_character_inventory'
        );

        $res = wp_remote_get( $url, tw_supabase_args( $anon_key ) );
        if ( is_wp_error( $res ) ) return [];
        if ( wp_remote_retrieve_response_code( $res ) !== 200 ) return [];

        $items = json_decode( wp_remote_retrieve_body( $res ), true );
        return is_array( $items ) ? $items : [];
    }
}

// ── Fetch character (P1: credentials passed, not re-called inside) ────────────
$char = get_public_character_data( $char_id, $supabase_url, $anon_key );

// ── Bot detection & view counter ──────────────────────────────────────────────
$is_bot = false;
$ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( $_SERVER['HTTP_USER_AGENT'] ) : '';
$bot_patterns = [
    'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit',
    'twitterbot', 'linkedinbot', 'whatsapp', 'telegrambot', 'applebot',
    'bingpreview', 'pinterest', 'semrush', 'ahrefsbot', 'mj12bot',
];
foreach ( $bot_patterns as $pattern ) {
    if ( str_contains( $ua, $pattern ) ) {
        $is_bot = true;
        break;
    }
}

// S4 / B2 FIX: Check the RPC response before displaying the incremented count.
// Previously the counter was always shown as +1 even when the RPC failed,
// causing display drift that compounds on every failed request.
$view_count_incremented = false;

if ( ! $is_bot ) {
    $rpc_url  = trailingslashit( $supabase_url ) . 'rest/v1/rpc/fn_increment_view_count';
    // FIX: array_merge cannot deep-merge the nested 'headers' key — the second
    // array would silently overwrite the entire 'headers' from tw_supabase_args().
    // Build the RPC args directly, adding Content-Type alongside the auth headers.
    $rpc_resp = wp_remote_post(
        $rpc_url,
        [
            'headers' => [
                'apikey'        => $anon_key,
                'Authorization' => 'Bearer ' . $anon_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ 'char_id' => $char_id ] ),
            'timeout' => 10,
        ]
    );
    // Only show the incremented count when the RPC actually succeeded.
    if ( ! is_wp_error( $rpc_resp ) && wp_remote_retrieve_response_code( $rpc_resp ) === 200 ) {
        $view_count_incremented = true;
    }
}

$display_views = ( isset( $char['view_count'] ) ? intval( $char['view_count'] ) : 0 )
               + ( $view_count_incremented ? 1 : 0 );

// ── Fetch inventory and build display variables ───────────────────────────────
// P1 FIX: credentials passed explicitly, not re-resolved inside helper.
$inventory   = get_public_character_inventory( $char_id, $supabase_url, $anon_key );
$profile_url = site_url( '/legend/' ) . '?char_id=' . $char_id;

// P3 FIX: use ?? (null coalescing) instead of ?: (falsy coalescing) so that a
// legitimately empty string avatar doesn't silently fall through to placeholder.
$avatar     = $char['avatar'] ?? 'https://via.placeholder.com/140x180?text=No+Data';
$char_name  = $char['name'] ?? 'Unknown';
$class_name = $char['cyber_classes']['name'] ?? 'Operative';
$race_name  = $char['cyber_races']['name']  ?? 'Unknown';
$level      = $char['lvl'] ?? 1;

// ── All data is ready — safe to open the HTML document now ───────────────────
// B1 FIX: get_header() is called here, after all data checks and early returns.
get_header();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js" integrity="sha384-OLBgp1GsljhM2TJ+sbHjaiH9txEUvgdDTAzHv2P24donTt6/529l+9Ua0vFImLlb" crossorigin="anonymous"></script>

<style>
@import url('https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@300;400;700&display=swap');

body.character-profile {
    background: radial-gradient(circle at top, #121212 0, #050505 60%, #000000 100%);
    color: #d8ffd0;
    font-family: 'Chakra Petch', sans-serif;
}

.character-card {
    max-width: 950px;
    margin: 60px auto;
    background: rgba(10, 10, 10, 0.95);
    border: 1px solid #adff00;
    box-shadow: 0 0 25px rgba(173, 255, 0, 0.25), 0 0 80px rgba(0, 0, 0, 0.9);
    padding: 60px 40px;
    position: relative;
    border-radius: 10px;
}

.profile-meta-bar {
    position: absolute;
    top: 16px;
    left: 24px;
    right: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.8rem;
    text-transform: uppercase;
    color: #b4ff7a;
}

.share-btn {
    border-radius: 999px;
    border: 1px solid #adff00;
    background: rgba(10, 10, 10, 0.9);
    color: #adff00;
    padding: 6px 14px;
    cursor: pointer;
}

.character-header {
    display: flex;
    align-items: flex-start;
    gap: 24px;
    margin-top: 40px;
}

.character-avatar img {
    width: 140px;
    border-radius: 8px;
    border: 1px solid #adff00;
}

.character-panels {
    margin-top: 32px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
}

.character-panel {
    background: rgba(0, 0, 0, 0.6);
    border: 1px solid rgba(173, 255, 0, 0.35);
    border-radius: 8px;
    padding: 16px 18px;
}

.stats-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.stats-list li {
    display: flex;
    justify-content: space-between;
    border-bottom: 1px solid rgba(173, 255, 0, 0.1);
    padding: 4px 0;
}

.qr-container {
    position: absolute;
    top: 50%;
    right: -70px;
    transform: translateY(-50%);
    background: #fff;
    padding: 5px;
    border-radius: 4px;
}

.loom-container {
    margin-top: 30px;
    background: rgba(0, 0, 0, 0.4);
    border: 1px solid rgba(173, 255, 0, 0.2);
    border-radius: 8px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.loom-header {
    width: 100%;
    text-align: center;
    margin-bottom: 15px;
}

#archetype-name {
    color: #adff00;
    font-weight: bold;
    letter-spacing: 2px;
    font-size: 1.2rem;
    text-shadow: 0 0 10px rgba(173, 255, 0, 0.5);
}

.chart-wrapper {
    width: 100%;
    max-width: 400px;
    height: 300px;
}

.loadout-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.loadout-item-main {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 4px 0;
    border-bottom: 1px solid rgba(173, 255, 0, 0.08);
}

.loadout-item-main span:first-child {
    max-width: 70%;
}

.loadout-item-desc {
    font-size: 0.8rem;
    opacity: 0.7;
    padding: 3px 0 6px;
    border-bottom: 1px solid rgba(173, 255, 0, 0.08);
}

@media (max-width: 1024px) {
    .qr-container {
        position: static;
        transform: none;
        margin-top: 20px;
        text-align: center;
    }
    .character-panels {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="character-card">
    <div class="profile-meta-bar">
        <div class="profile-meta-left">
            <span>ID: <?php echo esc_html( $char['id'] ); ?></span> |
            <span>Created: <?php echo esc_html( date_i18n( 'Y-m-d', strtotime( $char['created_at'] ) ) ); ?></span>
        </div>
        <div class="profile-meta-right">
            <span>Views: <?php echo esc_html( $display_views ); ?></span>
            <button class="share-btn" data-share-url="<?php echo esc_url( $profile_url ); ?>">Share</button>
        </div>
    </div>

    <div class="character-header">
        <div class="character-avatar">
            <?php /* B3 FIX: meaningful alt text for accessibility */ ?>
            <img src="<?php echo esc_url( $avatar ); ?>"
                 alt="<?php echo esc_attr( $char_name ); ?> avatar">
        </div>
        <div class="character-basic">
            <h1 class="character-name"><?php echo esc_html( $char_name ); ?></h1>
            <p class="character-meta-line">
                <?php echo esc_html( $class_name . ' • ' . $race_name . ' • Lvl ' . $level ); ?>
            </p>

            <div class="loom-container">
                <div class="loom-header">
                    <span style="font-size: 0.7rem; opacity: 0.6;">SOUL ARCHETYPE:</span><br>
                    <span id="archetype-name">CALCULATING...</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="fateChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="character-panels">
        <div class="character-panel">
            <h2>Combat Parameters</h2>
            <ul class="stats-list">
                <li><span>HP</span><span><?php echo esc_html( $char['hp'] ); ?></span></li>
                <li><span>MP</span><span><?php echo esc_html( $char['mp'] ); ?></span></li>
                <li><span>Body</span><span><?php echo esc_html( $char['body'] ); ?></span></li>
                <li><span>Mind</span><span><?php echo esc_html( $char['mind'] ); ?></span></li>
                <li><span>Reflex</span><span><?php echo esc_html( $char['reflex'] ); ?></span></li>
                <li><span>Spirit</span><span><?php echo esc_html( $char['spirit'] ); ?></span></li>
            </ul>
        </div>

        <div class="character-panel">
            <h2>Biography</h2>
            <p><?php echo nl2br( esc_html( $char['bio'] ?: 'No records found in the archives.' ) ); ?></p>
        </div>

        <div class="character-panel">
            <h2>Field Loadout</h2>
            <?php if ( empty( $inventory ) ) : ?>
                <p>No visible gear registered.</p>
            <?php else : ?>
                <ul class="loadout-list">
                    <?php foreach ( $inventory as $item ) :
                        $item_core = $item['cyber_items'] ?? [];
                        $name      = $item['custom_name'] ?: ( $item_core['name'] ?? 'Unknown Item' );
                        $rarity    = $item_core['rarity'] ?? '';
                        $slot      = $item['equipped_slot'] ?: ( $item_core['slot'] ?? '' );
                        $desc      = $item_core['description'] ?? '';
                        $equipped  = ! empty( $item['is_equipped'] );
                    ?>
                        <li>
                            <div class="loadout-item-main">
                                <span>
                                    <?php if ( $equipped ) echo '⭐ '; ?>
                                    <?php echo esc_html( $name ); ?>
                                    <?php if ( $slot ) : ?>
                                        <span style="opacity:0.6;"> (<?php echo esc_html( $slot ); ?>)</span>
                                    <?php endif; ?>
                                </span>
                                <span style="opacity:0.7;"><?php echo esc_html( $rarity ); ?></span>
                            </div>
                            <?php if ( $desc ) : ?>
                                <div class="loadout-item-desc">
                                    <?php echo esc_html( $desc ); ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php
    // S3 FIX: Generate QR code server-side via wp_remote_get and embed as a
    // base64 data URI. This prevents the visitor's profile URL from being sent
    // to api.qrserver.com as a third-party HTTP request from the user's browser,
    // which could expose the URL (and indirectly the user's IP) to that service.
    $qr_api_url = add_query_arg(
        [ 'size' => '80x80', 'data' => rawurlencode( $profile_url ) ],
        'https://api.qrserver.com/v1/create-qr-code/'
    );
    $qr_resp = wp_remote_get( $qr_api_url, [ 'timeout' => 5 ] );
    $qr_src  = '';
    if ( ! is_wp_error( $qr_resp ) && wp_remote_retrieve_response_code( $qr_resp ) === 200 ) {
        $qr_src = 'data:image/png;base64,' . base64_encode( wp_remote_retrieve_body( $qr_resp ) );
    }
    ?>
    <div class="qr-container">
        <?php if ( $qr_src ) : ?>
            <img src="<?php echo esc_attr( $qr_src ); ?>" alt="QR code for this profile" width="80" height="80">
        <?php endif; ?>
    </div>
</div>

<script>
(function() {
    'use strict';

    // P8 FIX: Cast char ID to integer in JS so Supabase receives a number type,
    // not the string "123" — which causes a type mismatch on the .eq() filter.
    const charId = <?php echo intval( $char_id ); ?>;

    async function initLoom() {
        if ( ! window.twSupabase ) {
            await new Promise( resolve =>
                document.addEventListener( 'twSupabaseReady', resolve, { once: true } )
            );
        }
        const sb = window.twSupabase;

        const categories = {
            brutality: ['Attack', 'Fire', 'Melee', 'Physical', 'Lethal', 'Grit'],
            cunning:   ['Stealth', 'Reflex', 'Glitch', 'Escape', 'Thievery'],
            intellect: ['Technology', 'Hacking', 'EMP', 'Logic', 'Analysis'],
            spirit:    ['Magic', 'Chaos', 'Willpower', 'Madness', 'Void'],
            presence:  ['Persuasion', 'Diplomacy', 'Intimidation', 'Social'],
        };

        // P4 FIX: Wrap Supabase call in try/catch so a network or auth failure
        // shows a meaningful message rather than leaving the chart blank/frozen.
        try {
            const { data: deckData, error } = await sb
                .from('cyber_character_deck')
                .select('cyber_deck(tags)')
                .eq('character_id', charId);

            if ( error ) throw error;

            const stats = { brutality: 0, cunning: 0, intellect: 0, spirit: 0, presence: 0 };

            if ( Array.isArray( deckData ) ) {
                deckData.forEach( entry => {
                    const tags = ( entry.cyber_deck?.tags || '' ).toLowerCase();
                    Object.keys( categories ).forEach( cat => {
                        categories[cat].forEach( keyword => {
                            if ( tags.includes( keyword.toLowerCase() ) ) stats[cat]++;
                        } );
                    } );
                } );
            }

            renderChart( stats );
        } catch ( err ) {
            console.error( 'Loom init error:', err );
            // P4 FIX: Surface the failure to the user instead of silently failing.
            const nameEl = document.getElementById( 'archetype-name' );
            if ( nameEl ) nameEl.textContent = 'DATA UNAVAILABLE';
        }
    }

    function renderChart( stats ) {
        const canvas = document.getElementById( 'fateChart' );
        if ( ! canvas ) return;

        new Chart( canvas.getContext( '2d' ), {
            type: 'radar',
            data: {
                labels: ['BRUTALITY', 'CUNNING', 'INTELLECT', 'SPIRIT', 'PRESENCE'],
                datasets: [{
                    data: [ stats.brutality, stats.cunning, stats.intellect, stats.spirit, stats.presence ],
                    backgroundColor: 'rgba(173, 255, 0, 0.2)',
                    borderColor: '#adff00',
                    pointBackgroundColor: '#adff00',
                    borderWidth: 2,
                }],
            },
            options: {
                scales: {
                    r: {
                        min: 0,
                        suggestedMax: 5,
                        grid:        { color: 'rgba(173, 255, 0, 0.1)' },
                        angleLines:  { color: 'rgba(173, 255, 0, 0.1)' },
                        pointLabels: { color: '#adff00', font: { family: 'Chakra Petch', size: 10 } },
                        ticks:       { display: false },
                    },
                },
                plugins: { legend: { display: false } },
            },
        } );

        const sorted  = Object.entries( stats ).sort( ( a, b ) => b[1] - a[1] );
        const titles  = {
            brutality: 'THE JUGGERNAUT',
            cunning:   'THE GHOST',
            intellect: 'THE ARCHITECT',
            spirit:    'THE CONDUIT',
            presence:  'THE ICON',
        };
        const topKey  = sorted[0]?.[0];
        const topVal  = topKey ? stats[topKey] : 0;
        const nameEl  = document.getElementById( 'archetype-name' );
        if ( nameEl ) {
            // P6 FIX: textContent instead of innerText — avoids forced layout reflow.
            nameEl.textContent = topVal > 0 ? ( titles[topKey] || 'UNKNOWN PATTERN' ) : 'VOID SOUL';
        }
    }

    initLoom();
} )();

// Share button
document.addEventListener( 'click', function( e ) {
    const btn = e.target.closest( '.share-btn' );
    if ( ! btn ) return;

    const url = btn.dataset.shareUrl;
    if ( navigator.share ) {
        navigator.share( { title: 'Character Profile', url: url } );
    } else if ( navigator.clipboard ) {
        navigator.clipboard.writeText( url ).then( () => {
            // P6 FIX: textContent instead of innerText.
            btn.textContent = 'COPIED!';
            setTimeout( () => { btn.textContent = 'Share'; }, 2000 );
        } );
    }
} );
</script>

<?php get_footer(); ?>
