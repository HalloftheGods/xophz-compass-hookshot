<?php

class Hookshot_Signature {

	const DEFAULT_HEADER = 'X-Hookshot-Signature';
	const TIMESTAMP_TOLERANCE = 300;

	public static function sign( $payload_body, $signing_secret ) {
		$timestamp = time();
		$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload_body}", $signing_secret );

		return "t={$timestamp},v1={$signature}";
	}

	public static function verify( WP_REST_Request $request, $webhook_id ) {
		$signing_secret = get_post_meta( $webhook_id, 'hookshot_signing_secret', true );

		$hasNoSecret = empty( $signing_secret );
		if ( $hasNoSecret ) {
			return true;
		}

		$header_name = get_post_meta( $webhook_id, 'hookshot_sig_header', true ) ?: self::DEFAULT_HEADER;
		$header_value = $request->get_header( strtolower( str_replace( '-', '_', $header_name ) ) );

		$hasNoHeader = empty( $header_value );
		if ( $hasNoHeader ) {
			return new WP_Error( 'missing_signature', 'Missing webhook signature header.', [ 'status' => 401 ] );
		}

		$raw_body = $request->get_body();

		return self::validate_stripe_format( $header_value, $raw_body, $signing_secret );
	}

	public static function build_outgoing_headers( $payload_body, $webhook_id ) {
		$signing_secret = get_post_meta( $webhook_id, 'hookshot_signing_secret', true );

		$hasNoSecret = empty( $signing_secret );
		if ( $hasNoSecret ) {
			return [];
		}

		$header_name = get_post_meta( $webhook_id, 'hookshot_sig_header', true ) ?: self::DEFAULT_HEADER;
		$signature = self::sign( $payload_body, $signing_secret );

		return [ $header_name => $signature ];
	}

	private static function validate_stripe_format( $header_value, $raw_body, $signing_secret ) {
		$parts = [];

		foreach ( explode( ',', $header_value ) as $segment ) {
			$pair = explode( '=', $segment, 2 );
			$hasBothParts = count( $pair ) === 2;
			if ( $hasBothParts ) {
				$parts[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}

		$hasMissingFields = ! isset( $parts['t'], $parts['v1'] );
		if ( $hasMissingFields ) {
			return self::try_raw_hmac( $header_value, $raw_body, $signing_secret );
		}

		$timestamp = (int) $parts['t'];
		$provided_sig = $parts['v1'];

		$isReplayAttack = abs( time() - $timestamp ) > self::TIMESTAMP_TOLERANCE;
		if ( $isReplayAttack ) {
			return new WP_Error( 'expired_signature', 'Webhook signature timestamp expired.', [ 'status' => 401 ] );
		}

		$expected_sig = hash_hmac( 'sha256', "{$timestamp}.{$raw_body}", $signing_secret );

		$isValidSignature = hash_equals( $expected_sig, $provided_sig );
		if ( ! $isValidSignature ) {
			return new WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
		}

		return true;
	}

	private static function try_raw_hmac( $header_value, $raw_body, $signing_secret ) {
		$expected = hash_hmac( 'sha256', $raw_body, $signing_secret );

		$isValidRawSignature = hash_equals( $expected, $header_value );
		if ( ! $isValidRawSignature ) {
			return new WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
		}

		return true;
	}
}
