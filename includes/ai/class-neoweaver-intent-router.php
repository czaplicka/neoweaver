<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-claude-client.php';

// ── Fallback constant (in case wp-config.php is missing it) ───────────────
if ( ! defined( 'NEOWEAVER_MODEL_ROUTER' ) ) {
    define( 'NEOWEAVER_MODEL_ROUTER', 'claude-haiku-4-5-20251001' );
}

class NeoWeaver_Intent_Router {

    /**
     * Classifies the player message into a gameplay protocol.
     * First tries regex rules (cheap), then falls back to Claude (more expensive).
     */
    public static function classify(string $message): string {
        $msg = mb_strtolower(trim($message));

        // Regex rules (order matters)
        $rules = [
            'META'   => '/^(status|hp|mp|stats|ekwipunek|inventory|karty|hand|mapa|map)\b/i',
            'COMBAT' => '/\b(atakuję|atak|attack|use card|zagraj kartę|walcz|fight|strike|shoot|cast)\b/i',
            'TRAVEL' => '/\b(idę|idę do|move|go to|north|south|east|west|północ|południe|wschód|zachód|travel|wejdź|enter|wyjśdź)\b/i',
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

        return self::classify_with_gpt($message);
    }

    /**
     * Fallback intent classification via Claude.
     * Returns exactly one allowed protocol label.
     */
    private static function classify_with_gpt(string $message): string {
        $result = NeoWeaver_Claude_Client::call(
            'Classify the player message into ONE word: TRAVEL, COMBAT, TRADE, DIALOG, LORE, REST, DECK, META. Reply with ONLY that word.',
            [
                [ 'role' => 'user', 'content' => $message ],
            ],
            NEOWEAVER_MODEL_ROUTER,
            10,
            0.0
        );

        if (is_wp_error($result)) {
            return 'DIALOG';
        }

        $intent = strtoupper(trim($result['content'] ?? 'DIALOG'));
        $valid  = [ 'TRAVEL', 'COMBAT', 'TRADE', 'DIALOG', 'LORE', 'REST', 'DECK', 'META' ];

        return in_array($intent, $valid, true) ? $intent : 'DIALOG';
    }
}
