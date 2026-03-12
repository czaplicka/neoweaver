<?php
/**
 * Template Name: Public Character Profile
 */

// 1) ID postaci z URL
$char_id = isset( $_GET['char_id'] ) ? intval( $_GET['char_id'] ) : 0;

if ( ! $char_id ) {
    echo "Character not found.";
    return;
}

$supabase_url = tw_supabase_url();
$anon_key     = tw_supabase_anon_key();

if ( empty( $supabase_url ) || empty( $anon_key ) ) {
    echo "Configuration error.";
    return;
}

// 2) Helper: pobranie publicznej postaci z klasą i rasą
if ( ! function_exists( 'get_public_character_data' ) ) {
    function get_public_character_data( $id ) {
        $base = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_characters';

        $url = add_query_arg(
            [
                'id'        => 'eq.' . intval( $id ),
                'is_public' => 'eq.true',
                'select'    => '*,cyber_classes(name),cyber_races(name)',
            ],
            $base
        );

        $args = [
            'headers' => [
                'apikey'        => tw_supabase_anon_key(),
                'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
            ],
        ];

        $response = wp_remote_get( $url, $args );
        if ( is_wp_error( $response ) ) return null;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $data ) ? $data[0] : null;
    }
}

// 3) Helper: publiczny ekwipunek (bez liczb/mechaniki)
if ( ! function_exists( 'get_public_character_inventory' ) ) {
    function get_public_character_inventory( $char_id ) {
        $base = trailingslashit( tw_supabase_url() ) . 'rest/v1/cyber_character_inventory';

        // Join do cyber_items, wybieramy tylko to, co chcemy pokazać
        $url = add_query_arg(
            [
                'character_id' => 'eq.' . intval( $char_id ),
                'select'       => 'is_equipped,equipped_slot,custom_name,cyber_items(name,description,rarity,slot,img_url)',
            ],
            $base
        );

        $args = [
            'headers' => [
                'apikey'        => tw_supabase_anon_key(),
                'Authorization' => 'Bearer ' . tw_supabase_anon_key(),
            ],
        ];

        $res = wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) return [];

        $code = wp_remote_retrieve_response_code( $res );
        if ( $code !== 200 ) return [];

        $items = json_decode( wp_remote_retrieve_body( $res ), true );
        return is_array( $items ) ? $items : [];
    }
}

// 4) Właściwe dane postaci
$char = get_public_character_data( $char_id );

if ( ! $char ) {
    echo "<div class='error-terminal'>ACCESS DENIED: Profile private or non-existent.</div>";
    return;
}

// 5) Inkrementacja licznika wyświetleń
$current_views = isset( $char['view_count'] ) ? intval( $char['view_count'] ) : 0;
$new_views     = $current_views + 1;

$update_url = trailingslashit( $supabase_url ) . 'rest/v1/cyber_characters?id=eq.' . intval( $char_id );

wp_remote_request(
    $update_url,
    [
        'method'  => 'PATCH',
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => wp_json_encode( [ 'view_count' => $new_views ] ),
    ]
);

// 6) Publiczny ekwipunek
$inventory    = get_public_character_inventory( $char_id );
$profile_url  = site_url( '/legend/' ) . '?char_id=' . $char_id;
$avatar       = $char['avatar'] ?: 'https://via.placeholder.com/140x180?text=No+Data';
$class_name   = $char['cyber_classes']['name'] ?? 'Operative';
$race_name    = $char['cyber_races']['name']  ?? 'Unknown';
$level        = $char['lvl'] ?? 1;

get_header();
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

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

/* Stylizacja Krosna Losu */
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

/* Loadout */
.loadout-list {
    list-style:none;
    padding:0;
    margin:0;
}

.loadout-item-main {
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    padding:4px 0;
    border-bottom:1px solid rgba(173,255,0,0.08);
}

.loadout-item-main span:first-child {
    max-width:70%;
}

.loadout-item-desc {
    font-size:0.8rem;
    opacity:0.7;
    padding:3px 0 6px;
    border-bottom:1px solid rgba(173,255,0,0.08);
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
            <span>Views: <?php echo esc_html( $new_views ); ?></span>
            <button class="share-btn" data-share-url="<?php echo esc_url( $profile_url ); ?>">Share</button>
        </div>
    </div>

    <div class="character-header">
        <div class="character-avatar">
            <img src="<?php echo esc_url( $avatar ); ?>" alt="">
        </div>
        <div class="character-basic">
            <h1 class="character-name"><?php echo esc_html( $char['name'] ); ?></h1>
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

    <div class="qr-container">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?php echo urlencode( $profile_url ); ?>" alt="">
    </div>
</div>

<script>
(function() {
    async function initLoom() {
        const charId      = "<?php echo $char_id; ?>";
        const supabaseUrl = "<?php echo esc_js( $supabase_url ); ?>";
        const supabaseKey = "<?php echo esc_js( $anon_key ); ?>";

        const categories = {
            brutality: ["Attack", "Fire", "Melee", "Physical", "Lethal", "Grit"],
            cunning: ["Stealth", "Reflex", "Glitch", "Escape", "Thievery"],
            intellect: ["Technology", "Hacking", "EMP", "Logic", "Analysis"],
            spirit: ["Magic", "Chaos", "Willpower", "Madness", "Void"],
            presence: ["Persuasion", "Diplomacy", "Intimidation", "Social"]
        };

        const res = await fetch(
            `${supabaseUrl}/rest/v1/cyber_character_deck?character_id=eq.${charId}&select=cyber_deck(tags)`,
            {
                headers: {
                    'apikey': supabaseKey,
                    'Authorization': `Bearer ${supabaseKey}`
                }
            }
        );

        let deckData = [];
        try {
            deckData = await res.json();
        } catch (e) {
            deckData = [];
        }

        let stats = { brutality: 0, cunning: 0, intellect: 0, spirit: 0, presence: 0 };

        if (Array.isArray(deckData)) {
            deckData.forEach(entry => {
                const tags = (entry.cyber_deck?.tags || "").toLowerCase();
                Object.keys(categories).forEach(cat => {
                    categories[cat].forEach(keyword => {
                        if (tags.includes(keyword.toLowerCase())) stats[cat]++;
                    });
                });
            });
        }

        renderChart(stats);
    }

    function renderChart(stats) {
        const ctx = document.getElementById('fateChart').getContext('2d');
        new Chart(ctx, {
            type: 'radar',
            data: {
                labels: ['BRUTALITY', 'CUNNING', 'INTELLECT', 'SPIRIT', 'PRESENCE'],
                datasets: [{
                    data: [stats.brutality, stats.cunning, stats.intellect, stats.spirit, stats.presence],
                    backgroundColor: 'rgba(173, 255, 0, 0.2)',
                    borderColor: '#adff00',
                    pointBackgroundColor: '#adff00',
                    borderWidth: 2
                }]
            },
            options: {
                scales: {
                    r: {
                        min: 0,
                        suggestedMax: 5,
                        grid: { color: 'rgba(173, 255, 0, 0.1)' },
                        angleLines: { color: 'rgba(173, 255, 0, 0.1)' },
                        pointLabels: { color: '#adff00', font: { family: 'Chakra Petch', size: 10 } },
                        ticks: { display: false }
                    }
                },
                plugins: { legend: { display: false } }
            }
        });

        const sorted = Object.entries(stats).sort((a,b) => b[1] - a[1]);
        const titles = {
            brutality: "THE JUGGERNAUT",
            cunning:   "THE GHOST",
            intellect: "THE ARCHITECT",
            spirit:    "THE CONDUIT",
            presence:  "THE ICON"
        };

        const topKey = sorted[0]?.[0];
        const topVal = topKey ? stats[topKey] : 0;
        document.getElementById('archetype-name').innerText =
            topVal > 0 ? (titles[topKey] || "UNKNOWN PATTERN") : "VOID SOUL";
    }

    initLoom();
})();

// Share button logic
document.addEventListener('click', function(e) {
    const btn = e.target.closest('.share-btn');
    if (!btn) return;

    const url = btn.dataset.shareUrl;
    if (navigator.share) {
        navigator.share({ title: 'Character Profile', url: url });
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(url);
        btn.innerText = 'COPIED!';
        setTimeout(() => btn.innerText = 'Share', 2000);
    }
});
</script>

<?php get_footer(); ?>