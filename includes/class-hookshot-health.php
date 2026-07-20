<?php

class Hookshot_Health {

	const WINDOW_HOURS = 24;
	const DEGRADED_THRESHOLD = 50;
	const TRANSIENT_PREFIX = 'hookshot_health_';
	const TRANSIENT_TTL = 300;

	public static function record( $webhook_id, $is_success ) {
		$key = self::TRANSIENT_PREFIX . $webhook_id;
		$stats = get_transient( $key ) ?: [ 'successes' => 0, 'failures' => 0, 'window_start' => time() ];

		$windowExpired = ( time() - $stats['window_start'] ) > ( self::WINDOW_HOURS * 3600 );
		if ( $windowExpired ) {
			$stats = [ 'successes' => 0, 'failures' => 0, 'window_start' => time() ];
		}

		if ( $is_success ) {
			$stats['successes']++;
		} else {
			$stats['failures']++;
		}

		$stats['last_event'] = time();
		$stats['last_status'] = $is_success ? 'success' : 'failure';

		set_transient( $key, $stats, self::WINDOW_HOURS * 3600 );

		update_post_meta( $webhook_id, 'hookshot_last_fired', time() );
		update_post_meta( $webhook_id, 'hookshot_last_status', $is_success ? 'success' : 'failure' );

		$failure_rate = self::calculate_failure_rate( $stats );
		$isDegraded = $failure_rate >= self::DEGRADED_THRESHOLD && $stats['failures'] >= 3;
		if ( $isDegraded ) {
			do_action( 'xophz_hookshot_health_degraded', $webhook_id, $failure_rate );
		}
	}

	public static function get_status( $webhook_id ) {
		$key = self::TRANSIENT_PREFIX . $webhook_id;
		$stats = get_transient( $key );

		$hasNoStats = empty( $stats );
		if ( $hasNoStats ) {
			return [
				'status'       => 'unknown',
				'color'        => 'grey',
				'failure_rate' => 0,
				'successes'    => 0,
				'failures'     => 0,
				'last_fired'   => get_post_meta( $webhook_id, 'hookshot_last_fired', true ) ?: null,
				'last_status'  => get_post_meta( $webhook_id, 'hookshot_last_status', true ) ?: null,
			];
		}

		$failure_rate = self::calculate_failure_rate( $stats );

		$isHealthy = $failure_rate < 10;
		$isWarning = $failure_rate >= 10 && $failure_rate < self::DEGRADED_THRESHOLD;

		$status = 'critical';
		$color = 'red';

		if ( $isHealthy ) {
			$status = 'healthy';
			$color = 'green';
		} elseif ( $isWarning ) {
			$status = 'degraded';
			$color = 'yellow';
		}

		return [
			'status'       => $status,
			'color'        => $color,
			'failure_rate' => round( $failure_rate, 1 ),
			'successes'    => $stats['successes'],
			'failures'     => $stats['failures'],
			'last_fired'   => $stats['last_event'] ?? null,
			'last_status'  => $stats['last_status'] ?? null,
			'window_start' => $stats['window_start'],
		];
	}

	public static function get_all_statuses() {
		$webhooks = get_posts( [
			'post_type'      => 'compass_webhook',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		$statuses = [];

		foreach ( $webhooks as $webhook_id ) {
			$statuses[ $webhook_id ] = self::get_status( $webhook_id );
		}

		return $statuses;
	}

	public static function get_aggregate_stats() {
		$statuses = self::get_all_statuses();

		$total = count( $statuses );
		$healthy = 0;
		$degraded = 0;
		$critical = 0;
		$total_successes = 0;
		$total_failures = 0;

		foreach ( $statuses as $status ) {
			$total_successes += $status['successes'];
			$total_failures += $status['failures'];

			switch ( $status['status'] ) {
				case 'healthy':
					$healthy++;
					break;
				case 'degraded':
					$degraded++;
					break;
				case 'critical':
					$critical++;
					break;
			}
		}

		$total_dispatches = $total_successes + $total_failures;
		$overall_rate = $total_dispatches > 0 ? round( ( $total_successes / $total_dispatches ) * 100, 1 ) : 100;

		return [
			'total_webhooks'    => $total,
			'healthy'           => $healthy,
			'degraded'          => $degraded,
			'critical'          => $critical,
			'total_dispatches'  => $total_dispatches,
			'total_successes'   => $total_successes,
			'total_failures'    => $total_failures,
			'success_rate'      => $overall_rate,
		];
	}

	private static function calculate_failure_rate( $stats ) {
		$total = $stats['successes'] + $stats['failures'];

		$hasNoData = $total === 0;
		if ( $hasNoData ) {
			return 0;
		}

		return ( $stats['failures'] / $total ) * 100;
	}
}
