<?php

class Hookshot_Transform {

	const PRESETS = [
		'slack' => [
			'name'   => 'Slack Incoming Webhook',
			'schema' => [
				'text'       => '$.event',
				'username'   => 'COMPASS Hookshot',
				'icon_emoji' => ':hook:',
			],
		],
		'discord' => [
			'name'   => 'Discord Webhook',
			'schema' => [
				'content'  => '$.event',
				'username' => 'COMPASS Hookshot',
			],
		],
		'zapier' => [
			'name'   => 'Zapier Catch Hook',
			'schema' => [
				'event'     => '$.event',
				'timestamp' => '$.timestamp',
				'data'      => '$.args',
			],
		],
		'make' => [
			'name'   => 'Make (Integromat) Webhook',
			'schema' => [
				'trigger'   => '$.event',
				'occurred'  => '$.timestamp',
				'payload'   => '$.args',
			],
		],
	];

	public static function apply( $payload, $webhook_id ) {
		$transform_json = get_post_meta( $webhook_id, 'hookshot_transform', true );

		$hasNoTransform = empty( $transform_json );
		if ( $hasNoTransform ) {
			return $payload;
		}

		$transform_map = is_array( $transform_json ) ? $transform_json : json_decode( $transform_json, true );

		$isInvalidMap = ! is_array( $transform_map ) || empty( $transform_map );
		if ( $isInvalidMap ) {
			return $payload;
		}

		$transformed = [];

		foreach ( $transform_map as $target_key => $source_path ) {
			$isStaticValue = strpos( $source_path, '$.' ) !== 0;
			if ( $isStaticValue ) {
				$transformed[ $target_key ] = $source_path;
				continue;
			}

			$resolved = self::resolve_path( $payload, $source_path );
			$transformed[ $target_key ] = $resolved;
		}

		return $transformed;
	}

	public static function apply_incoming( $payload, $webhook_id ) {
		$transform_json = get_post_meta( $webhook_id, 'hookshot_incoming_transform', true );

		$hasNoTransform = empty( $transform_json );
		if ( $hasNoTransform ) {
			return $payload;
		}

		$transform_map = is_array( $transform_json ) ? $transform_json : json_decode( $transform_json, true );

		$isInvalidMap = ! is_array( $transform_map ) || empty( $transform_map );
		if ( $isInvalidMap ) {
			return $payload;
		}

		$transformed = [];

		foreach ( $transform_map as $target_key => $source_path ) {
			$isStaticValue = strpos( $source_path, '$.' ) !== 0;
			if ( $isStaticValue ) {
				$transformed[ $target_key ] = $source_path;
				continue;
			}

			$transformed[ $target_key ] = self::resolve_path( $payload, $source_path );
		}

		return $transformed;
	}

	public static function get_presets() {
		return apply_filters( 'xophz_hookshot_transform_presets', self::PRESETS );
	}

	public static function preview( $payload, $transform_map ) {
		$isInvalidInput = ! is_array( $payload ) || ! is_array( $transform_map );
		if ( $isInvalidInput ) {
			return $payload;
		}

		$result = [];

		foreach ( $transform_map as $target_key => $source_path ) {
			$isStaticValue = strpos( $source_path, '$.' ) !== 0;
			if ( $isStaticValue ) {
				$result[ $target_key ] = $source_path;
				continue;
			}

			$result[ $target_key ] = self::resolve_path( $payload, $source_path );
		}

		return $result;
	}

	private static function resolve_path( $data, $path ) {
		$segments = explode( '.', substr( $path, 2 ) );
		$current = $data;

		foreach ( $segments as $segment ) {
			$isArrayAccess = is_array( $current ) && isset( $current[ $segment ] );
			$isObjectAccess = is_object( $current ) && isset( $current->$segment );

			if ( $isArrayAccess ) {
				$current = $current[ $segment ];
			} elseif ( $isObjectAccess ) {
				$current = $current->$segment;
			} else {
				return null;
			}
		}

		return $current;
	}
}
