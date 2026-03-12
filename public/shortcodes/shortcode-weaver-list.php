add_shortcode('tw_weaver_list', 'tw_display_weaver_list');

function tw_display_weaver_list() {
    // 1. Pobierz character_id za pomocą Twojego handlera z CORE
    $char_id = tw_get_current_character_id();
    
    if (!$char_id || $char_id == 0) {
        return '<div class="tw-weaver-error">No active character found. Please select a character first.</div>';
    }

    // 2. Dane Supabase pobrane z funkcji CORE (zamiast bezpośrednio ze stałych)
    $supabase_url = tw_supabase_url();
    $anon_key = tw_supabase_anon_key();

    if (empty($supabase_url) || empty($anon_key)) {
        return '<p>Configuration Error: Supabase credentials missing.</p>';
    }

    // 3. Pobranie Splotów (Weaves) dla tej postaci
    $base_url = trailingslashit($supabase_url) . 'rest/v1/cyber_weaves';
    $url = add_query_arg([
        'character_id' => 'eq.' . $char_id,
        'is_consumed' => 'is.false', 
        'select' => '*'
    ], $base_url);

    $response = wp_remote_get($url, [
        'headers' => [
            'apikey' => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
            'Content-Type' => 'application/json'
        ],
        'timeout'   => 15,
        'sslverify' => true
    ]);

    if (is_wp_error($response)) {
        return '<p>Connection Error.</p>';
    }

    $weaves = json_decode(wp_remote_retrieve_body($response), true);

    if (empty($weaves) || !is_array($weaves)) {
        return '<div class="tw-no-weaves">Your Weaver pouch is empty. Dissolve items or complete quests to gain Weaves.</div>';
    }

    // 4. Generowanie HTML
    $output = '<div class="tw-weaver-container">';
    $output .= '<div class="tw-weaver-grid">';

    foreach ($weaves as $weave) {
        $rarity = strtolower($weave['rarity'] ?? 'common');
        $tag = htmlspecialchars($weave['tag_reference'] ?? 'General');
        $name = htmlspecialchars($weave['name'] ?? 'Unknown Weave');
        $desc = htmlspecialchars($weave['description'] ?? '');

        $output .= "
        <div class='tw-weaver-card rarity-{$rarity}'>
            <div class='tw-weaver-header'>
                <span class='tw-weaver-name'>{$name}</span>
                <span class='tw-weaver-tag'>#{$tag}</span>
            </div>
            <div class='tw-weaver-desc'>{$desc}</div>
            <div class='tw-weaver-footer'>
                <span class='tw-rarity-label'>{$rarity}</span>
            </div>
        </div>";
    }

    $output .= '</div></div>';

    // 5. CSS (Bez zmian, dopasowany do Tale Weaver)
    $output .= '
    <style>
        .tw-weaver-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); 
            gap: 20px; 
            padding: 10px 0;
        }
        .tw-weaver-card { 
            background: rgba(20, 20, 20, 0.9); 
            border: 1px solid #333; 
            border-radius: 4px; 
            padding: 15px; 
            position: relative;
            font-family: "Chakra Petch", sans-serif;
            transition: all 0.3s ease;
        }
        .tw-weaver-card:hover {
            border-color: #adff00;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(173, 255, 0, 0.1);
        }
        .tw-weaver-header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            margin-bottom: 12px;
            border-bottom: 1px solid #222;
            padding-bottom: 8px;
        }
        .tw-weaver-name { 
            font-weight: 700; 
            color: #fff; 
            font-size: 1rem;
            text-transform: uppercase;
        }
        .tw-weaver-tag { 
            color: #adff00; 
            font-size: 0.75rem; 
            font-weight: bold;
            letter-spacing: 1px;
        }
        .tw-weaver-desc { 
            font-size: 0.85rem; 
            color: #bbb; 
            line-height: 1.4;
            min-height: 40px;
        }
        .tw-weaver-footer {
            margin-top: 10px;
            text-align: right;
        }
        .tw-rarity-label {
            font-size: 0.65rem;
            text-transform: uppercase;
            padding: 2px 6px;
            background: #222;
            border-radius: 2px;
            color: #888;
        }
        .rarity-common { border-top: 3px solid #888; }
        .rarity-uncommon { border-top: 3px solid #00ff88; }
        .rarity-rare { border-top: 3px solid #0088ff; }
        .rarity-epic { border-top: 3px solid #a033ff; }
        .rarity-legendary { border-top: 3px solid #ffaa00; }
        .tw-no-weaves, .tw-weaver-error {
            padding: 20px;
            background: #111;
            border: 1px dashed #333;
            color: #666;
            text-align: center;
        }
    </style>';

    return $output;
}