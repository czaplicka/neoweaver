<?php
/**
 * NeoWeaver Memory Parser
 *
 * Wyciąga blok ---SYSTEM--- z odpowiedzi GM,
 * parsuje tagi #MEMORY i zapisuje do cyber_memory w Supabase.
 *
 * Format tagu:
 *   #MEMORY:topic:content
 *   #MEMORY:topic:subject:content
 *
 * Przykłady:
 *   #MEMORY:character:fear:pająki intensywna fobia
 *   #MEMORY:npc:barman_aldric:jest koruptem, współpracuje z Czerwoną Łapą
 *   #MEMORY:location:tawerna_trzy_pióra:bezpieczna kryjówka, gracz był w sesji 3
 *   #MEMORY:faction:czerwona_lapa:kontroluje port, szuka gracza
 *   #MEMORY:campaign:gracz odkrył mapę do fortecy
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NW_Memory_Parser {

    // Supabase REST endpoint (ustaw w wp-config.php)
    private string $supabase_url;
    private string $service_key;

    public function __construct() {
        $this->supabase_url = defined('NW_SUPABASE_URL') ? NW_SUPABASE_URL : '';
        $this->service_key  = defined('NW_SUPABASE_SERVICE_KEY') ? NW_SUPABASE_SERVICE_KEY : '';
    }

    // =========================================================
    // GŁÓWNA METODA — wywołaj po otrzymaniu odpowiedzi od GPT
    // =========================================================

    /**
     * @param string $raw_gm_text  Surowa odpowiedź z GPT (może zawierać blok ---SYSTEM---)
     * @param string $char_id      UUID postaci gracza
     * @param string $world_id     UUID świata
     * @param string $session_id   UUID sesji
     *
     * @return array {
     *   'clean_text' => string,  // tekst bez bloku SYSTEM — do wyświetlenia graczowi
     *   'tags'       => array,   // wyciągnięte tagi (do dalszej obsługi np. HUD)
     *   'memories'   => array,   // zapisane fakty do cyber_memory
     * }
     */
    public function parse( string $raw_gm_text, string $char_id, string $world_id, string $session_id ): array {

        // 1. Wytnij blok SYSTEM z narracji
        [ $clean_text, $system_block ] = $this->extract_system_block( $raw_gm_text );

        // 2. Parsuj tagi z bloku SYSTEM
        $memory_entries = [];
        $other_tags     = [];

        if ( ! empty( $system_block ) ) {
            foreach ( $this->parse_tags( $system_block ) as $tag ) {

                if ( $tag['type'] === 'MEMORY' ) {
                    $memory_entries[] = $this->build_memory_entry(
                        $tag, $char_id, $world_id, $session_id
                    );
                } else {
                    // Inne tagi (np. #STATUS:, #ENTROPY: itp.) — zwróć do dalszej obsługi
                    $other_tags[] = $tag;
                }
            }
        }

        // 3. Zapisz memory do Supabase (batch insert)
        $saved = [];
        if ( ! empty( $memory_entries ) ) {
            $saved = $this->save_memories( $memory_entries );
        }

        return [
            'clean_text' => trim( $clean_text ),
            'tags'       => $other_tags,
            'memories'   => $saved,
        ];
    }

    // =========================================================
    // WYCIĄGANIE BLOKU SYSTEM
    // =========================================================

    /**
     * Szuka bloku ---SYSTEM---...---END--- i wycina go z tekstu.
     * Zwraca [ $tekst_bez_bloku, $zawartość_bloku ].
     */
    private function extract_system_block( string $text ): array {

        $pattern = '/\n?---SYSTEM---\n(.*?)\n---END---\n?/s';

        if ( ! preg_match( $pattern, $text, $matches ) ) {
            return [ $text, '' ];
        }

        $system_block = trim( $matches[1] );
        $clean_text   = preg_replace( $pattern, '', $text );

        return [ $clean_text, $system_block ];
    }

    // =========================================================
    // PARSOWANIE TAGÓW
    // =========================================================

    /**
     * Parsuje linie z bloku SYSTEM.
     *
     * Obsługiwane formaty:
     *   #MEMORY:topic:content
     *   #MEMORY:topic:subject:content
     *   #STATUS:poisoned
     *   #ENTROPY_UP:5
     *   #LOC:42
     */
    private function parse_tags( string $system_block ): array {

        $tags = [];
        $lines = explode( "\n", $system_block );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) || $line[0] !== '#' ) continue;

            // Usuń #
            $raw = ltrim( $line, '#' );

            // Podziel po pierwszym ':'
            $parts = explode( ':', $raw, 2 );
            $type  = strtoupper( trim( $parts[0] ) );
            $rest  = isset( $parts[1] ) ? trim( $parts[1] ) : '';

            if ( $type === 'MEMORY' ) {
                // MEMORY:topic:content  LUB  MEMORY:topic:subject:content
                $mem_parts = explode( ':', $rest, 3 );

                $topic   = strtolower( trim( $mem_parts[0] ?? '' ) );
                $second  = trim( $mem_parts[1] ?? '' );
                $third   = trim( $mem_parts[2] ?? '' );

                // Jeśli jest trzecia część — subject:content
                // Jeśli nie — content jest w $second
                if ( $third !== '' ) {
                    $subject = $second;
                    $content = $third;
                } else {
                    $subject = null;
                    $content = $second;
                }

                $tags[] = [
                    'type'    => 'MEMORY',
                    'topic'   => $topic,
                    'subject' => $subject,
                    'content' => $content,
                ];

            } else {
                // Inne tagi — zwróć surowo
                $tags[] = [
                    'type'  => $type,
                    'value' => $rest,
                    'raw'   => $line,
                ];
            }
        }

        return $tags;
    }

    // =========================================================
    // BUDOWANIE WPISU DO cyber_memory
    // =========================================================

    private function build_memory_entry( array $tag, string $char_id, string $world_id, string $session_id ): array {

        $topic = $tag['topic'];

        // Wpisy 'character' przypisane do gracza; reszta do świata
        $is_character_memory = ( $topic === 'character' );

        // Importance na podstawie topicu
        $importance_map = [
            'character' => 4,
            'campaign'  => 3,
            'npc'       => 3,
            'location'  => 2,
            'faction'   => 3,
            'item'      => 2,
            'summary'   => 5,
        ];

        return [
            'world_id'   => $world_id,
            'char_id'    => $is_character_memory ? $char_id : null,
            'session_id' => $session_id ?: null,
            'topic'      => $topic,
            'subject'    => $tag['subject'] ?? null,
            'content'    => $tag['content'],
            'importance' => $importance_map[ $topic ] ?? 3,
            'source'     => 'gm_tag',
        ];
    }

    // =========================================================
    // ZAPIS DO SUPABASE
    // =========================================================

    /**
     * Batch INSERT do cyber_memory.
     * Duplikaty (ten sam topic+subject+content) są ignorowane.
     */
    private function save_memories( array $entries ): array {

        if ( empty( $this->supabase_url ) || empty( $this->service_key ) ) {
            error_log( '[NW_Memory_Parser] Brak konfiguracji Supabase.' );
            return [];
        }

        $url = rtrim( $this->supabase_url, '/' ) . '/rest/v1/cyber_memory';

        $response = wp_remote_post( $url, [
            'headers' => [
                'apikey'        => $this->service_key,
                'Authorization' => 'Bearer ' . $this->service_key,
                'Content-Type'  => 'application/json',
                'Prefer'        => 'resolution=ignore-duplicates,return=representation',
            ],
            'body'    => wp_json_encode( $entries ),
            'timeout' => 10,
        ]);

        if ( is_wp_error( $response ) ) {
            error_log( '[NW_Memory_Parser] Save error: ' . $response->get_error_message() );
            return [];
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 ) {
            error_log( '[NW_Memory_Parser] Supabase returned ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
            return [];
        }

        return json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];
    }

    // =========================================================
    // POBIERANIE MEMORY DO PROMPTU
    // =========================================================

    /**
     * Buduje blok MEMORY do wklejenia w prompt systemowy GM-a.
     *
     * @param string $char_id
     * @param string $world_id
     * @param int    $char_limit   Ile wpisów character (domyślnie 5)
     * @param int    $world_limit  Ile wpisów world/campaign (domyślnie 8)
     */
    public function build_prompt_block( string $char_id, string $world_id, int $char_limit = 5, int $world_limit = 8 ): string {

        $url_base = rtrim( $this->supabase_url, '/' ) . '/rest/v1/cyber_memory';
        $headers  = [
            'apikey'        => $this->service_key,
            'Authorization' => 'Bearer ' . $this->service_key,
            'Content-Type'  => 'application/json',
        ];

        // Pamięć gracza
        $char_resp = wp_remote_get( add_query_arg([
            'char_id'  => 'eq.' . $char_id,
            'topic'    => 'eq.character',
            'order'    => 'importance.desc,updated_at.desc',
            'limit'    => $char_limit,
            'select'   => 'topic,subject,content',
        ], $url_base ), [ 'headers' => $headers, 'timeout' => 5 ] );

        // Pamięć świata (tylko odkryta przez tego gracza)
        $world_resp = wp_remote_get( add_query_arg([
            'world_id' => 'eq.' . $world_id,
            'char_id'  => 'is.null',
            'order'    => 'importance.desc,updated_at.desc',
            'limit'    => $world_limit,
            'select'   => 'topic,subject,content',
        ], $url_base ), [ 'headers' => $headers, 'timeout' => 5 ] );

        $lines = [];

        foreach ( [ $char_resp, $world_resp ] as $resp ) {
            if ( is_wp_error( $resp ) ) continue;
            $items = json_decode( wp_remote_retrieve_body( $resp ), true ) ?? [];
            foreach ( $items as $m ) {
                $subj   = ! empty( $m['subject'] ) ? $m['subject'] . ': ' : '';
                $lines[] = "[{$m['topic']}] {$subj}{$m['content']}";
            }
        }

        if ( empty( $lines ) ) return '';

        return "MEMORY:\n" . implode( "\n", $lines );
    }
}
