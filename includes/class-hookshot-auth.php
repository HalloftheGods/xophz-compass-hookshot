<?php

class Hookshot_Auth {

	const TYPE_NONE = 'none';
	const TYPE_BEARER = 'bearer';
	const TYPE_BASIC = 'basic';
	const TYPE_API_KEY = 'api_key';

	const VALID_TYPES = [ self::TYPE_NONE, self::TYPE_BEARER, self::TYPE_BASIC, self::TYPE_API_KEY ];

	public static function build_headers( $webhook_id ) {
		$auth_type = get_post_meta( $webhook_id, 'hookshot_auth_type', true ) ?: self::TYPE_NONE;
		$auth_value = get_post_meta( $webhook_id, 'hookshot_auth_value', true );

		$isNone = $auth_type === self::TYPE_NONE || empty( $auth_value );
		if ( $isNone ) {
			return [];
		}

		switch ( $auth_type ) {
			case self::TYPE_BEARER:
				return [ 'Authorization' => "Bearer {$auth_value}" ];

			case self::TYPE_BASIC:
				return [ 'Authorization' => "Basic {$auth_value}" ];

			case self::TYPE_API_KEY:
				$header_name = get_post_meta( $webhook_id, 'hookshot_auth_header', true ) ?: 'X-API-Key';
				return [ $header_name => $auth_value ];

			default:
				return [];
		}
	}

	public static function get_types() {
		return [
			self::TYPE_NONE   => 'No Authentication',
			self::TYPE_BEARER => 'Bearer Token',
			self::TYPE_BASIC  => 'Basic Auth (Base64)',
			self::TYPE_API_KEY => 'API Key Header',
		];
	}
}
