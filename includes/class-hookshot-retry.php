<?php

class Hookshot_Retry {

	const ACTION_HOOK = 'hookshot_retry_dispatch';
	const FALLBACK_CRON_HOOK = 'hookshot_cron_process_retries';
	const DEFAULT_MAX_ATTEMPTS = 5;
	const BACKOFF_SCHEDULE = [ 120, 900, 3600, 21600 ];

	private $has_action_scheduler;
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function init() {
		$this->has_action_scheduler = function_exists( 'as_schedule_single_action' );

		add_action( self::ACTION_HOOK, [ $this, 'execute_retry' ], 10, 2 );

		$needsCronFallback = ! $this->has_action_scheduler;
		if ( $needsCronFallback ) {
			add_action( self::FALLBACK_CRON_HOOK, [ $this, 'process_cron_retries' ] );
			$this->schedule_fallback_cron();
		}
	}

	public function queue_retry( $webhook_id, $log_id, $event, $payload ) {
		$attempt_count = (int) get_post_meta( $log_id, 'wh_log_retry_count', true );
		$max_attempts = (int) ( get_post_meta( $webhook_id, 'hookshot_max_retries', true ) ?: self::DEFAULT_MAX_ATTEMPTS );

		$isExhausted = $attempt_count >= $max_attempts;
		if ( $isExhausted ) {
			update_post_meta( $log_id, 'wh_log_retry_status', 'dead_letter' );
			do_action( 'xophz_hookshot_dead_letter', $payload, $webhook_id, $log_id );
			return;
		}

		update_post_meta( $log_id, 'wh_log_retry_status', 'pending' );

		$delay = $this->calculate_delay( $attempt_count );
		$retry_args = [ $log_id, $webhook_id ];

		update_post_meta( $log_id, 'wh_log_retry_payload', wp_json_encode( $payload ) );
		update_post_meta( $log_id, 'wh_log_retry_event', $event );

		if ( $this->has_action_scheduler ) {
			as_schedule_single_action( time() + $delay, self::ACTION_HOOK, $retry_args, 'hookshot' );
			return;
		}

		update_post_meta( $log_id, 'wh_log_next_retry_at', time() + $delay );
	}

	public function execute_retry( $log_id, $webhook_id ) {
		$retry_status = get_post_meta( $log_id, 'wh_log_retry_status', true );

		$isNotPending = $retry_status !== 'pending';
		if ( $isNotPending ) {
			return;
		}

		$target_url = get_post_meta( $webhook_id, 'hookshot_target_url', true );
		$payload_json = get_post_meta( $log_id, 'wh_log_retry_payload', true );
		$event = get_post_meta( $log_id, 'wh_log_retry_event', true );

		$hasNoTarget = empty( $target_url ) || empty( $payload_json );
		if ( $hasNoTarget ) {
			update_post_meta( $log_id, 'wh_log_retry_status', 'dead_letter' );
			return;
		}

		$payload = json_decode( $payload_json, true );
		$attempt_count = (int) get_post_meta( $log_id, 'wh_log_retry_count', true ) + 1;
		update_post_meta( $log_id, 'wh_log_retry_count', $attempt_count );

		$encoded_payload = wp_json_encode( $payload );

		$request_args = [
			'body'        => $encoded_payload,
			'headers'     => array_merge(
				[
					'Content-Type' => 'application/json',
					'User-Agent'   => 'Xophz-COMPASS-Hookshot/2.0',
					'X-Hookshot-Retry' => $attempt_count,
				],
				Hookshot_Signature::build_outgoing_headers( $encoded_payload, $webhook_id ),
				Hookshot_Auth::build_headers( $webhook_id )
			),
			'timeout'     => 15,
			'data_format' => 'body',
		];

		$response = wp_remote_post( $target_url, $request_args );

		$status_code = is_wp_error( $response ) ? 500 : wp_remote_retrieve_response_code( $response );
		$isSuccessful = $status_code >= 200 && $status_code < 300;

		if ( $isSuccessful ) {
			update_post_meta( $log_id, 'wh_log_retry_status', 'succeeded' );
			update_post_meta( $log_id, 'wh_log_status', $status_code );
			return;
		}

		$response_body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
		update_post_meta( $log_id, 'wh_log_retry_last_error', $response_body );
		update_post_meta( $log_id, 'wh_log_status', $status_code );

		$this->queue_retry( $webhook_id, $log_id, $event, $payload );
	}

	public function process_cron_retries() {
		$pending_logs = get_posts( [
			'post_type'      => 'compass_wh_log',
			'posts_per_page' => 20,
			'meta_query'     => [
				'relation' => 'AND',
				[
					'key'     => 'wh_log_retry_status',
					'value'   => 'pending',
				],
				[
					'key'     => 'wh_log_next_retry_at',
					'value'   => time(),
					'compare' => '<=',
					'type'    => 'NUMERIC',
				],
			],
		] );

		foreach ( $pending_logs as $log ) {
			$webhook_id = get_post_meta( $log->ID, 'wh_log_parent_id', true );
			$this->execute_retry( $log->ID, (int) $webhook_id );
		}
	}

	public function cancel_retries( $log_id ) {
		update_post_meta( $log_id, 'wh_log_retry_status', 'cancelled' );

		if ( $this->has_action_scheduler ) {
			as_unschedule_all_actions( self::ACTION_HOOK, [ $log_id ], 'hookshot' );
		}
	}

	private function calculate_delay( $attempt_count ) {
		$index = min( $attempt_count, count( self::BACKOFF_SCHEDULE ) - 1 );

		return self::BACKOFF_SCHEDULE[ $index ];
	}

	private function schedule_fallback_cron() {
		$isAlreadyScheduled = wp_next_scheduled( self::FALLBACK_CRON_HOOK );
		if ( $isAlreadyScheduled ) {
			return;
		}

		wp_schedule_event( time(), 'five_minutes', self::FALLBACK_CRON_HOOK );
	}

	public static function register_cron_interval( $schedules ) {
		$schedules['five_minutes'] = [
			'interval' => 300,
			'display'  => 'Every Five Minutes',
		];

		return $schedules;
	}
}
