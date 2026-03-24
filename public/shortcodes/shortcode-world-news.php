function get_cyber_world_news_ajax() {
    $world_id = sanitize_text_field($_POST['world_id']);
    $character_id = sanitize_text_field($_POST['character_id']); 
    $current_day = intval($_POST['current_day']);
    $current_hour = intval($_POST['current_hour']);
    $clearance = isset($_POST['clearance']) ? intval($_POST['clearance']) : 0;

    $supa_url = SUPABASE_URL;
    $supa_key = SUPABASE_KEY;

    // Poprawiony URL z logiką godziny i clearance
    $url = $supa_url . "/rest/v1/cyber_world_news?world_id=eq.$world_id&is_active=eq.true&clearance_level=lte.$clearance";
    $url .= "&or=(game_day.lt.$current_day,and(game_day.eq.$current_day,game_hour.lte.$current_hour))";
    $url .= "&order=game_day.desc,game_hour.desc";

    $response = wp_remote_get($url, [
        'headers' => [
            'apikey' => $supa_key,
            'Authorization' => 'Bearer ' . $supa_key
        ]
    ]);

    $body = wp_remote_retrieve_body($response);
    $news = json_decode($body, true);

    if (!is_array($news)) {
        wp_send_json(['news' => [], 'unread_count' => 0]);
        return;
    }

    $unread_count = 0;
    foreach ($news as &$item) {
        $read_by = $item['read_by'];
        if (is_string($read_by)) $read_by = json_decode($read_by, true);
        $read_by = is_array($read_by) ? $read_by : [];

        $item['is_new'] = !in_array($character_id, $read_by);
        if ($item['is_new']) $unread_count++;
    }

    wp_send_json([
        'news' => $news,
        'unread_count' => $unread_count
    ]);
}
