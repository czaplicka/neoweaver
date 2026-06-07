<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/class-neoweaver-claude-client.php';

// ── Fallback constant (in case wp-config.php is missing it) ─────────────
if ( ! defined( 'NEOWEAVER_MODEL_ROUTER' ) ) {
    define( 'NEOWEAVER_MODEL_ROUTER', 'claude-haiku-4-5-20251001' );
}

class NeoWeaver_Intent_Router {

    /**
     * Classifies the player message into a gameplay protocol.
     * First tries regex rules (cheap), then falls back to Claude (more expensive).
     *
     * Game language: English only.
     */
    public static function classify(string $message): string {
        $msg = mb_strtolower(trim($message));

        // Regex rules (order matters — more specific patterns first)
        $rules = [
            'META'   => '/^(status|hp|mp|stats|inventory|hand|map)\b/i',
            'COMBAT' => '/\b(attack|use card|fight|strike|shoot|cast)\b/i',
            'TRAVEL' => '/\b(move|go to|north|south|east|west|travel|enter|exit|leave)\b/i',
            'TRADE'  => '/\b(buy|sell|trade|price|shop|cost|how much)\b/i',
            'REST'   => '/\b(rest|sleep|camp|wait)\b/i',
            'DECK'   => '/\b(deck|draw|shuffle|hand|cards)\b/i',
            'LORE'   => '/\b(lore|history|gossip|who is|what is|tell me about)\b/i',
            'DIALOG' => '/\b(say|ask|talk|speak|greet)\b/i',
        ];

        foreach ($rules as $protocol => $pattern) {
            if (preg_match($pattern, $msg)) {
                return $protocol;
            }
        }

        return self::classify_with_claude($message);
    }

    /**
     * Fallback intent classification via Claude.
     * Returns exactly one allowed protocol label.
     */
    private static function classify_with_claude(string $message): string {
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
