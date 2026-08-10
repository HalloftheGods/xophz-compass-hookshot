<?php

class Hookshot_REST_Dashboard {

	const NAMESPACE = 'hookshot/v1';

	public function init() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route( self::NAMESPACE, '/webhooks', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'list_webhooks' ],
				'permission_callback' => [ $this, 'check_read' ],
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_webhook' ],
				'permission_callback' => [ $this, 'check_admin' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/webhooks/(?P<id>\d+)', [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_webhook' ],
				'permission_callback' => [ $this, 'check_read' ],
			],
			[
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_webhook' ],
				'permission_callback' => [ $this, 'check_admin' ],
			],
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_webhook' ],
				'permission_callback' => [ $this, 'check_admin' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/webhooks/(?P<id>\d+)/test', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'test_webhook' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		register_rest_route( self::NAMESPACE, '/logs', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_logs' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/webhooks/(?P<id>\d+)/logs', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_logs' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/webhooks/(?P<id>\d+)/health', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_health' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/dead-letters', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'list_dead_letters' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/dead-letters/(?P<id>\d+)/retry', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'retry_dead_letter' ],
			'permission_callback' => [ $this, 'check_admin' ],
		] );

		register_rest_route( self::NAMESPACE, '/stats', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_stats' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/bridges', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_bridges' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/presets', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_presets' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );

		register_rest_route( self::NAMESPACE, '/auth-types', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ $this, 'get_auth_types' ],
			'permission_callback' => [ $this, 'check_read' ],
		] );
	}

	public function check_admin() {
		return current_user_can( 'manage_options' );
	}

	public function check_read() {
		return is_user_logged_in() || current_user_can( 'manage_options' );
	}

	public function list_webhooks( WP_REST_Request $request ) {
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 50 );
		$page = (int) ( $request->get_param( 'page' ) ?: 1 );
		$type_filter = $request->get_param( 'type' );
		$status_filter = $request->get_param( 'status' );

		$args = [
			'post_type'      => 'compass_webhook',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => [],
		];

		$hasTypeFilter = ! empty( $type_filter );
		if ( $hasTypeFilter ) {
			$args['meta_query'][] = [ 'key' => 'hookshot_type', 'value' => $type_filter ];
		}

		$hasStatusFilter = ! empty( $status_filter );
		if ( $hasStatusFilter ) {
			$args['meta_query'][] = [ 'key' => 'hookshot_status', 'value' => $status_filter ];
		}

		$query = new WP_Query( $args );
		$webhooks = array_map( [ $this, 'format_webhook' ], $query->posts );

		return new WP_REST_Response( [
			'items' => $webhooks,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		] );
	}

	public function create_webhook( WP_REST_Request $request ) {
		$params = $request->get_json_params();

		$post_id = wp_insert_post( [
			'post_title'  => sanitize_text_field( $params['name'] ?? 'New Webhook' ),
			'post_type'   => 'compass_webhook',
			'post_status' => 'publish',
		] );

		$isError = is_wp_error( $post_id );
		if ( $isError ) {
			return new WP_REST_Response( [ 'error' => $post_id->get_error_message() ], 500 );
		}

		$this->save_webhook_meta( $post_id, $params );

		return new WP_REST_Response( $this->format_webhook( get_post( $post_id ) ), 201 );
	}

	public function get_webhook( WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'id' ) );

		$isNotFound = ! $post || $post->post_type !== 'compass_webhook';
		if ( $isNotFound ) {
			return new WP_REST_Response( [ 'error' => 'Webhook not found.' ], 404 );
		}

		return new WP_REST_Response( $this->format_webhook( $post ) );
	}

	public function update_webhook( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$post = get_post( $id );

		$isNotFound = ! $post || $post->post_type !== 'compass_webhook';
		if ( $isNotFound ) {
			return new WP_REST_Response( [ 'error' => 'Webhook not found.' ], 404 );
		}

		$params = $request->get_json_params();

		$hasNameUpdate = ! empty( $params['name'] );
		if ( $hasNameUpdate ) {
			wp_update_post( [ 'ID' => $id, 'post_title' => sanitize_text_field( $params['name'] ) ] );
		}

		$this->save_webhook_meta( $id, $params );

		return new WP_REST_Response( $this->format_webhook( get_post( $id ) ) );
	}

	public function delete_webhook( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		wp_delete_post( $id, true );

		return new WP_REST_Response( [ 'deleted' => true ] );
	}

	public function test_webhook( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		$type = get_post_meta( $id, 'hookshot_type', true );

		$isIncoming = $type === 'incoming';
		if ( $isIncoming ) {
			$secret = get_post_meta( $id, 'hookshot_secret', true );
			$endpoint = rest_url( "xophz/v1/hookshot/incoming/{$secret}" );

			return new WP_REST_Response( [
				'type'     => 'incoming',
				'endpoint' => $endpoint,
				'message'  => 'Use this URL to receive webhooks. Send a POST with JSON body to test.',
			] );
		}

		$target_url = get_post_meta( $id, 'hookshot_target_url', true );

		$hasNoTarget = empty( $target_url );
		if ( $hasNoTarget ) {
			return new WP_REST_Response( [ 'error' => 'No target URL configured.' ], 400 );
		}

		$test_payload = [
			'event'     => 'hookshot_test',
			'timestamp' => time(),
			'args'      => [ 'message' => 'Test ping from COMPASS Hookshot' ],
		];

		$transform = get_post_meta( $id, 'hookshot_transform', true );
		$hasTransform = ! empty( $transform );
		if ( $hasTransform ) {
			$test_payload = Hookshot_Transform::apply( $test_payload, $id );
		}

		$encoded = wp_json_encode( $test_payload );

		$response = wp_remote_post( $target_url, [
			'body'        => $encoded,
			'headers'     => array_merge(
				[ 'Content-Type' => 'application/json', 'User-Agent' => 'Xophz-COMPASS-Hookshot/2.0' ],
				Hookshot_Signature::build_outgoing_headers( $encoded, $id ),
				Hookshot_Auth::build_headers( $id )
			),
			'timeout'     => 15,
			'data_format' => 'body',
		] );

		$status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
		$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );

		return new WP_REST_Response( [
			'type'        => 'outgoing',
			'target'      => $target_url,
			'status_code' => $status_code,
			'response'    => $body,
		] );
	}

	public function get_logs( WP_REST_Request $request ) {
		$webhook_id = (int) $request->get_param( 'id' );
		$per_page   = (int) ( $request->get_param( 'per_page' ) ?: 20 );
		$page       = (int) ( $request->get_param( 'page' ) ?: 1 );

		$args = [
			'post_type'      => 'compass_wh_log',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $webhook_id > 0 ) {
			$args['meta_key']   = 'wh_log_parent_id';
			$args['meta_value'] = $webhook_id;
		}

		$query = new WP_Query( $args );

		$logs = array_map( [ $this, 'format_log' ], $query->posts );

		return new WP_REST_Response( [
			'items' => $logs,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		] );
	}

	public function get_health( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );

		return new WP_REST_Response( Hookshot_Health::get_status( $id ) );
	}

	public function list_dead_letters( WP_REST_Request $request ) {
		$per_page = (int) ( $request->get_param( 'per_page' ) ?: 20 );
		$page = (int) ( $request->get_param( 'page' ) ?: 1 );

		$query = new WP_Query( [
			'post_type'      => 'compass_wh_log',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_key'       => 'wh_log_retry_status',
			'meta_value'     => 'dead_letter',
		] );

		$logs = array_map( [ $this, 'format_log' ], $query->posts );

		return new WP_REST_Response( [
			'items' => $logs,
			'total' => $query->found_posts,
			'pages' => $query->max_num_pages,
		] );
	}

	public function retry_dead_letter( WP_REST_Request $request ) {
		$log_id = (int) $request->get_param( 'id' );
		$webhook_id = (int) get_post_meta( $log_id, 'wh_log_parent_id', true );
		$payload_json = get_post_meta( $log_id, 'wh_log_retry_payload', true );
		$event = get_post_meta( $log_id, 'wh_log_retry_event', true );

		$isInvalid = empty( $webhook_id ) || empty( $payload_json );
		if ( $isInvalid ) {
			return new WP_REST_Response( [ 'error' => 'Invalid dead letter.' ], 400 );
		}

		update_post_meta( $log_id, 'wh_log_retry_status', 'pending' );
		update_post_meta( $log_id, 'wh_log_retry_count', 0 );

		$retry = new Hookshot_Retry();
		$retry->queue_retry( $webhook_id, $log_id, $event, json_decode( $payload_json, true ) );

		return new WP_REST_Response( [ 'retrying' => true, 'log_id' => $log_id ] );
	}

	public function get_stats() {
		$health_stats = Hookshot_Health::get_aggregate_stats();

		$dead_letter_count = ( new WP_Query( [
			'post_type'      => 'compass_wh_log',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => 'wh_log_retry_status',
			'meta_value'     => 'dead_letter',
		] ) )->found_posts;

		$recent_logs = ( new WP_Query( [
			'post_type'      => 'compass_wh_log',
			'posts_per_page' => 10,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] ) )->posts;

		return new WP_REST_Response( array_merge( $health_stats, [
			'dead_letters'  => $dead_letter_count,
			'recent_activity' => array_map( [ $this, 'format_log' ], $recent_logs ),
		] ) );
	}

	public function get_bridges() {
		$bridges = Hookshot_Bridges::get_registered();
		$formatted = [];

		foreach ( $bridges as $slug => $bridge ) {
			$formatted[] = [
				'slug'        => $slug,
				'name'        => $bridge['name'],
				'description' => $bridge['description'],
				'icon'        => $bridge['icon'],
				'category'    => $bridge['category'],
				'fields'      => $bridge['fields'],
			];
		}

		return new WP_REST_Response( $formatted );
	}

	public function get_presets() {
		return new WP_REST_Response( Hookshot_Transform::get_presets() );
	}

	public function get_auth_types() {
		return new WP_REST_Response( Hookshot_Auth::get_types() );
	}

	private function format_webhook( $post ) {
		$meta_keys = [
			'hookshot_type', 'hookshot_status', 'hookshot_secret',
			'hookshot_target_url', 'hookshot_trigger_event', 'hookshot_signing_secret',
			'hookshot_sig_header', 'hookshot_auth_type', 'hookshot_auth_value',
			'hookshot_auth_header', 'hookshot_transform', 'hookshot_incoming_transform',
			'hookshot_bridges', 'hookshot_bridge_config', 'hookshot_allowed_ips',
			'hookshot_rate_limit', 'hookshot_max_retries', 'hookshot_last_fired',
			'hookshot_last_status', 'hookshot_async',
		];

		$data = [
			'id'         => $post->ID,
			'name'       => $post->post_title,
			'created_at' => $post->post_date,
			'updated_at' => $post->post_modified,
		];

		foreach ( $meta_keys as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			$short_key = str_replace( 'hookshot_', '', $key );
			$data[ $short_key ] = $value ?: null;
		}

		$data['health'] = Hookshot_Health::get_status( $post->ID );

		$isIncoming = ( $data['type'] ?? 'incoming' ) === 'incoming';
		if ( $isIncoming ) {
			$secret = $data['secret'] ?? '';
			$data['endpoint'] = rest_url( "xophz/v1/hookshot/incoming/{$secret}" );
		}

		$categories = wp_get_post_terms( $post->ID, 'hookshot_category', [ 'fields' => 'names' ] );
		$data['categories'] = is_wp_error( $categories ) ? [] : $categories;

		return $data;
	}

	private function format_log( $post ) {
		return [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'created_at'   => $post->post_date,
			'webhook_id'   => get_post_meta( $post->ID, 'wh_log_parent_id', true ),
			'direction'    => get_post_meta( $post->ID, 'wh_log_direction', true ),
			'status'       => get_post_meta( $post->ID, 'wh_log_status', true ),
			'payload'      => json_decode( get_post_meta( $post->ID, 'wh_log_payload', true ), true ),
			'headers'      => json_decode( get_post_meta( $post->ID, 'wh_log_headers', true ), true ),
			'response'     => get_post_meta( $post->ID, 'wh_log_response', true ),
			'retry_status'   => get_post_meta( $post->ID, 'wh_log_retry_status', true ) ?: null,
			'retry_count'    => (int) get_post_meta( $post->ID, 'wh_log_retry_count', true ),
			'retry_error'    => get_post_meta( $post->ID, 'wh_log_retry_last_error', true ) ?: null,
			'bridge_results' => json_decode( get_post_meta( $post->ID, 'wh_log_bridge_results', true ), true ) ?: null,
		];
	}

	private function save_webhook_meta( $post_id, $params ) {
		$meta_fields = [
			'type'               => 'hookshot_type',
			'status'             => 'hookshot_status',
			'secret'             => 'hookshot_secret',
			'target_url'         => 'hookshot_target_url',
			'trigger_event'      => 'hookshot_trigger_event',
			'signing_secret'     => 'hookshot_signing_secret',
			'sig_header'         => 'hookshot_sig_header',
			'auth_type'          => 'hookshot_auth_type',
			'auth_value'         => 'hookshot_auth_value',
			'auth_header'        => 'hookshot_auth_header',
			'transform'          => 'hookshot_transform',
			'incoming_transform' => 'hookshot_incoming_transform',
			'bridges'            => 'hookshot_bridges',
			'bridge_config'      => 'hookshot_bridge_config',
			'allowed_ips'        => 'hookshot_allowed_ips',
			'rate_limit'         => 'hookshot_rate_limit',
			'max_retries'        => 'hookshot_max_retries',
			'async'              => 'hookshot_async',
		];

		foreach ( $meta_fields as $param_key => $meta_key ) {
			$hasValue = isset( $params[ $param_key ] );
			if ( ! $hasValue ) {
				continue;
			}

			$value = $params[ $param_key ];
			$needsJson = is_array( $value );
			$sanitized = $needsJson ? $value : sanitize_text_field( $value );

			update_post_meta( $post_id, $meta_key, $sanitized );
		}

		$isNewIncoming = ( $params['type'] ?? '' ) === 'incoming' && empty( get_post_meta( $post_id, 'hookshot_secret', true ) );
		if ( $isNewIncoming ) {
			update_post_meta( $post_id, 'hookshot_secret', wp_generate_password( 24, false ) );
		}

		$hasCategories = isset( $params['categories'] ) && is_array( $params['categories'] );
		if ( $hasCategories ) {
			wp_set_post_terms( $post_id, $params['categories'], 'hookshot_category' );
		}
	}
}
