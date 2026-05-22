<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Claude_Client {

    /**
     * Calls the Anthropic Messages API.
     *
     * @param string $system   System prompt (top-level, not in messages array)
     * @param array  $messages [ ['role'=>'user'|'assistant', 'content'=>string] ]
     * @param string $model    e.g. 'claude-haiku-4-5-20251001'
     * @param int    $max_tokens
     * @param float  $temperature
     * @return array|WP_Error  ['content'=>string, 'usage'=>['input_tokens'=>int, 'output_tokens'=>int]]
     */
    public static function call(
        string $system,
        array  $messages,
        string $model,
        int    $max_tokens = 1024,
        float  $temperature = 0.8
    ) {
        if ( ! defined( 'NW_CLAUDE_API_KEY' ) || empty( NW_CLAUDE_API_KEY ) ) {
            return new WP_Error( 'nw_no_claude_key', 'Missing NW_CLAUDE_API_KEY in wp-config.php' );
        }

        $body = [
            'model'      => $model,
            'max_tokens' => $max_tokens,
            'system'     => $system,
            'messages'   => $messages,
        ];
        // Claude temperature range is 0–1, same as OpenAI
        if ( $temperature !== 1.0 ) {
            $body['temperature'] = $temperature;
        }

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 45,
            'headers' => [
                'x-api-key'         => NW_CLAUDE_API_KEY,
                'anthropic-version' => '2023-06-01',
                'Content-Type'      => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[NeoWeaver Claude] Network error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $data['error']['message'] ?? "HTTP {$code}";
            error_log( '[NeoWeaver Claude] API error: ' . $err );
            return new WP_Error( 'nw_claude_http_' . $code, $err );
        }

        return [
            'content' => $data['content'][0]['text'] ?? '',
            'usage'   => [
                'input_tokens'  => $data['usage']['input_tokens']  ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
                // Backwards compat aliases for token ledger
                'prompt_tokens'     => $data['usage']['input_tokens']  ?? 0,
                'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
            ],
        ];
    }
}
