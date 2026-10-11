<?php

class Hookshot_Signature {

	const DEFAULT_HEADER = 'X-Hookshot-Signature';

	/** GitHub's signature header, accepted when the configured header is absent. */
	const GITHUB_HEADER = 'x_hub_signature_256';

	const TIMESTAMP_TOLERANCE = 300;

	/** Webhook ids whose request body passed an HMAC check against a signing secret in this request. */
	private static $verified = [];

	/**
	 * True only when verify() matched this request's signature to the webhook's signing secret.
	 * A webhook with no signing secret passes verify() unsigned, and that is not "verified", so
	 * payload fields that change behavior (force, redeploy, allow_prereleases) are not honored for it.
	 */
	public static function was_verified( $webhook_id ) {
		return ! empty( self::$verified[ (int) $webhook_id ] );
	}

	public static function sign( $payload_body, $signing_secret ) {
		$timestamp = time();
		$signature = hash_hmac( 'sha256', "{$timestamp}.{$payload_body}", $signing_secret );

		return "t={$timestamp},v1={$signature}";
	}

	public static function verify( WP_REST_Request $request, $webhook_id ) {
		unset( self::$verified[ (int) $webhook_id ] );

		$signing_secret = get_post_meta( $webhook_id, 'hookshot_signing_secret', true );

		$hasNoSecret = empty( $signing_secret );
		if ( $hasNoSecret ) {
			return true;
		}

		$header_name = get_post_meta( $webhook_id, 'hookshot_sig_header', true ) ?: self::DEFAULT_HEADER;
		$header_value = $request->get_header( strtolower( str_replace( '-', '_', $header_name ) ) ) ?: $request->get_header( self::GITHUB_HEADER );

		$hasNoHeader = empty( $header_value );
		if ( $hasNoHeader ) {
			return new WP_Error( 'missing_signature', 'Missing webhook signature header.', [ 'status' => 401 ] );
		}

		$raw_body = $request->get_body();

		$result = self::validate_stripe_format( $header_value, $raw_body, $signing_secret );

		if ( true === $result ) {
			self::$verified[ (int) $webhook_id ] = true;
		}

		return $result;
	}

	/**
	 * Header HMAC authentication for the signed receive endpoint: unlike verify(), a webhook
	 * with no signing secret is refused, so the request is never trusted on its URL alone.
	 */
	public static function verify_required( WP_REST_Request $request, $webhook_id ) {
		$signing_secret = get_post_meta( $webhook_id, 'hookshot_signing_secret', true );

		$hasNoSecret = empty( $signing_secret );
		if ( $hasNoSecret ) {
			return new WP_Error( 'signature_not_configured', 'Webhook has no signing secret.', [ 'status' => 401 ] );
		}

		return self::verify( $request, $webhook_id );
	}

	public static function generate_secret() {
		return wp_generate_password( 40, false );
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
		// Handle GitHub style sha256= prefixes
		if ( strpos( $header_value, 'sha256=' ) === 0 ) {
			$header_value = substr( $header_value, 7 );
		} elseif ( strpos( $header_value, 'sha1=' ) === 0 ) {
			$expected = hash_hmac( 'sha1', $raw_body, $signing_secret );
			$isValidRawSignature = hash_equals( $expected, substr( $header_value, 5 ) );
			if ( ! $isValidRawSignature ) {
				return new WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
			}
			return true;
		}

		$expected = hash_hmac( 'sha256', $raw_body, $signing_secret );

		$isValidRawSignature = hash_equals( $expected, $header_value );
		if ( ! $isValidRawSignature ) {
			return new WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
		}

		return true;
	}
}
