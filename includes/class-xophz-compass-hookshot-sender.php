<?php

class Xophz_Compass_Hookshot_Sender {

	private $retry;
	private static $dispatch_depth = 0;

	public function init() {
		$this->retry = Hookshot_Retry::get_instance();
		// No need to call init() again since it's called in the core class

		add_action( 'init', [ $this, 'attach_listeners' ], 99 );
	}

	public function attach_listeners() {
		$webhooks = get_posts( [
			'post_type'      => 'compass_webhook',
			'posts_per_page' => -1,
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => 'hookshot_type', 'value' => 'outgoing' ],
				[ 'key' => 'hookshot_status', 'value' => 'active' ],
			],
		] );

		$attach_webhook_listener = function ( $webhook ) {
			$trigger_event = get_post_meta( $webhook->ID, 'hookshot_trigger_event', true );

			$hasTrigger = ! empty( $trigger_event );
			if ( $hasTrigger ) {
				add_action( $trigger_event, function() use ( $webhook, $trigger_event ) {
					$this->dispatch_webhook( $webhook->ID, $trigger_event, func_get_args() );
				}, 10, 10 );
			}
		};

		array_walk( $webhooks, $attach_webhook_listener );
	}

	private function dispatch_webhook( $webhook_id, $event, $hook_args ) {
		self::$dispatch_depth++;

		$isInfiniteLoop = self::$dispatch_depth > 5;
		if ( $isInfiniteLoop ) {
			error_log( 'Hookshot: Synchronous infinite loop detected. Aborting dispatch for Webhook ID: ' . $webhook_id );
			self::$dispatch_depth--;
			return;
		}

		$target_url = get_post_meta( $webhook_id, 'hookshot_target_url', true );

		$hasNoTarget = empty( $target_url );
		if ( $hasNoTarget ) {
			self::$dispatch_depth--;
			return;
		}

		$payload = [
			'event'     => $event,
			'timestamp' => time(),
			'args'      => $hook_args,
		];

		$payload = apply_filters( 'xophz_hookshot_outgoing_payload', $payload, $webhook_id, $event );
		$payload = Hookshot_Transform::apply( $payload, $webhook_id );

		$encoded_payload = wp_json_encode( $payload );

		$request_args = [
			'body'        => $encoded_payload,
			'headers'     => array_merge(
				[
					'Content-Type'     => 'application/json',
					'User-Agent'       => 'Xophz-COMPASS-Hookshot/2.0',
					'X-Hookshot-Depth' => self::$dispatch_depth,
				],
				Hookshot_Signature::build_outgoing_headers( $encoded_payload, $webhook_id ),
				Hookshot_Auth::build_headers( $webhook_id )
			),
			'timeout'     => 15,
			'data_format' => 'body',
		];

		$is_async = get_post_meta( $webhook_id, 'hookshot_async', true ) === '1';
		$request_args['blocking'] = ! $is_async;

		$response = wp_remote_post( $target_url, $request_args );

		$log_id = $this->log_outgoing( $webhook_id, $payload, $response );

		$status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
		$isSuccessful = $status_code >= 200 && $status_code < 300;

		Hookshot_Health::record( $webhook_id, $isSuccessful );

		$needsRetry = ! $isSuccessful && $log_id;
		if ( $needsRetry ) {
			$this->retry->queue_retry( $webhook_id, $log_id, $event, $payload );
		}

		self::$dispatch_depth--;
	}

	private function log_outgoing( $webhook_id, $payload, $response ) {
		$status_code = 0;
		$response_body = '';

		if ( is_wp_error( $response ) ) {
			$status_code = 500;
			$response_body = $response->get_error_message();
		} else {
			$status_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
		}

		$log_id = wp_insert_post( [
			'post_title'  => sprintf( 'Outgoing Webhook (%d)', $webhook_id ),
			'post_type'   => 'compass_wh_log',
			'post_status' => 'publish',
		] );

		$isValid = ! is_wp_error( $log_id );
		if ( $isValid ) {
			update_post_meta( $log_id, 'wh_log_parent_id', $webhook_id );
			update_post_meta( $log_id, 'wh_log_direction', 'outgoing' );
			update_post_meta( $log_id, 'wh_log_payload', wp_json_encode( $payload ) );
			update_post_meta( $log_id, 'wh_log_response', $response_body );
			update_post_meta( $log_id, 'wh_log_status', $status_code );
		}

		return $isValid ? $log_id : null;
	}
}

