<?php

/**
 * Incoming webhook receiver.
 *
 * POST xophz/v1/hookshot/receive/{id} is the supported endpoint. {id} is the public webhook id,
 * and the request authenticates with an HMAC signature header (Hookshot_Signature) keyed by the
 * webhook's signing secret, so no secret travels in the URL.
 *
 * DEPRECATED: POST xophz/v1/hookshot/incoming/{secret} and /hookshot/verify/{secret} carry the
 * webhook secret in the URL path, where it lands in access logs and referrers. They remain only
 * because the GitHub release webhooks on the plugin repositories still post to that form. Their
 * responses carry a Deprecation header and a Link to the signed endpoint, each use is stamped in
 * the `hookshot_legacy_last_used` meta, and returning false from the
 * `xophz_hookshot_legacy_secret_urls` filter retires them (410).
 */
class Xophz_Compass_Hookshot_REST {

	const NAMESPACE = 'xophz/v1';

	const LEGACY_LAST_USED_META = 'hookshot_legacy_last_used';

	const REDACTED_HEADERS = [ 'authorization', 'proxy_authorization', 'cookie', 'x_wp_nonce' ];

	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public static function receive_url( $webhook_id ) {
		return rest_url( self::NAMESPACE . '/hookshot/receive/' . (int) $webhook_id );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/hookshot/receive/(?P<id>\d+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_signed_webhook' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/hookshot/receive/(?P<id>\d+)/verify', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_signed_verification' ],
			'permission_callback' => '__return_true',
		] );

		// Deprecated secret-in-URL routes; see the class comment.
		register_rest_route( self::NAMESPACE, '/hookshot/incoming/(?P<secret>[a-zA-Z0-9-_]+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_incoming_webhook' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( self::NAMESPACE, '/hookshot/verify/(?P<secret>[a-zA-Z0-9-_]+)', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_verification_challenge' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle_signed_webhook( WP_REST_Request $request ) {
		$rejection = $this->reject_oversized_or_looping();
		if ( $rejection ) {
			return $rejection;
		}

		$webhook_id = $this->authenticate_signed( $request );
		if ( is_wp_error( $webhook_id ) ) {
			return $this->authentication_failed();
		}

		return $this->process( $request, $webhook_id );
	}

	public function handle_signed_verification( WP_REST_Request $request ) {
		$webhook_id = $this->authenticate_signed( $request );
		if ( is_wp_error( $webhook_id ) ) {
			return $this->authentication_failed();
		}

		return $this->challenge_response( $request );
	}

	/**
	 * @deprecated Secret in the URL path. Use POST xophz/v1/hookshot/receive/{id} with a signature header.
	 */
	public function handle_incoming_webhook( WP_REST_Request $request ) {
		$rejection = $this->reject_oversized_or_looping();
		if ( $rejection ) {
			return $rejection;
		}

		$webhook_id = $this->resolve_legacy_webhook( $request );
		if ( is_wp_error( $webhook_id ) ) {
			return $this->error_response( $webhook_id );
		}

		$sig_check = Hookshot_Signature::verify( $request, $webhook_id );
		if ( is_wp_error( $sig_check ) ) {
			Hookshot_Health::record( $webhook_id, false );
			return $this->deprecated( $this->error_response( $sig_check ), $webhook_id );
		}

		return $this->deprecated( $this->process( $request, $webhook_id ), $webhook_id );
	}

	/**
	 * @deprecated Secret in the URL path. Use POST xophz/v1/hookshot/receive/{id}/verify with a signature header.
	 */
	public function handle_verification_challenge( WP_REST_Request $request ) {
		$webhook_id = $this->resolve_legacy_webhook( $request );
		if ( is_wp_error( $webhook_id ) ) {
			return $this->error_response( $webhook_id );
		}

		return $this->deprecated( $this->challenge_response( $request ), $webhook_id );
	}

	private function process( WP_REST_Request $request, $webhook_id ) {
		try {
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

			$payload = $request->get_json_params() ?: $request->get_body_params();
			$headers = self::redact_headers( $request->get_headers() );

			$hasNoPayload = empty( $payload );
			if ( $hasNoPayload ) {
				$raw = $request->get_body();
				$hasRawBody = ! empty( $raw );
				if ( $hasRawBody ) {
					$payload = [ 'raw_body' => $raw ];
				}
			}

			$payload = Hookshot_Transform::apply_incoming( $payload, $webhook_id );

			do_action( 'xophz_hookshot_incoming', $payload, $webhook_id );

			global $hookshot_last_bridge_results;
			$bridge_results = $hookshot_last_bridge_results ?? [];

			$this->log_webhook( $webhook_id, 'incoming', $payload, $headers, 200, $bridge_results );

			Hookshot_Health::record( $webhook_id, true );

			return new WP_REST_Response( [
				'success' => true,
				'message' => 'Webhook received and processed.',
				'bridges' => $bridge_results,
			], 200 );
		} catch ( Throwable $e ) {
			error_log( sprintf( 'Hookshot Incoming Webhook Exception: %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine() ) );
			Hookshot_Notifier::notify_failure( $webhook_id, sprintf( "%s\nFile: %s:%d", $e->getMessage(), $e->getFile(), $e->getLine() ), 'Incoming REST Endpoint' );
			// The exception text stays in the server log and the failure notification, never in the response.
			return new WP_REST_Response( [
				'success' => false,
				'message' => 'Internal processing error.',
			], 500 );
		}
	}

	private function reject_oversized_or_looping() {
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

		return null;
	}

	/**
	 * Resolves an active incoming webhook by its public id and requires a valid HMAC signature.
	 * Unauthenticated calls do not touch the webhook's health record.
	 */
	private function authenticate_signed( WP_REST_Request $request ) {
		// URL params only: get_param() prefers a JSON body's own "id" (every Stripe event has one).
		$webhook_id = (int) ( $request->get_url_params()['id'] ?? 0 );

		$isActiveIncoming = $webhook_id > 0
			&& get_post_type( $webhook_id ) === 'compass_webhook'
			&& get_post_status( $webhook_id ) === 'publish'
			&& get_post_meta( $webhook_id, 'hookshot_type', true ) === 'incoming'
			&& get_post_meta( $webhook_id, 'hookshot_status', true ) === 'active';
		if ( ! $isActiveIncoming ) {
			return new WP_Error( 'unknown_webhook', 'Unknown webhook.' );
		}

		$sig_check = Hookshot_Signature::verify_required( $request, $webhook_id );

		return is_wp_error( $sig_check ) ? $sig_check : $webhook_id;
	}

	private function authentication_failed() {
		return new WP_REST_Response( [ 'success' => false, 'message' => 'Webhook signature missing or invalid.' ], 401 );
	}

	private function resolve_legacy_webhook( WP_REST_Request $request ) {
		$isEnabled = (bool) apply_filters( 'xophz_hookshot_legacy_secret_urls', true );
		if ( ! $isEnabled ) {
			return new WP_Error( 'legacy_url_retired', 'Secret URLs are retired. Use the signed receive endpoint.', [ 'status' => 410 ] );
		}

		$webhook_id = $this->resolve_webhook( $request->get_url_params()['secret'] ?? '' );
		if ( ! is_wp_error( $webhook_id ) ) {
			update_post_meta( $webhook_id, self::LEGACY_LAST_USED_META, time() );
		}

		return $webhook_id;
	}

	private function deprecated( WP_REST_Response $response, $webhook_id ) {
		$response->header( 'Deprecation', 'true' );
		$response->header( 'Link', '<' . self::receive_url( $webhook_id ) . '>; rel="successor-version"' );

		return $response;
	}

	private function error_response( WP_Error $error ) {
		$data = $error->get_error_data();
		$status = is_array( $data ) ? (int) ( $data['status'] ?? 401 ) : 401;

		return new WP_REST_Response( [ 'success' => false, 'message' => $error->get_error_message() ], $status );
	}

	private function challenge_response( WP_REST_Request $request ) {
		$challenge = $request->get_param( 'challenge' ) ?: $request->get_header( 'x_hookshot_challenge' );

		return new WP_REST_Response( [
			'success'   => true,
			'challenge' => $challenge,
		] );
	}

	private static function redact_headers( array $headers ) {
		foreach ( self::REDACTED_HEADERS as $name ) {
			$isPresent = isset( $headers[ $name ] );
			if ( $isPresent ) {
				$headers[ $name ] = [ '[redacted]' ];
			}
		}

		return $headers;
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
			return new WP_Error( 'invalid_secret', 'Invalid or inactive webhook secret.', [ 'status' => 401 ] );
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

		// REMOTE_ADDR, or the forwarded client behind a configured trusted proxy. Never a caller-set header alone.
		$client_ip = Hookshot_Client_Ip::resolve( $request );

		$isAllowed = '' !== $client_ip && Hookshot_Client_Ip::matches_any( $client_ip, $ip_list );
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

	private function log_webhook( $webhook_id, $direction, $payload, $headers, $status_code = 200, $bridge_results = [] ) {
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
			if ( ! empty( $bridge_results ) ) {
				update_post_meta( $log_id, 'wh_log_bridge_results', wp_json_encode( $bridge_results ) );
			}
		}

		return $isValid ? $log_id : null;
	}
}
