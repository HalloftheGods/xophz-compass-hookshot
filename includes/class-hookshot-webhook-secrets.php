<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Which webhook fields are secret, and how they cross the REST boundary.
 *
 * Secrets are write-only. Responses carry `has_<field>` booleans, never a value. A write with a
 * non-empty string replaces the stored value; an empty or missing one keeps it, because the
 * dashboard never receives the value and posts back "". Removing a secret takes an explicit
 * `clear` list. The one exception is a signing secret the server generates on create: the
 * create response shows it once and it is never returned again.
 */
class Hookshot_Webhook_Secrets {

	const WRITE_ONLY = [
		'signing_secret' => 'hookshot_signing_secret',
		'auth_value'     => 'hookshot_auth_value',
	];

	/**
	 * Deprecated URL token for POST xophz/v1/hookshot/incoming/{secret}. It is no longer issued or
	 * writable; existing tokens keep working until cleared or until the legacy route is retired.
	 */
	const LEGACY_URL_SECRET = 'hookshot_secret';

	/** Keys that may never be stored in, or returned from, hookshot_bridge_config. */
	const BRIDGE_SECRET_KEYS = [ 'github_token' ];

	public static function flags( $webhook_id ): array {
		$flags = [];
		foreach ( self::WRITE_ONLY as $field => $meta_key ) {
			$flags[ 'has_' . $field ] = self::is_set( $webhook_id, $meta_key );
		}
		$flags['has_secret'] = self::is_set( $webhook_id, self::LEGACY_URL_SECRET );

		return $flags;
	}

	public static function save( $webhook_id, array $params ) {
		foreach ( self::WRITE_ONLY as $field => $meta_key ) {
			$value = $params[ $field ] ?? '';
			$hasNewValue = is_string( $value ) && trim( $value ) !== '';
			if ( $hasNewValue ) {
				update_post_meta( $webhook_id, $meta_key, sanitize_text_field( $value ) );
			}
		}

		$clearable = self::WRITE_ONLY + [ 'secret' => self::LEGACY_URL_SECRET ];
		foreach ( (array) ( $params['clear'] ?? [] ) as $field ) {
			$meta_key = is_string( $field ) ? ( $clearable[ $field ] ?? '' ) : '';
			if ( $meta_key !== '' ) {
				delete_post_meta( $webhook_id, $meta_key );
			}
		}
	}

	/**
	 * Gives an incoming webhook without a signing secret a generated one. Returns the new value for
	 * the one-time display in the create response, or '' when a secret was already set.
	 */
	public static function issue_signing_secret( $webhook_id ): string {
		$meta_key = self::WRITE_ONLY['signing_secret'];
		if ( self::is_set( $webhook_id, $meta_key ) ) {
			return '';
		}

		$secret = Hookshot_Signature::generate_secret();
		update_post_meta( $webhook_id, $meta_key, $secret );

		return $secret;
	}

	public static function without_bridge_secrets( $bridge_config ) {
		if ( ! is_array( $bridge_config ) ) {
			return $bridge_config;
		}

		$secret_keys = array_flip( self::BRIDGE_SECRET_KEYS );
		foreach ( $bridge_config as $slug => $config ) {
			if ( is_array( $config ) ) {
				$bridge_config[ $slug ] = array_diff_key( $config, $secret_keys );
			}
		}

		return $bridge_config;
	}

	private static function is_set( $webhook_id, $meta_key ): bool {
		return (string) get_post_meta( $webhook_id, $meta_key, true ) !== '';
	}
}
