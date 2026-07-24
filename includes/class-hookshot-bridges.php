<?php

class Hookshot_Bridges {

	private static $registered_bridges = [];

	public function init() {
		add_action( 'init', [ $this, 'register_default_bridges' ], 20 );
		add_action( 'xophz_hookshot_incoming', [ $this, 'route_incoming' ], 10, 2 );
	}

	public function register_default_bridges() {
		self::register( 'questbook_contact', [
			'name'        => 'Questbook CRM',
			'description' => 'Auto-create or update a Questbook contact from incoming payload.',
			'icon'        => 'fad fa-address-book',
			'category'    => 'CRM',
			'fields'      => [ 'email', 'name', 'phone', 'source' ],
			'handler'     => [ $this, 'bridge_questbook' ],
		] );

		self::register( 'bombbag_subscribe', [
			'name'        => 'Bomb Bag Subscribe',
			'description' => 'Auto-subscribe an email to a Bomb Bag list from incoming payload.',
			'icon'        => 'fad fa-envelope',
			'category'    => 'MA',
			'fields'      => [ 'email', 'first_name', 'last_name', 'list_id' ],
			'handler'     => [ $this, 'bridge_bombbag' ],
		] );

		self::register( 'xp_grant', [
			'name'        => 'XP Grant',
			'description' => 'Award XP to a user based on incoming payload.',
			'icon'        => 'fad fa-star',
			'category'    => 'LXP',
			'fields'      => [ 'user_id', 'xp_amount', 'reason' ],
			'handler'     => [ $this, 'bridge_xp' ],
		] );

		self::register( 'wp_action', [
			'name'        => 'Fire WP Action',
			'description' => 'Fire a custom WordPress action with the incoming payload.',
			'icon'        => 'fad fa-bolt',
			'category'    => 'ITSM',
			'fields'      => [ 'action_name' ],
			'handler'     => [ $this, 'bridge_wp_action' ],
		] );

		self::register( 'github_plugin_release', [
			'name'        => 'GitHub Plugin Release',
			'description' => 'Auto-update a plugin when a GitHub release is published.',
			'icon'        => 'fab fa-github',
			'category'    => 'DevOps',
			'fields'      => [ 'allowed_plugins' ], // Comma-separated list of allowed plugin slugs, or blank for all
			'handler'     => [ $this, 'bridge_github_plugin_release' ],
		] );

		do_action( 'xophz_hookshot_register_bridges' );
	}

	public static function register( $slug, $config ) {
		self::$registered_bridges[ $slug ] = $config;
	}

	public static function get_registered() {
		return self::$registered_bridges;
	}

	public function route_incoming( $payload, $webhook_id ) {
		$enabled_bridges = get_post_meta( $webhook_id, 'hookshot_bridges', true );

		$hasNoBridges = empty( $enabled_bridges ) || ! is_array( $enabled_bridges );
		if ( $hasNoBridges ) {
			return;
		}

		$bridge_config = get_post_meta( $webhook_id, 'hookshot_bridge_config', true ) ?: [];

		foreach ( $enabled_bridges as $bridge_slug ) {
			$isRegistered = isset( self::$registered_bridges[ $bridge_slug ] );
			if ( ! $isRegistered ) {
				continue;
			}

			$bridge = self::$registered_bridges[ $bridge_slug ];
			$config = $bridge_config[ $bridge_slug ] ?? [];

			$hasHandler = isset( $bridge['handler'] ) && is_callable( $bridge['handler'] );
			if ( $hasHandler ) {
				call_user_func( $bridge['handler'], $payload, $webhook_id, $config );
			}
		}
	}

	public function bridge_questbook( $payload, $webhook_id, $config ) {
		$hasNoCRM = ! class_exists( 'Xophz_Compass_Quests_REST' );
		if ( $hasNoCRM ) {
			return;
		}

		$email_field = $config['email_field'] ?? 'email';
		$name_field = $config['name_field'] ?? 'name';
		$phone_field = $config['phone_field'] ?? 'phone';

		$email = self::extract_field( $payload, $email_field );

		$hasNoEmail = empty( $email );
		if ( $hasNoEmail ) {
			return;
		}

		$name = self::extract_field( $payload, $name_field ) ?: '';
		$phone = self::extract_field( $payload, $phone_field ) ?: '';

		$existing = get_posts( [
			'post_type'      => 'questbook_contact',
			'posts_per_page' => 1,
			'meta_key'       => '_qb_raw_email',
			'meta_value'     => $email,
			'fields'         => 'ids',
		] );

		$contactExists = ! empty( $existing );
		if ( $contactExists ) {
			return;
		}

		$contact_id = wp_insert_post( [
			'post_title'  => $name ?: $email,
			'post_type'   => 'questbook_contact',
			'post_status' => 'publish',
		] );

		$isValid = ! is_wp_error( $contact_id );
		if ( $isValid ) {
			update_post_meta( $contact_id, '_qb_raw_email', sanitize_email( $email ) );
			update_post_meta( $contact_id, '_qb_lead_status', 'New Lead' );
			update_post_meta( $contact_id, '_qb_source', 'Hookshot Webhook #' . $webhook_id );

			$hasPhone = ! empty( $phone );
			if ( $hasPhone ) {
				update_post_meta( $contact_id, '_qb_phone', sanitize_text_field( $phone ) );
			}
		}
	}

	public function bridge_bombbag( $payload, $webhook_id, $config ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bomb_bag_subscribers';

		$tableExists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		if ( ! $tableExists ) {
			return;
		}

		$email_field = $config['email_field'] ?? 'email';
		$email = self::extract_field( $payload, $email_field );

		$hasNoEmail = empty( $email );
		if ( $hasNoEmail ) {
			return;
		}

		$first_name = self::extract_field( $payload, $config['first_name_field'] ?? 'first_name' ) ?: '';
		$last_name = self::extract_field( $payload, $config['last_name_field'] ?? 'last_name' ) ?: '';

		$alreadyExists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE email = %s",
			$email
		) );

		if ( $alreadyExists ) {
			return;
		}

		$wpdb->insert( $table, [
			'email'      => sanitize_email( $email ),
			'first_name' => sanitize_text_field( $first_name ),
			'last_name'  => sanitize_text_field( $last_name ),
			'status'     => 'active',
			'created_at' => current_time( 'mysql' ),
		] );

		$subscriber_id = $wpdb->insert_id;
		$list_id = $config['list_id'] ?? null;

		$hasListTarget = ! empty( $list_id ) && $subscriber_id;
		if ( $hasListTarget ) {
			$junction_table = $wpdb->prefix . 'bomb_bag_list_subscribers';
			$wpdb->insert( $junction_table, [
				'list_id'       => (int) $list_id,
				'subscriber_id' => $subscriber_id,
			] );
		}
	}

	public function bridge_xp( $payload, $webhook_id, $config ) {
		$user_field = $config['user_field'] ?? 'user_id';
		$xp_field = $config['xp_field'] ?? 'xp_amount';
		$reason_field = $config['reason_field'] ?? 'reason';

		$user_id = (int) self::extract_field( $payload, $user_field );
		$xp_amount = (int) self::extract_field( $payload, $xp_field );

		$isInvalidGrant = empty( $user_id ) || empty( $xp_amount );
		if ( $isInvalidGrant ) {
			return;
		}

		$reason = self::extract_field( $payload, $reason_field ) ?: 'Hookshot Webhook';

		do_action( 'xophz_xp_grant', $user_id, $xp_amount, $reason );
	}

	public function bridge_wp_action( $payload, $webhook_id, $config ) {
		$action_name = $config['action_name'] ?? '';

		$hasNoAction = empty( $action_name );
		if ( $hasNoAction ) {
			return;
		}

		$is_safe_action = preg_match( '/^[a-z0-9_]+$/i', $action_name );
		if ( ! $is_safe_action ) {
			return;
		}

		do_action( $action_name, $payload, $webhook_id );
	}

	public function bridge_github_plugin_release( $payload, $webhook_id, $config ) {
		// Only run on release published event
		$action = self::extract_field( $payload, 'action' );
		if ( $action !== 'published' && $action !== 'released' ) {
			return;
		}

		$repo_name = self::extract_field( $payload, 'repository.name' );
		if ( empty( $repo_name ) ) {
			return;
		}

		// Optional filter to only update specific plugins if configured
		$allowed_plugins = $config['allowed_plugins'] ?? '';
		if ( ! empty( $allowed_plugins ) ) {
			$allowed_list = array_map( 'trim', explode( ',', $allowed_plugins ) );
			if ( ! in_array( $repo_name, $allowed_list, true ) ) {
				return;
			}
		}

		$release = (object) self::extract_field( $payload, 'release' );
		if ( empty( $release ) ) {
			return;
		}

		$download_url = '';
		$assets = $release->assets ?? [];
		if ( ! empty( $assets ) ) {
			foreach ( $assets as $asset ) {
				$asset = (object) $asset;
				if ( substr( $asset->name ?? '', -4 ) === '.zip' ) {
					$download_url = $asset->browser_download_url;
					break;
				}
			}
		}

		if ( empty( $download_url ) ) {
			$download_url = $release->zipball_url ?? '';
		}

		if ( empty( $download_url ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';

		// Silent upgrader skin so it doesn't print HTML to the REST API request output
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		
		// Add rename filter
		$slug = $repo_name;
		$rename_filter = function( $source, $remote_source, $upgrader_obj, $hook_extra = null ) use ( $slug ) {
			global $wp_filesystem;
			$expected_dir = $slug;
			$source_dir = untrailingslashit( $source );
			
			if ( basename( $source_dir ) === $expected_dir ) {
				return $source;
			}
			
			$new_source = trailingslashit( $remote_source ) . $expected_dir;
			if ( $wp_filesystem->move( $source, $new_source ) ) {
				return trailingslashit( $new_source );
			}
			return $source;
		};
		
		add_filter( 'upgrader_source_selection', $rename_filter, 10, 4 );

		// Run installation. This actually upgrades it if it exists or installs it if it doesn't.
		$args = [
			'overwrite_package' => true,
		];
		$installed = $upgrader->install( $download_url, $args );
		
		remove_filter( 'upgrader_source_selection', $rename_filter, 10 );

		if ( ! is_wp_error( $installed ) && $installed ) {
			if ( ! function_exists( 'activate_plugin' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_file = $slug . '/' . $slug . '.php';
			activate_plugin( $plugin_file );
			
			// Optional: Clear update transients
			delete_site_transient( 'update_plugins' );
			delete_transient( 'xophz_gh_rel_' . md5( 'HalloftheGods/' . $slug ) );
		}
	}

	private static function extract_field( $payload, $field_path ) {
		$isNested = strpos( $field_path, '.' ) !== false;
		if ( ! $isNested ) {
			return $payload[ $field_path ] ?? null;
		}

		$segments = explode( '.', $field_path );
		$current = $payload;

		foreach ( $segments as $segment ) {
			$isAccessible = is_array( $current ) && isset( $current[ $segment ] );
			if ( ! $isAccessible ) {
				return null;
			}
			$current = $current[ $segment ];
		}

		return $current;
	}
}
