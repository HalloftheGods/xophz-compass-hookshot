<?php

class Hookshot_Notifier {

	public static function init() {
		add_action( 'xophz_hookshot_health_degraded', [ __CLASS__, 'notify_health_degraded' ], 10, 2 );
		add_action( 'xophz_hookshot_dead_letter', [ __CLASS__, 'notify_dead_letter' ], 10, 3 );
	}

	public static function notify_failure( $webhook_id, $error_message, $context = 'Incoming Webhook' ) {
		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			return;
		}

		// Throttle email alerts to max 1 per 15 minutes per webhook & context
		$transient_key = 'hookshot_alert_' . md5( $webhook_id . '_' . $context );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, true, 900 );

		$webhook_title = $webhook_id ? ( get_the_title( $webhook_id ) ?: "Webhook #{$webhook_id}" ) : 'Unknown Webhook';
		$site_name = get_bloginfo( 'name' );

		$subject = sprintf( '[%s] ⚠️ Webhook Failure Alert: %s', $site_name, $webhook_title );

		$message  = "Hello Site Owner,\n\n";
		$message .= sprintf( "A failure occurred in Hookshot for webhook \"%s\" (ID: %d).\n\n", $webhook_title, $webhook_id );
		$message .= "Context: " . $context . "\n";
		$message .= "Error Details:\n" . $error_message . "\n\n";
		$message .= "Timestamp: " . current_time( 'Y-m-d H:i:s T' ) . "\n\n";
		$message .= "Inspect logs and status in your COMPASS Webhooks Dashboard:\n";
		$message .= admin_url( 'admin.php?page=xophz-compass#/hookshot' ) . "\n\n";
		$message .= "Best regards,\n";
		$message .= "Xophz Magic Hookshot Engine\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $admin_email, $subject, $message, $headers );
	}

	public static function notify_health_degraded( $webhook_id, $failure_rate ) {
		$transient_key = 'hookshot_alert_degraded_' . $webhook_id;
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, true, 1800 ); // 30-minute throttle

		$admin_email = get_option( 'admin_email' );
		if ( empty( $admin_email ) || ! is_email( $admin_email ) ) {
			return;
		}

		$webhook_title = get_the_title( $webhook_id ) ?: "Webhook #{$webhook_id}";
		$site_name = get_bloginfo( 'name' );

		$subject = sprintf( '[%s] 🚨 Webhook Health Degraded: %s', $site_name, $webhook_title );

		$message  = "Hello Site Owner,\n\n";
		$message .= sprintf( "The health status for webhook \"%s\" (ID: %d) has DEGRADED.\n\n", $webhook_title, $webhook_id );
		$message .= sprintf( "Failure Rate: %.1f%%\n\n", $failure_rate );
		$message .= "Log into your dashboard to check logs and target configuration:\n";
		$message .= admin_url( 'admin.php?page=xophz-compass#/hookshot' ) . "\n\n";
		$message .= "Best regards,\n";
		$message .= "Xophz Magic Hookshot Engine\n";

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $admin_email, $subject, $message, $headers );
	}

	public static function notify_dead_letter( $payload, $webhook_id, $log_id ) {
		$error_message = get_post_meta( $log_id, 'wh_log_retry_last_error', true ) ?: 'Max retry attempts exhausted.';
		self::notify_failure( $webhook_id, "Dead Letter Event (Log #{$log_id}): " . $error_message, 'Dead Letter Queue' );
	}
}
