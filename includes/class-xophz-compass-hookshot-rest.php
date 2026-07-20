<?php

class Xophz_Compass_Hookshot_REST {

	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( 'xophz/v1', '/hookshot/incoming/(?P<secret>[a-zA-Z0-9-_]+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_incoming_webhook' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( 'xophz/v1', '/hookshot/verify/(?P<secret>[a-zA-Z0-9-_]+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_verification_challenge' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_incoming_webhook( WP_REST_Request $request ) {
		$content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
		
		$isTooLarge = $content_length > 1048576;
		if ( $isTooLarge ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Payload Too Large. Max size is 1MB.' ], 413 );
		}

		$depth = isset( $_SERVER['HTTP_X_HOOKSHOT_DEPTH'] ) ? (int) $_SERVER['HTTP_X_HOOKSHOT_DEPTH'] : 0;
		
		$isLooping = $depth > 3;
		if ( $isLooping ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Loop detected. Max depth exceeded.' ], 408 );
		}

		$secret = $request->get_param( 'secret' );

		$webhook_id = $this->resolve_webhook( $secret );

		$isInvalid = is_wp_error( $webhook_id );
		if ( $isInvalid ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => 'Invalid or inactive webhook secret.' ], 401 );
		}

		$ip_check = $this->check_ip_whitelist( $webhook_id, $request );
		$isBlocked = is_wp_error( $ip_check );
		if ( $isBlocked ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $ip_check->get_error_message() ], 403 );
		}

		$rate_check = $this->check_rate_limit( $webhook_id );
		$isRateLimited = is_wp_error( $rate_check );
		if ( $isRateLimited ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $rate_check->get_error_message() ], 429 );
		}

		$sig_check = Hookshot_Signature::verify( $request, $webhook_id );
		$isInvalidSig = is_wp_error( $sig_check );
		if ( $isInvalidSig ) {
			Hookshot_Health::record( $webhook_id, false );
			return new WP_REST_Response( [
				'success' => false,
				'message' => $sig_check->get_error_message(),
			], $sig_check->get_error_data()['status'] ?? 401 );
		}

		$payload = $request->get_json_params() ?: $request->get_body_params();
		$headers = $request->get_headers();

		$hasNoPayload = empty( $payload );
		if ( $hasNoPayload ) {
			$raw = $request->get_body();
			$hasRawBody = ! empty( $raw );
			if ( $hasRawBody ) {
				$payload = [ 'raw_body' => $raw ];
			}
		}

		$payload = Hookshot_Transform::apply_incoming( $payload, $webhook_id );

		$this->log_webhook( $webhook_id, 'incoming', $payload, $headers );

		Hookshot_Health::record( $webhook_id, true );

		do_action( 'xophz_hookshot_incoming', $payload, $webhook_id );

		return new WP_REST_Response( [ 'success' => true, 'message' => 'Webhook received.' ], 200 );
	}

	public function handle_verification_challenge( WP_REST_Request $request ) {
		$secret = $request->get_param( 'secret' );

		$webhook_id = $this->resolve_webhook( $secret );

		$isInvalid = is_wp_error( $webhook_id );
		if ( $isInvalid ) {
			return new WP_REST_Response( [ 'success' => false ], 401 );
		}

		$challenge = $request->get_param( 'challenge' ) ?: $request->get_header( 'x_hookshot_challenge' );

		return new WP_REST_Response( [
			'success'   => true,
			'challenge' => $challenge,
		] );
	}

	private function resolve_webhook( $secret ) {
		$query = new WP_Query( [
			'post_type'      => 'compass_webhook',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => 'hookshot_secret', 'value' => $secret ],
				[ 'key' => 'hookshot_type', 'value' => 'incoming' ],
				[ 'key' => 'hookshot_status', 'value' => 'active' ],
			],
		] );

		$hasNoMatch = empty( $query->posts );
		if ( $hasNoMatch ) {
			return new WP_Error( 'invalid_secret', 'No active webhook matches this secret.' );
		}

		return $query->posts[0];
	}

	private function check_ip_whitelist( $webhook_id, WP_REST_Request $request ) {
		$allowed_ips = get_post_meta( $webhook_id, 'hookshot_allowed_ips', true );

		$hasNoWhitelist = empty( $allowed_ips );
		if ( $hasNoWhitelist ) {
			return true;
		}

		$ip_list = is_array( $allowed_ips ) ? $allowed_ips : array_filter( array_map( 'trim', explode( ',', $allowed_ips ) ) );

		$hasNoRestrictions = empty( $ip_list );
		if ( $hasNoRestrictions ) {
			return true;
		}

		$client_ip = $request->get_header( 'x_forwarded_for' ) ?: $_SERVER['REMOTE_ADDR'] ?? '';
		$client_ip = trim( explode( ',', $client_ip )[0] );

		$isAllowed = in_array( $client_ip, $ip_list, true );
		if ( ! $isAllowed ) {
			return new WP_Error( 'ip_blocked', 'Request origin not in IP whitelist.' );
		}

		return true;
	}

	private function check_rate_limit( $webhook_id ) {
		$limit = (int) get_post_meta( $webhook_id, 'hookshot_rate_limit', true );

		$hasNoLimit = $limit <= 0;
		if ( $hasNoLimit ) {
			return true;
		}

		$transient_key = 'hookshot_rl_' . $webhook_id;
		$current_count = (int) get_transient( $transient_key );

		$isOverLimit = $current_count >= $limit;
		if ( $isOverLimit ) {
			return new WP_Error( 'rate_limited', 'Rate limit exceeded. Try again later.' );
		}

		set_transient( $transient_key, $current_count + 1, 60 );

		return true;
	}

	private function log_webhook( $webhook_id, $direction, $payload, $headers, $status_code = 200 ) {
		$log_id = wp_insert_post( [
			'post_title'  => sprintf( '%s Webhook (%d)', ucfirst( $direction ), $webhook_id ),
			'post_type'   => 'compass_wh_log',
			'post_status' => 'publish',
		] );

		$isValid = ! is_wp_error( $log_id );
		if ( $isValid ) {
			update_post_meta( $log_id, 'wh_log_parent_id', $webhook_id );
			update_post_meta( $log_id, 'wh_log_direction', $direction );
			update_post_meta( $log_id, 'wh_log_payload', wp_json_encode( $payload ) );
			update_post_meta( $log_id, 'wh_log_headers', wp_json_encode( $headers ) );
			update_post_meta( $log_id, 'wh_log_status', $status_code );
		}

		return $isValid ? $log_id : null;
	}
}

