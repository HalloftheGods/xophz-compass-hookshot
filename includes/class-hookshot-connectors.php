<?php

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Hookshot credentials that live in the WordPress core Connectors API (Settings > Connectors).
 *
 * The GitHub Plugin Release bridge downloads private release assets with the `github_token`
 * connector. get_key() follows the same precedence as core's _wp_connectors_get_api_key_source():
 * environment variable, then PHP constant, then the database option, reading every name from the
 * connector's own `authentication` definition. Values are for server-side use only: never echo,
 * log, or return them from a REST route.
 */
class Hookshot_Connectors {

	const GITHUB_TOKEN = 'github_token';

	const MIGRATION_FLAG = 'hookshot_github_token_migrated';

	public static function init() {
		add_action( 'wp_connectors_init', [ __CLASS__, 'register' ] );
		add_action( 'init', [ __CLASS__, 'migrate_bridge_config_tokens' ], 20 );
	}

	public static function register( $registry ) {
		$canRegister = is_object( $registry ) && method_exists( $registry, 'register' );
		if ( ! $canRegister || wp_is_connector_registered( self::GITHUB_TOKEN ) ) {
			return;
		}

		$registry->register( self::GITHUB_TOKEN, [
			'name'           => __( 'GitHub Token', 'xophz-compass-hookshot' ),
			'description'    => __( 'GitHub access token used to download private release assets (Hookshot GitHub Plugin Release bridge).', 'xophz-compass-hookshot' ),
			'type'           => 'api_key',
			'authentication' => [
				'method'          => 'api_key',
				'setting_name'    => 'compass_github_token',
				'constant_name'   => 'GITHUB_TOKEN',
				'env_var_name'    => 'GITHUB_TOKEN',
				'credentials_url' => 'https://github.com/settings/personal-access-tokens',
			],
		] );
	}

	public static function get_key( string $connector_id ): string {
		$auth = self::authentication( $connector_id );

		$from_env = self::from_env( (string) ( $auth['env_var_name'] ?? '' ) );
		if ( $from_env !== '' ) {
			return $from_env;
		}

		$from_constant = self::from_constant( (string) ( $auth['constant_name'] ?? '' ) );
		if ( $from_constant !== '' ) {
			return $from_constant;
		}

		return self::from_option( (string) ( $auth['setting_name'] ?? '' ) );
	}

	public static function is_configured( string $connector_id ): bool {
		return self::get_key( $connector_id ) !== '';
	}

	/**
	 * One-time move of a per-webhook `github_token` out of `hookshot_bridge_config` post meta.
	 *
	 * The first non-empty token fills the connector's database option when the connector has no
	 * value yet; every copy is then deleted from post meta. Runs after core's connector init.
	 */
	public static function migrate_bridge_config_tokens() {
		$isDone = (bool) get_option( self::MIGRATION_FLAG, false );
		$hasConnectors = function_exists( 'wp_is_connector_registered' ) && wp_is_connector_registered( self::GITHUB_TOKEN );
		if ( $isDone || ! $hasConnectors ) {
			return;
		}

		$ids = get_posts( [
			'post_type'      => 'compass_webhook',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => 'hookshot_bridge_config',
		] );

		foreach ( $ids as $webhook_id ) {
			self::migrate_webhook( (int) $webhook_id );
		}

		update_option( self::MIGRATION_FLAG, 1, false );
	}

	private static function migrate_webhook( int $webhook_id ) {
		$config = get_post_meta( $webhook_id, 'hookshot_bridge_config', true );
		if ( ! is_array( $config ) ) {
			return;
		}

		$changed = false;
		foreach ( $config as $slug => $bridge_config ) {
			$hasToken = is_array( $bridge_config ) && array_key_exists( 'github_token', $bridge_config );
			if ( ! $hasToken ) {
				continue;
			}

			self::adopt_token( self::as_string( $bridge_config['github_token'] ) );
			unset( $config[ $slug ]['github_token'] );
			$changed = true;
		}

		if ( $changed ) {
			update_post_meta( $webhook_id, 'hookshot_bridge_config', $config );
		}
	}

	private static function adopt_token( string $token ) {
		$setting_name = (string) ( self::authentication( self::GITHUB_TOKEN )['setting_name'] ?? '' );
		$canAdopt = $token !== '' && $setting_name !== '' && ! self::is_configured( self::GITHUB_TOKEN );
		if ( $canAdopt ) {
			update_option( $setting_name, $token, false );
		}
	}

	private static function authentication( string $connector_id ): array {
		$isAvailable = function_exists( 'wp_get_connector' ) && function_exists( 'wp_is_connector_registered' );
		if ( ! $isAvailable || ! wp_is_connector_registered( $connector_id ) ) {
			return [];
		}

		$connector = wp_get_connector( $connector_id );

		return is_array( $connector ) ? (array) ( $connector['authentication'] ?? [] ) : [];
	}

	private static function from_env( string $env_var_name ): string {
		return $env_var_name === '' ? '' : self::as_string( getenv( $env_var_name ) );
	}

	private static function from_constant( string $constant_name ): string {
		$isDefined = $constant_name !== '' && defined( $constant_name );

		return $isDefined ? self::as_string( constant( $constant_name ) ) : '';
	}

	private static function from_option( string $setting_name ): string {
		return $setting_name === '' ? '' : self::as_string( get_option( $setting_name, '' ) );
	}

	private static function as_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
