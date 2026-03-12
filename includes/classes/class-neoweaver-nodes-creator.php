<?php
/**
 * Neoweaver_Nodes_Creator
 *
 * PHP-side API for Node (World) creation.
 *
 * Responsibilities:
 *   - validate()            — gate-check required fields and auth state
 *   - build_payload()       — assemble the FormData-equivalent array
 *   - create_via_endpoint() — wp_remote_post() to the theme endpoint
 *   - create()              — orchestrator; fires neoweaver_node_created on success
 *
 * The front-end JS continues to submit directly to tw-endpoint-world.php;
 * this class provides a PHP-side API for server-driven creation and future tests.
 *
 * No namespaces. No Supabase direct calls — the endpoint owns that contract.
 *
 * @package Neoweaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Neoweaver_Nodes_Creator {

	/**
	 * Required fields that must be present and non-empty in $data.
	 */
	private const REQUIRED_FIELDS = [
		'name',
		'description',
		'size',
		'wealth',
		'difficulty',
		'magic',
		'gods',
		'technology',
		'relations',
		'moral',
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Validate incoming form data before attempting Node creation.
	 *
	 * Checks:
	 *   1. A WordPress user is logged in.
	 *   2. All required fields are present and non-empty.
	 *
	 * @param  array $data  Raw form fields (mirrors what the JS FormData posts).
	 * @return true|WP_Error
	 */
	public function validate( array $data ): true|WP_Error {
		// Auth check.
		if ( ! get_current_user_id() ) {
			return new WP_Error(
				'neoweaver_not_logged_in',
				'Operator authentication required. No valid session found.'
			);
		}

		// Required field check.
		foreach ( self::REQUIRED_FIELDS as $field ) {
			if ( empty( $data[ $field ] ) && $data[ $field ] !== '0' ) {
				return new WP_Error(
					'neoweaver_missing_field',
					sprintf( 'Required field missing or empty: %s', $field )
				);
			}
		}

		return true;
	}

	/**
	 * Build the payload array expected by tw-endpoint-world.php.
	 *
	 * Field names are kept exactly as the current form posts them.
	 * wp_user_id is injected from the current session.
	 *
	 * @param  array $data  Validated form fields.
	 * @return array
	 */
	public function build_payload( array $data ): array {
		return [
			'name'        => sanitize_text_field( $data['name'] ),
			'description' => sanitize_textarea_field( $data['description'] ),
			'size'        => intval( $data['size'] ),
			'wealth'      => intval( $data['wealth'] ),
			'difficulty'  => intval( $data['difficulty'] ),
			'magic'       => intval( $data['magic'] ),
			'gods'        => intval( $data['gods'] ),
			'technology'  => intval( $data['technology'] ),
			'relations'   => intval( $data['relations'] ),
			'moral'       => intval( $data['moral'] ),
			'customize'   => sanitize_textarea_field( $data['customize'] ?? '' ),
			'wp_user_id'  => get_current_user_id(),
			'nonce'       => wp_create_nonce( 'tw_world_nonce' ),
		];
	}

	/**
	 * POST the payload to the theme's tw-endpoint-world.php.
	 *
	 * Uses wp_remote_post() so it respects WordPress proxy / SSL settings.
	 * The endpoint URL matches what the front-end JS uses — no contract change.
	 *
	 * @param  array $payload  Output of build_payload().
	 * @return array  ['success' => bool, 'data' => mixed]
	 */

public function create_via_rest_api( array $payload ): array {
    $endpoint_url = rest_url('neoweaver/v1/world/create');
    
    $response = wp_remote_post($endpoint_url, [
        'body' => $payload,
        'timeout' => 60,
    ]);

		if ( is_wp_error( $response ) ) {
			error_log( 'TW Nodes Creator – wp_remote_post error: ' . $response->get_error_message() );
			return [
				'success' => false,
				'data'    => [ 'message' => $response->get_error_message() ],
			];
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		if ( $decoded === null ) {
			error_log( 'TW Nodes Creator – JSON decode failed. Raw body: ' . $body );
			return [
				'success' => false,
				'data'    => [ 'message' => 'Endpoint returned non-JSON response.' ],
			];
		}

		// Endpoint contract: { success: true|false, data: { ... } }
		return [
			'success' => ! empty( $decoded['success'] ),
			'data'    => $decoded['data'] ?? $decoded,
		];
	}

	/**
	 * Orchestrate Node creation: validate → build payload → call endpoint.
	 *
	 * Fires neoweaver_node_created with the response data on success.
	 *
	 * @param  array $data  Raw form fields.
	 * @return array  ['success' => bool, 'data' => mixed] or ['success' => false, 'error' => WP_Error]
	 */
	public function create( array $data ): array {
		$validation = $this->validate( $data );

		if ( is_wp_error( $validation ) ) {
			return [
				'success' => false,
				'error'   => $validation,
				'data'    => [ 'message' => $validation->get_error_message() ],
			];
		}

		$payload  = $this->build_payload( $data );
		$result   = $this->create_via_rest_api( $payload );

		if ( $result['success'] ) {
			/**
			 * Fired after a Node is successfully created via PHP.
			 *
			 * @param mixed $data  The decoded response data from the endpoint.
			 */
			do_action( 'neoweaver_node_created', $result['data'] );
		}

		return $result;
	}
}
