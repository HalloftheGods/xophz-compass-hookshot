<?php

class Hookshot_GC {

	const LOG_RETENTION_DAYS = 30;

	public function init() {
		add_action( 'hookshot_daily_gc', [ $this, 'run_garbage_collection' ] );

		if ( ! wp_next_scheduled( 'hookshot_daily_gc' ) ) {
			wp_schedule_event( time(), 'daily', 'hookshot_daily_gc' );
		}
	}

	public function run_garbage_collection() {
		global $wpdb;

		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( '-' . self::LOG_RETENTION_DAYS . ' days' ) );

		// Batch delete the posts directly to avoid loading thousands of WP_Post objects into memory
		$deleted_posts = $wpdb->query( $wpdb->prepare( "
			DELETE FROM {$wpdb->posts} 
			WHERE post_type = 'compass_wh_log' 
			AND post_date < %s
		", $cutoff_date ) );

		// Clean up orphaned postmeta left behind by the raw SQL delete
		if ( $deleted_posts ) {
			$wpdb->query( "
				DELETE pm FROM {$wpdb->postmeta} pm 
				LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id 
				WHERE p.ID IS NULL 
				AND pm.meta_key LIKE 'wh_log_%'
			" );
		}
	}
}
