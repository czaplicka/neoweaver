function get_cyber_world_news_ajax() {
    $world_id = sanitize_text_field($_POST['world_id']);
    $character_id = sanitize_text_field($_POST['character_id']); // ID aktywnej postaci
    $current_day = intval($_POST['current_day']);
    $current_hour = intval($_POST['current_hour']);

    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // Filtry: ten świat, aktywne, oraz czas (tylko to, co już się wydarzyło w grze)
    // lte = less than or equal (mniejsze lub równe obecnemu dniowi)
    $url = $supa_url . "/rest/v1/cyber_world_news?world_id=eq.$world_id&game_day=lte.$current_day&is_active=eq.true&order=game_day.desc,game_hour.desc";

    $response = wp_remote_get($url, [
        'headers' => [
            'apikey' => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key
        ]
    ]);

    $news = json_decode(wp_remote_retrieve_body($response), true);

    // Sprawdzamy, czy są nieprzeczytane
    $unread_count = 0;
    foreach ($news as &$item) {
        $read_by = is_array($item['read_by']) ? $item['read_by'] : json_decode($item['read_by'], true);
        $item['is_new'] = !in_array($character_id, (array)$read_by);
        if ($item['is_new']) $unread_count++;
    }

    wp_send_json([
        'news' => $news,
        'unread_count' => $unread_count
    ]);
}
