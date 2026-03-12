add_shortcode('tw_essences', 'tw_essences_shortcode');
function tw_essences_shortcode() {
    if (!is_user_logged_in()) {
        return '<div class="tw-essence-error">You must be logged in to see essences.</div>';
    }

    $user_id = get_current_user_id();
    $supabase_base = trailingslashit(tw_supabase_url()) . 'rest/v1/';
    $anon_key = tw_supabase_anon_key();
    $args = [
        'headers' => [
            'apikey'        => $anon_key,
            'Authorization' => 'Bearer ' . $anon_key,
        ],
        'timeout' => 15,
    ];

    // Helper lokalny (INNA nazwa niż globalna)
    $tw_ess_get = function($url) use ($args) {
        $resp = wp_remote_get($url, $args);
        if (is_wp_error($resp)) return [];
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        return is_array($data) ? $data : [];
    };

    // Pobierz char_id
    $char_rows = $tw_ess_get($supabase_base . 'cyber_characters?wp_user_id=eq.' . $user_id . '&select=id&limit=1');
    $char_id = $char_rows[0]['id'] ?? null;
    if (!$char_id) {
        return '<div class="tw-essence-error">No character selected.</div>';
    }

    // Pobierz esencje
    $essences_rows = $tw_ess_get($supabase_base . 'cyber_character_essences?character_id=eq.' . (int)$char_id . '&select=essence_type,quantity');

    $essences = [];
    foreach ($essences_rows as $row) {
        if (!isset($row['essence_type'])) continue;
        $essences[$row['essence_type']] = (float) ($row['quantity'] ?? 0);
    }

    $config = [
        'might'  => ['label' => 'Might',  'icon' => '⚔️', 'color' => '#ff4500'],
        'primal' => ['label' => 'Primal', 'icon' => '🌿', 'color' => '#32cd32'],
        'magic'  => ['label' => 'Magic',  'icon' => '✨', 'color' => '#8a2be2'],
        'logic'  => ['label' => 'Logic',  'icon' => '💠', 'color' => '#00ced1'],
        'token'  => ['label' => 'Token',  'icon' => '🪙', 'color' => '#ffd700'],
        'venom'  => ['label' => 'Venom',  'icon' => '🧪', 'color' => '#9400d3'],
        'weaver' => ['label' => 'Weaver','icon' => '🧶', 'color' => '#adff00'],
    ];

    ob_start(); ?>
    <style>
    .tw-essence-container{display:flex;flex-wrap:wrap;gap:12px;background:rgba(0,0,0,0.3);padding:10px;border-radius:8px;border:1px solid #444;font-family:'Chakra Petch',sans-serif;}
    .tw-essence-item{display:flex;align-items:center;gap:6px;padding:4px 8px;background:rgba(255,255,255,0.05);border-radius:4px;font-size:14px;border-bottom:2px solid transparent;}
    .tw-essence-count{font-weight:bold;font-size:16px;}
    </style>
    <div class="tw-essence-container">
        <?php foreach ($config as $key => $data): 
            $value = isset($essences[$key]) ? $essences[$key] : 0;
        ?>
            <div class="tw-essence-item" style="border-color:<?=esc_attr($data['color'])?>;">
                <span title="<?=esc_attr($data['label'])?>"><?=esc_html($data['icon'])?></span>
                <span class="tw-essence-count" style="color=<?=esc_attr($data['color'])?>;">
                    <?=esc_html(number_format($value))?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}