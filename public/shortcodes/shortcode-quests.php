function tw_time_ago($timestamp) {
	if ( ! is_page( 2857 ) ) { return ''; }
    $created = strtotime($timestamp);
    if (!$created) return '';

    $now  = current_time('timestamp');
    $diff = $now - $created;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' min ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' h ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' d ago';
    }
}

function tw_display_active_scenarios_shortcode() {
    $supabase_url = 'https://' . TW_SUPABASE_PROJECT_ID . '.supabase.co/rest/v1/cyber_active_quests';
    $supabase_key = TW_SUPABASE_ANON_KEY;

    $character_id = tw_get_current_character_id();

    if (!$character_id) {
        return '<div class="echo-stream-container">// ERROR: NO ACTIVE SESSION DETECTED</div>';
    }

    // bierzemy wszystkie questy tej postaci, bez filtrowania po statusie
    $url = add_query_arg([
        'character_id' => 'eq.' . $character_id,
        'select'       => '*,cyber_scenarios(*,cyber_areas(*))'
    ], $supabase_url);

    $response = wp_remote_get($url, [
        'headers' => [
            'apikey'        => $supabase_key,
            'Authorization' => 'Bearer ' . $supabase_key
        ]
    ]);

    if (is_wp_error($response)) {
        return '<div class="scenario-card" style="opacity:0.5; text-align:center; border:1px dashed #444;">// CONNECTION ERROR</div>';
    }

    $body   = wp_remote_retrieve_body($response);
    $quests = json_decode($body, true);

    if (!is_array($quests) || (isset($quests['code']) && isset($quests['message']))) {
        return '<div class="scenario-card" style="opacity:0.5; text-align:center; border:1px dashed #444;">// API ERROR</div>';
    }

    if (empty($quests)) {
        return '<div class="scenario-card" style="opacity:0.5; text-align:center; border:1px dashed #444;">NO OBJECTIVES</div>';
    }

    // grupowanie po statusie dokładnie wg wartości z tabeli
    $grouped = [
        'active'    => [],
        'completed' => [],
        'failed'    => [],
        'paused'    => [],
    ];

    foreach ($quests as $quest) {
        if (!is_array($quest)) continue;
        $status = $quest['status'] ?? 'active';
        if (!isset($grouped[$status])) {
            $grouped[$status] = [];
        }
        $grouped[$status][] = $quest;
    }

    $output = '<div class="active-scenarios-container">';

    // helper do renderowania jednej karty
    $render_card = function($quest) {
        $scenario = $quest['cyber_scenarios'] ?? null;
        if (!$scenario || !is_array($scenario)) return '';

        $quest_type = $scenario['type']      ?? 'side';
        $quest_name = $scenario['name']      ?? 'Unknown objective';
        $quest_tags = $scenario['tags']      ?? '';
        $category   = $scenario['category']  ?? 'UNCATEGORIZED';
        $goal       = $scenario['goal']      ?? 'N/A';

        $area       = $scenario['cyber_areas'] ?? null;
        $area_name  = is_array($area) ? ($area['name'] ?? 'Unknown area') : 'Unknown area';

        $created_at = $quest['created_at'] ?? null;
        $time_ago   = $created_at ? tw_time_ago($created_at) : '';

        $cat_display   = strtoupper($category);
        $type_display  = strtoupper($quest_type);
        $name          = esc_html($quest_name);
        $goal_display  = esc_html($goal);
        $area_display  = esc_html($area_name);
        $time_display  = esc_html($time_ago);

        $tags_html = '';
        if (!empty($quest_tags)) {
            $tags_array = explode(',', $quest_tags);
            foreach ($tags_array as $tag) {
                $trimmed_tag = trim($tag);
                if ($trimmed_tag !== '') {
                    $tags_html .= "<span class='tw-tag'>" . esc_html($trimmed_tag) . "</span> ";
                }
            }
        }

        return "
        <div class='scenario-card'>
            <div class='quest-header'>// OBJECTIVE: {$cat_display} - {$type_display}</div>
            <div class='quest-name-line'>{$name}</div>
            <div class='quest-tags-line'>{$tags_html}</div>
            <div class='quest-what'>// WHAT: {$goal_display}</div>
            <div class='quest-where'>// WHERE: {$area_display}</div>
            <div class='quest-time'>// TIME: {$time_display}</div>
        </div>";
    };

    // Kolejność jak w tabeli: active, completed, failed, paused
    $status_labels = [
        'active'    => 'active',
        'completed' => 'completed',
        'failed'    => 'failed',
        'paused'    => 'paused',
    ];

    foreach ($status_labels as $status_key => $label) {
        if (!empty($grouped[$status_key])) {
            $status_title = strtoupper($label);
            $output .= "<div class='quest-status-header'>{$status_title}:</div>";
            foreach ($grouped[$status_key] as $q) {
                $output .= $render_card($q);
            }
        }
    }

    $output .= '</div>';

    return $output;
}
add_shortcode('active_scenarios', 'tw_display_active_scenarios_shortcode');