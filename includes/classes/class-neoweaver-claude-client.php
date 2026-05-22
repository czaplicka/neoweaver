<?php
/**
 * NeoWeaver_Claude_Client
 *
 * Low-level HTTP wrapper for the Anthropic Messages API.
 * Called by NW_Chat_Claude (and optionally by the intent router).
 *
 * Required constant in wp-config.php:
 *   define( 'NW_CLAUDE_API_KEY', 'sk-ant-...' );
 *
 * @package NeoWeaver
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NeoWeaver_Claude_Client {

	private const API_URL = 'https://api.anthropic.com/v1/messages';
	private const VERSION = '2023-06-01';

	/**
	 * Call the Anthropic Messages API.
	 *
	 * @param string $system_prompt  The system/instructions block.
	 * @param array  $messages       Conversation array: [['role'=>'user'|'assistant','content'=>'...'], ...]
	 *                               Must start with role=user. Claude requires alternating turns.
	 * @param string $model          Model ID, e.g. 'claude-sonnet-4-5-20251001'.
	 * @param int    $max_tokens     Max output tokens.
	 * @param float  $temperature    0.0–1.0.
	 *
	 * @return array|WP_Error  On success: ['content' => string, 'usage' => ['input_tokens' => int, 'output_tokens' => int]]
	 *                         On error:  WP_Error
	 */
	public static function call(
		string $system_prompt,
		array  $messages,
		string $model       = 'claude-sonnet-4-5-20251001',
		int    $max_tokens  = 1024,
		float  $temperature = 0.8
	): array|WP_Error {

		if ( ! defined( 'NW_CLAUDE_API_KEY' ) || ! NW_CLAUDE_API_KEY ) {
			return new WP_Error( 'nw_claude_no_key', 'NW_CLAUDE_API_KEY is not defined.' );
		}

		// Claude requires the first message to have role=user.
		// Safety: strip any leading assistant turns.
		while ( ! empty( $messages ) && ( $messages[0]['role'] ?? '' ) !== 'user' ) {
			array_shift( $messages );
		}

		if ( empty( $messages ) ) {
			return new WP_Error( 'nw_claude_no_messages', 'Message array is empty after validation.' );
		}

		$body = [
			'model'       => $model,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
			'system'      => $system_prompt,
			'messages'    => $messages,
		];

		$response = wp_remote_post( self::API_URL, [
			'headers' => [
				'x-api-key'         => NW_CLAUDE_API_KEY,
				'anthropic-version' => self::VERSION,
				'content-type'      => 'application/json',
			],
			'body'    => wp_json_encode( $body ),
			'timeout' => 45,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( '[NeoWeaver Claude] HTTP error: ' . $response->get_error_message() );
			return $response;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 ) {
			$msg = $data['error']['message'] ?? "HTTP {$http_code}";
			error_log( "[NeoWeaver Claude] API error {$http_code}: {$msg}" );
			return new WP_Error( 'nw_claude_api_error', $msg );
		}

		// Extract text content from response
		$content = '';
		foreach ( $data['content'] ?? [] as $block ) {
			if ( ( $block['type'] ?? '' ) === 'text' ) {
				$content .= $block['text'];
			}
		}

		// Claude usage keys: input_tokens, output_tokens
		$usage = [
			'input_tokens'  => $data['usage']['input_tokens']  ?? 0,
			'output_tokens' => $data['usage']['output_tokens'] ?? 0,
		];

		return compact( 'content', 'usage' );
	}
}
