<?php
if (!defined('ABSPATH')) exit;

class NeoWeaver_Context_Builder {

    private string $supabase_url;
    private string $supabase_key;

    public function __construct() {
        $this->supabase_url = NEOWEAVER_SUPABASE_URL;
        $this->supabase_key = NEOWEAVER_SUPABASE_KEY;
    }

    /**
     * Zwraca array z 3 blokami promptu systemowego.
     */
    public function build(string $char_id, string $protocol): array {
        $core    = $this->get_core_context($char_id);
        $extra   = $this->get_protocol_context($char_id, $protocol, $core);

        return [
            'block_a' => $this->build_block_a($core),
            'block_b' => $this->build_block_b($core),
            'block_c' => $this->build_block_c($protocol, $extra),
            'world_id' => $core['world']['id'] ?? null,
        ];
    }

    // ------------------------------------------------
    // CORE: dane zawsze potrzebne
    // ------------------------------------------------
    private function get_core_context(string $char_id): array {
        $char = $this->query(
            'cyber_characters',
            "id=eq.{$char_id}&select=id,name,currenthp,maxhp,mp,satiety,hydration,locationid,echo_tags,gold,worldid"
        )[0] ?? [];

        $location = [];
        $world    = [];

        if (!empty($char['locationid'])) {
            $location = $this->query(
                'cyber_worldmap',
                "id=eq.{$char['locationid']}&select=id,locationname,instancetags,aiprompt,threatlevel,nid,eid,sid,wid"
            )[0] ?? [];
        }

        if (!empty($char['worldid'])) {
            $world = $this->query(
                'cyber_worlds',
                "id=eq.{$char['worldid']}&select=id,worldname,entropy,globaltag1,globaltag2,globaltag3,difficulty,archetype"
            )[0] ?? [];
        }

        return compact('char', 'location', 'world');
    }

    // ------------------------------------------------
    // EXTRA: dane per protokół
    // ------------------------------------------------
    private function get_protocol_context(string $char_id, string $protocol, array $core): array {
        $loc_id = $core['location']['id'] ?? null;

        switch ($protocol) {
            case 'COMBAT':
                return [
                    'monsters' => $this->query(
                        'cyber_monsters',
                        "locationid=eq.{$loc_id}&select=name,hp,attack,defense,tags&limit=3"
                    ),
                    'hand' => $this->query(
                        'cyber_deck_state',
                        "char_id=eq.{$char_id}&zone=eq.hand&select=card_id,card_name,card_type,effect_tags"
                    ),
                ];

            case 'TRADE':
                return [
                    'npc_inventory' => $this->query(
                        'cyber_npc_inventory',
                        "locationid=eq.{$loc_id}&select=item_name,price,quantity&limit=10"
                    ),
                    'player_gold' => $core['char']['gold'] ?? 0,
                ];

            case 'TRAVEL':
                $exits = [];
                foreach (['nid','eid','sid','wid'] as $dir) {
                    if (!empty($core['location'][$dir])) {
                        $dest = $this->query(
                            'cyber_worldmap',
                            "id=eq.{$core['location'][$dir]}&select=id,locationname,threatlevel"
                        )[0] ?? null;
                        if ($dest) $exits[$dir] = $dest;
                    }
                }
                return ['exits' => $exits];

            case 'DIALOG':
                return [
                    'npcs' => $this->query(
                        'cyber_npcs',
                        "locationid=eq.{$loc_id}&select=name,role,ai_personality_prompt,relationship_tags&limit=3"
                    ),
                ];

            case 'LORE':
                return [
                    'world_tags' => $this->query(
                        'cyber_world_tags',
                        "worldid=eq.{$core['world']['id']}&select=tag_name,tag_value&limit=10"
                    ),
                ];

            default:
                return [];
        }
    }

    // ------------------------------------------------
    // BLOKI PROMPTU
    // ------------------------------------------------
    private function build_block_a(array $core): string {
        $archetype = $core['world']['archetype'] ?? 'EPIC';
        return <<<PROMPT
You are the AI Game Master of NeoWeave — a dark, narrative RPG.
Archetype: {$archetype}
Rules: Respond in character as the world. Keep answers under 120 words unless combat demands more.
Embed system tags in your response using syntax #TAG or #TAG:value (e.g. #ENTROPY_UP:5, #loc:42, #STATUS_POISONED).
Player does not see tags — they are parsed by the system. Never explain tags to the player.
Language: respond in the same language the player uses.
PROMPT;
    }

    private function build_block_b(array $core): string {
        $c = $core['char'];
        $l = $core['location'];
        $w = $core['world'];
        $tags = is_array($c['echo_tags'] ?? null) 
            ? implode(', ', $c['echo_tags']) 
            : ($c['echo_tags'] ?? 'none');

        return <<<PROMPT
WORLD: {$w['worldname']} | Entropy: {$w['entropy']}/100 | Difficulty: {$w['difficulty']}
WORLD_TAGS: {$w['globaltag1']}, {$w['globaltag2']}, {$w['globaltag3']}
AGENT: {$c['name']} | HP: {$c['currenthp']}/{$c['maxhp']} | MP: {$c['mp']} | Gold: {$c['gold']}
BIOMETRICS: Satiety {$c['satiety']}% | Hydration {$c['hydration']}%
ECHO: {$tags}
LOCATION: {$l['locationname']} | Threat: {$l['threatlevel']} | Tags: {$l['instancetags']}
GM_NOTE: {$l['aiprompt']}
PROMPT;
    }

    private function build_block_c(string $protocol, array $extra): string {
        $lines = ["PROTOCOL: {$protocol}"];

        switch ($protocol) {
            case 'TRAVEL':
                foreach ($extra['exits'] ?? [] as $dir => $dest) {
                    $dirName = ['nid'=>'NORTH','eid'=>'EAST','sid'=>'SOUTH','wid'=>'WEST'][$dir];
                    $lines[] = "EXIT_{$dirName}: {$dest['locationname']} (id:{$dest['id']}, threat:{$dest['threatlevel']})";
                }
                break;

            case 'COMBAT':
                foreach ($extra['monsters'] ?? [] as $m) {
                    $lines[] = "ENEMY: {$m['name']} HP:{$m['hp']} ATK:{$m['attack']} DEF:{$m['defense']}";
                }
                $hand = array_column($extra['hand'] ?? [], 'card_name');
                $lines[] = "PLAYER_HAND: " . implode(', ', $hand);
                break;

            case 'TRADE':
                $items = array_map(fn($i) => "{$i['item_name']}({$i['price']}g)", $extra['npc_inventory'] ?? []);
                $lines[] = "SHOP: " . implode(', ', $items);
                $lines[] = "PLAYER_GOLD: {$extra['player_gold']}";
                break;

            case 'DIALOG':
                foreach ($extra['npcs'] ?? [] as $npc) {
                    $lines[] = "NPC: {$npc['name']} | Role: {$npc['role']} | Personality: {$npc['ai_personality_prompt']}";
                }
                break;

            case 'LORE':
                foreach ($extra['world_tags'] ?? [] as $t) {
                    $lines[] = "LORE: {$t['tag_name']} = {$t['tag_value']}";
                }
                break;
        }

        return implode("\n", $lines);
    }

    // ------------------------------------------------
    // HELPER: zapytanie do Supabase REST
    // ------------------------------------------------
    private function query(string $table, string $params): array {
        $response = wp_remote_get("{$this->supabase_url}/rest/v1/{$table}?{$params}", [
            'headers' => [
                'apikey'        => $this->supabase_key,
                'Authorization' => 'Bearer ' . $this->supabase_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 8,
        ]);

        if (is_wp_error($response)) return [];
        return json_decode(wp_remote_retrieve_body($response), true) ?? [];
    }
}
