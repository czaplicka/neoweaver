<?php
if (!defined('ABSPATH')) exit;

class NeoWeaver_Intent_Router {

    /**
     * Klasyfikuje wiadomość gracza na protokół.
     * Najpierw próbuje regex (tanie), fallback do GPT mini (drogie).
     */
    public static function classify(string $message): string {
        $msg = mb_strtolower(trim($message));

        // --- REGEX rules (kolejność ma znaczenie) ---
        $rules = [
            'META'   => '/^(status|hp|mp|stats|ekwipunek|inventory|karty|hand|mapa|map)\b/i',
            'COMBAT' => '/\b(atakuję|atak|attack|use card|zagraj kartę|walcz|fight|strike|shoot|cast)\b/i',
            'TRAVEL' => '/\b(idę|idę do|move|go to|north|south|east|west|północ|południe|wschód|zachód|travel|wejdź|enter|wyjdź)\b/i',
            'TRADE'  => '/\b(kup|kupuję|sprzedaj|sprzedaję|buy|sell|trade|handel|ile kosztuje|price|cena|shop|sklep)\b/i',
            'REST'   => '/\b(odpoczywa|śpię|rest|sleep|camp|nocleg|odpoczynek|czekam|wait)\b/i',
            'DECK'   => '/\b(dobierz|dobierz kartę|talia|deck|draw|karty|hand|shuffle|przetasuj)\b/i',
            'LORE'   => '/\b(co wiem|opowiedz|lore|historia|gossip|plotki|kim jest|what is|tell me about)\b/i',
            'DIALOG' => '/\b(mówię|pytam|zagaduję|powiedz|rozmawiam|say|ask|talk|speak|greet)\b/i',
        ];

        foreach ($rules as $protocol => $pattern) {
            if (preg_match($pattern, $msg)) {
                return $protocol;
            }
        }

        // Fallback: krótkie zapytanie do GPT-4o-mini (tanio)
        return self::classify_with_gpt($message);
    }

    private static function classify_with_gpt(string $message): string {
        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . NEOWEAVER_OPENAI_KEY,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'      => 'gpt-4o-mini',
                'max_tokens' => 10,
                'messages'   => [
                    ['role' => 'system', 'content' => 
                        'Classify the player message into ONE word: TRAVEL, COMBAT, TRADE, DIALOG, LORE, REST, DECK, META. Reply with ONLY that word.'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]),
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) return 'DIALOG';

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $intent = strtoupper(trim($body['choices'][0]['message']['content'] ?? 'DIALOG'));

        $valid = ['TRAVEL','COMBAT','TRADE','DIALOG','LORE','REST','DECK','META'];
        return in_array($intent, $valid) ? $intent : 'DIALOG';
    }
}
