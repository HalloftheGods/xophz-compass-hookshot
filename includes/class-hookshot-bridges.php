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
			'fields'      => [ 'allowed_plugins', 'github_token' ],
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
			return [];
		}

		$bridge_config = get_post_meta( $webhook_id, 'hookshot_bridge_config', true ) ?: [];
		$results = [];

		foreach ( $enabled_bridges as $bridge_slug ) {
			$isRegistered = isset( self::$registered_bridges[ $bridge_slug ] );
			if ( ! $isRegistered ) {
				$results[ $bridge_slug ] = [ 'status' => 'skipped', 'details' => 'Bridge not registered.' ];
				continue;
			}

			$bridge = self::$registered_bridges[ $bridge_slug ];
			$config = $bridge_config[ $bridge_slug ] ?? [];

			$hasHandler = isset( $bridge['handler'] ) && is_callable( $bridge['handler'] );
			if ( $hasHandler ) {
				try {
					$res = call_user_func( $bridge['handler'], $payload, $webhook_id, $config );
					$results[ $bridge_slug ] = is_array( $res ) ? $res : [ 'status' => 'success', 'details' => 'Executed successfully.' ];
				} catch ( Throwable $e ) {
					$err_details = sprintf( "Bridge [%s] Error: %s in %s:%d", $bridge_slug, $e->getMessage(), $e->getFile(), $e->getLine() );
					error_log( $err_details );
					Hookshot_Notifier::notify_failure( $webhook_id, $err_details, 'Automated Bridge Engine' );
					$results[ $bridge_slug ] = [ 'status' => 'error', 'details' => $e->getMessage() ];
				}
			}
		}

		global $hookshot_last_bridge_results;
		$hookshot_last_bridge_results = $results;

		return $results;
	}

	public function bridge_questbook( $payload, $webhook_id, $config ) {
		$hasNoCRM = ! class_exists( 'Xophz_Compass_Quests_REST' );
		if ( $hasNoCRM ) {
			return [ 'status' => 'skipped', 'details' => 'Questbook CRM not installed.' ];
		}

		$email_field = $config['email_field'] ?? 'email';
		$name_field = $config['name_field'] ?? 'name';
		$phone_field = $config['phone_field'] ?? 'phone';

		$email = self::extract_field( $payload, $email_field );

		$hasNoEmail = empty( $email );
		if ( $hasNoEmail ) {
			return [ 'status' => 'skipped', 'details' => 'No email field found in payload.' ];
		}

		$name = self::extract_field( $payload, $name_field ) ?: '';
		$phone = self::extract_field( $payload, $phone_field ) ?: '';

		$api = new Xophz_Compass_Quests_REST();

		$existing = get_posts( [
			'post_type'      => 'compass_contact',
			'posts_per_page' => 1,
			'meta_key'       => 'contact_email',
			'meta_value'     => $email,
		] );

		if ( ! empty( $existing ) ) {
			$contact_id = $existing[0]->ID;
			if ( $name ) {
				update_post_meta( $contact_id, 'contact_name', sanitize_text_field( $name ) );
			}
			if ( $phone ) {
				update_post_meta( $contact_id, 'contact_phone', sanitize_text_field( $phone ) );
			}
			return [ 'status' => 'success', 'details' => "Updated Questbook contact ID {$contact_id} for {$email}." ];
		}

		$contact_id = wp_insert_post( [
			'post_title'  => $name ?: $email,
			'post_type'   => 'compass_contact',
			'post_status' => 'publish',
		] );

		$isValid = ! is_wp_error( $contact_id );
		if ( $isValid ) {
			update_post_meta( $contact_id, 'contact_email', sanitize_email( $email ) );
			update_post_meta( $contact_id, 'contact_name', sanitize_text_field( $name ) );
			update_post_meta( $contact_id, 'contact_phone', sanitize_text_field( $phone ) );
			update_post_meta( $contact_id, 'contact_source', 'hookshot_webhook' );
			return [ 'status' => 'success', 'details' => "Created Questbook contact ID {$contact_id} for {$email}." ];
		}

		return [ 'status' => 'error', 'details' => 'Failed to create Questbook contact post.' ];
	}

	public function bridge_bombbag( $payload, $webhook_id, $config ) {
		global $wpdb;

		$table = $wpdb->prefix . 'bomb_bag_subscribers';

		$tableExists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
		if ( ! $tableExists ) {
			return [ 'status' => 'skipped', 'details' => 'Bomb Bag subscribers table does not exist.' ];
		}

		$email_field = $config['email_field'] ?? 'email';
		$email = self::extract_field( $payload, $email_field );

		$hasNoEmail = empty( $email );
		if ( $hasNoEmail ) {
			return [ 'status' => 'skipped', 'details' => 'No email field found in payload.' ];
		}

		$first_name = self::extract_field( $payload, $config['first_name_field'] ?? 'first_name' ) ?: '';
		$last_name = self::extract_field( $payload, $config['last_name_field'] ?? 'last_name' ) ?: '';

		$alreadyExists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE email = %s",
			$email
		) );

		if ( $alreadyExists ) {
			return [ 'status' => 'skipped', 'details' => "Email {$email} is already subscribed." ];
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

		return [ 'status' => 'success', 'details' => "Subscribed {$email} to Bomb Bag (Subscriber ID: {$subscriber_id})." ];
	}

	public function bridge_xp( $payload, $webhook_id, $config ) {
		$user_field = $config['user_field'] ?? 'user_id';
		$xp_field = $config['xp_field'] ?? 'xp_amount';
		$reason_field = $config['reason_field'] ?? 'reason';

		$user_id = (int) self::extract_field( $payload, $user_field );
		$xp_amount = (int) self::extract_field( $payload, $xp_field );

		$isInvalidGrant = empty( $user_id ) || empty( $xp_amount );
		if ( $isInvalidGrant ) {
			return [ 'status' => 'skipped', 'details' => 'Invalid user_id or xp_amount in payload.' ];
		}

		$reason = self::extract_field( $payload, $reason_field ) ?: 'Hookshot Webhook';

		do_action( 'xophz_xp_grant', $user_id, $xp_amount, $reason );
		return [ 'status' => 'success', 'details' => "Granted {$xp_amount} XP to User ID {$user_id} ({$reason})." ];
	}

	public function bridge_wp_action( $payload, $webhook_id, $config ) {
		$action_name = $config['action_name'] ?? '';

		$hasNoAction = empty( $action_name );
		if ( $hasNoAction ) {
			return [ 'status' => 'skipped', 'details' => 'No action_name configured.' ];
		}

		$is_safe_action = preg_match( '/^[a-z0-9_]+$/i', $action_name );
		if ( ! $is_safe_action ) {
			return [ 'status' => 'error', 'details' => "Unsafe action_name '{$action_name}'." ];
		}

		do_action( $action_name, $payload, $webhook_id );
		return [ 'status' => 'success', 'details' => "Fired WP Action '{$action_name}'." ];
	}

	public function bridge_github_plugin_release( $payload, $webhook_id, $config ) {
		// Only run on release published event
		$action = self::extract_field( $payload, 'action' );
		if ( $action !== 'published' && $action !== 'released' ) {
			return [
				'status'  => 'skipped',
				'details' => "Ignored action '{$action}'. Only 'published' or 'released' triggers updates.",
			];
		}

		$repo_name = self::extract_field( $payload, 'repository.name' );
		if ( empty( $repo_name ) ) {
			return [
				'status'  => 'skipped',
				'details' => 'Missing repository name in payload.',
			];
		}

		$slug = strtolower( $repo_name );

		// Optional filter to only update specific plugins if configured
		$allowed_plugins = $config['allowed_plugins'] ?? '';
		if ( ! empty( $allowed_plugins ) ) {
			$allowed_list = array_map( 'trim', explode( ',', $allowed_plugins ) );
			if ( ! in_array( $repo_name, $allowed_list, true ) && ! in_array( $slug, $allowed_list, true ) ) {
				return [
					'status'  => 'skipped',
					'details' => "Repository '{$repo_name}' is not in allowed plugins list ({$allowed_plugins}).",
					'slug'    => $slug,
				];
			}
		}
		
		$is_private = self::extract_field( $payload, 'repository.private' );

		$release = (object) self::extract_field( $payload, 'release' );
		if ( empty( $release ) ) {
			return [
				'status'  => 'skipped',
				'details' => 'Missing release object in payload.',
				'slug'    => $slug,
			];
		}

		$release_tag = $release->tag_name ?? $release->name ?? 'unknown';

		$github_token = $config['github_token'] ?? '';
		if ( empty( $github_token ) ) {
			if ( defined( 'GITHUB_TOKEN' ) ) {
				$github_token = GITHUB_TOKEN;
			} elseif ( defined( 'GITHUB_PA_TOKEN' ) ) {
				$github_token = GITHUB_PA_TOKEN;
			} else {
				$github_token = get_option( 'xophz_compass_bugnet_github_token', '' );
			}
		}

		if ( $is_private && empty( $github_token ) ) {
			return [
				'status'  => 'error',
				'details' => "Repository '{$repo_name}' is private, but no GitHub Access Token is configured in Hookshot or COMPASS settings.",
				'slug'    => $slug,
			];
		}

		$download_url = '';
		$assets = $release->assets ?? [];
		if ( ! empty( $assets ) ) {
			foreach ( $assets as $asset ) {
				$asset = (object) $asset;
				if ( substr( $asset->name ?? '', -4 ) === '.zip' ) {
					// Use API asset URL for private repos with auth token; browser_download_url for public repos
					$download_url = ( $is_private && ! empty( $github_token ) ) ? $asset->url : $asset->browser_download_url;
					break;
				}
			}
		}

		// Strict check: Only proceed if official packaged release zip asset exists
		if ( empty( $download_url ) ) {
			return [
				'status'      => 'skipped',
				'details'     => "No release ZIP asset found for repository '{$repo_name}'.",
				'slug'        => $slug,
				'release_tag' => $release_tag,
			];
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		if ( ! function_exists( 'is_plugin_active' ) || ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-plugin-upgrader.php';
		}
		if ( file_exists( ABSPATH . 'wp-admin/includes/class-theme-upgrader.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';
		}

		WP_Filesystem();
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			$wp_filesystem = new WP_Filesystem_Direct( null );
		}

		// Detect whether target release is a WordPress theme or plugin
		$theme_root = function_exists( 'get_theme_root' ) ? get_theme_root() : WP_CONTENT_DIR . '/themes';
		$is_theme   = file_exists( $theme_root . '/' . $slug . '/style.css' )
		           || ( strpos( $slug, 'theme' ) !== false )
		           || ( strpos( $slug, '-wp-' ) !== false && ! file_exists( WP_PLUGIN_DIR . '/' . $slug ) );

		$base_dir_path   = $is_theme ? $theme_root : WP_PLUGIN_DIR;
		$target_dir_path = $base_dir_path . '/' . $slug;

		$plugin_file_path = $target_dir_path . '/' . $slug . '.php';
		$plugin_file      = $slug . '/' . $slug . '.php';
		$style_css_path   = $target_dir_path . '/style.css';

		// Extract old version before backup/update
		$old_version = 'not installed';
		if ( $is_theme ) {
			if ( function_exists( 'wp_get_theme' ) ) {
				$theme_obj = wp_get_theme( $slug );
				if ( $theme_obj->exists() ) {
					$old_version = $theme_obj->get( 'Version' ) ?: 'unknown';
				}
			}
			$was_active = function_exists( 'wp_get_theme' ) && wp_get_theme()->get_stylesheet() === $slug;
		} else {
			if ( file_exists( $plugin_file_path ) ) {
				$old_data    = get_plugin_data( $plugin_file_path, false, false );
				$old_version = ! empty( $old_data['Version'] ) ? $old_data['Version'] : 'unknown';
			}
			$was_active = is_plugin_active( $plugin_file );
		}

		// Clean up any stale backup directories from previous runs
		$stale_backups = glob( $base_dir_path . '/' . $slug . '_hookshot_backup_*' );
		if ( is_array( $stale_backups ) ) {
			foreach ( $stale_backups as $stale_dir ) {
				if ( $wp_filesystem->is_dir( $stale_dir ) ) {
					$wp_filesystem->delete( $stale_dir, true );
				}
			}
		}

		// Silent upgrader skin so it doesn't print HTML to the REST API request output
		if ( class_exists( 'Automatic_Upgrader_Skin' ) ) {
			$skin = new Automatic_Upgrader_Skin();
		} elseif ( class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			$skin = new WP_Ajax_Upgrader_Skin();
		} else {
			$skin = new WP_Upgrader_Skin();
		}
		
		$upgrader = $is_theme ? new Theme_Upgrader( $skin ) : new Plugin_Upgrader( $skin );

		// Set up GitHub authentication ONLY for private repo downloads (stripping on S3 redirects)
		$auth_filter = function( $args, $url ) use ( $github_token, $is_private ) {
			if ( $is_private && ! empty( $github_token ) ) {
				if ( strpos( $url, 'api.github.com' ) !== false ) {
					$args['headers']['Authorization'] = 'token ' . $github_token;
					$args['headers']['Accept']        = 'application/octet-stream';
				} else {
					unset( $args['headers']['Authorization'] );
				}
			}
			return $args;
		};

		add_filter( 'http_request_args', $auth_filter, 10, 2 );

		// Add rename filter to normalize unzipped folder name to target slug
		$rename_filter = function( $source, $remote_source, $upgrader_obj, $hook_extra = null ) use ( $slug ) {
			global $wp_filesystem;
			if ( is_wp_error( $source ) ) {
				return $source;
			}
			if ( ! is_object( $wp_filesystem ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
				$wp_filesystem = new WP_Filesystem_Direct( null );
			}
			$expected_dir = $slug;
			$source_dir   = untrailingslashit( $source );
			$source_base  = basename( $source_dir );
			
			if ( $source_base === $expected_dir ) {
				return $source;
			}
			
			$remote_dir = untrailingslashit( $remote_source );
			$new_source = $remote_dir . '/' . $expected_dir;

			if ( $wp_filesystem->is_dir( $new_source ) ) {
				$wp_filesystem->delete( $new_source, true );
			}
			if ( $wp_filesystem->move( $source, $new_source ) ) {
				return trailingslashit( $new_source );
			}
			if ( function_exists( 'copy_dir' ) ) {
				$copy_res = copy_dir( $source, $new_source );
				if ( ! is_wp_error( $copy_res ) && $copy_res !== false ) {
					$wp_filesystem->delete( $source, true );
					return trailingslashit( $new_source );
				}
			}
			return $source;
		};
		
		add_filter( 'upgrader_source_selection', $rename_filter, 10, 4 );

		// Create backup of existing item
		$backup_dir_path = $base_dir_path . '/' . $slug . '_hookshot_backup_' . time();
		$backup_created  = false;
		
		if ( $wp_filesystem->is_dir( $target_dir_path ) ) {
			$res            = copy_dir( $target_dir_path, $backup_dir_path );
			$backup_created = ! is_wp_error( $res ) && $res !== false;
			
			if ( ! $backup_created ) {
				remove_filter( 'upgrader_source_selection', $rename_filter, 10 );
				remove_filter( 'http_request_args', $auth_filter, 10 );
				return [
					'status'       => 'error',
					'details'      => 'Failed to create backup of existing package. Aborting update to prevent data loss.',
					'slug'         => $slug,
					'old_version'  => $old_version,
					'target_tag'   => $release_tag,
				];
			}
		}

		// Check if performing a self-update of Hookshot plugin itself
		$is_self_update = ! $is_theme && ( $slug === 'xophz-compass-hookshot' || strpos( __FILE__, '/' . $slug . '/' ) !== false );

		if ( $is_self_update && $was_active ) {
			register_shutdown_function( function() use ( $plugin_file ) {
				$active_plugins = (array) get_option( 'active_plugins', [] );
				if ( ! in_array( $plugin_file, $active_plugins, true ) ) {
					$active_plugins[] = $plugin_file;
					update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );
				}
			} );
		}

		// Clean target directory before install if backup exists (skip for self-update to avoid deleting executing script)
		if ( ! $is_self_update && $backup_created && $wp_filesystem->is_dir( $target_dir_path ) ) {
			$wp_filesystem->delete( $target_dir_path, true );
		}

		// Run installation.
		$args = [
			'overwrite_package' => true,
		];
		$installed = $upgrader->install( $download_url, $args );
		
		remove_filter( 'upgrader_source_selection', $rename_filter, 10 );
		remove_filter( 'http_request_args', $auth_filter, 10 );

		// Check entry point existence after extraction
		if ( $is_theme ) {
			$item_missing = ! $wp_filesystem->is_file( $style_css_path );
		} else {
			if ( ! $wp_filesystem->is_file( $plugin_file_path ) && $wp_filesystem->is_dir( $target_dir_path ) ) {
				$php_files = glob( $target_dir_path . '/*.php' );
				if ( is_array( $php_files ) ) {
					foreach ( $php_files as $file ) {
						$data = get_plugin_data( $file, false, false );
						if ( ! empty( $data['Name'] ) ) {
							$plugin_file_path = $file;
							$plugin_file      = $slug . '/' . basename( $file );
							break;
						}
					}
				}
			}
			$item_missing = ! $wp_filesystem->is_file( $plugin_file_path );
		}

		$install_failed = is_wp_error( $installed ) || ! $installed;

		$rollback_performed = false;
		if ( $backup_created ) {
			if ( $install_failed || $item_missing ) {
				// Rollback
				if ( $wp_filesystem->is_dir( $target_dir_path ) ) {
					$wp_filesystem->delete( $target_dir_path, true );
				}
				$wp_filesystem->move( $backup_dir_path, $target_dir_path );
				$installed          = false;
				$item_missing       = false;
				$rollback_performed = true;
			} else {
				// Delete backup on success
				$wp_filesystem->delete( $backup_dir_path, true );
			}
		}

		if ( ! is_wp_error( $installed ) && $installed && ! $item_missing ) {
			if ( $was_active ) {
				if ( $is_theme && function_exists( 'switch_theme' ) ) {
					switch_theme( $slug );
				} elseif ( function_exists( 'activate_plugin' ) ) {
					activate_plugin( $plugin_file );
					// Guarantee persistence in active_plugins WP option
					$active_plugins = (array) get_option( 'active_plugins', [] );
					if ( ! in_array( $plugin_file, $active_plugins, true ) ) {
						$active_plugins[] = $plugin_file;
						update_option( 'active_plugins', array_values( array_unique( $active_plugins ) ) );
					}
				}
			}

			// Extract new version
			$new_version = 'unknown';
			if ( $is_theme ) {
				if ( function_exists( 'wp_get_theme' ) ) {
					$theme_obj = wp_get_theme( $slug );
					if ( $theme_obj->exists() ) {
						$new_version = $theme_obj->get( 'Version' ) ?: $release_tag;
					}
				}
			} else {
				if ( file_exists( $plugin_file_path ) ) {
					$new_data    = get_plugin_data( $plugin_file_path, false, false );
					$new_version = ! empty( $new_data['Version'] ) ? $new_data['Version'] : $release_tag;
				}
			}
			
			// Clear update transients
			delete_site_transient( $is_theme ? 'update_themes' : 'update_plugins' );
			delete_transient( 'xophz_gh_rel_' . md5( 'HalloftheGods/' . $slug ) );

			$item_type = $is_theme ? 'Theme' : 'Plugin';

			return [
				'status'         => 'success',
				'details'        => "{$item_type} '{$slug}' updated successfully: v{$old_version} -> v{$new_version} (Release {$release_tag}).",
				'slug'           => $slug,
				'item_type'      => $is_theme ? 'theme' : 'plugin',
				'old_version'    => $old_version,
				'new_version'    => $new_version,
				'upgrade_path'   => "v{$old_version} -> v{$new_version}",
				'release_tag'    => $release_tag,
				'was_active'     => $was_active,
				'is_active'      => $is_theme ? ( wp_get_theme()->get_stylesheet() === $slug ) : is_plugin_active( $plugin_file ),
				'backups_clean'  => true,
			];
		}

		// Gather detailed diagnostic error message
		$diag_errors = [];
		if ( is_wp_error( $installed ) ) {
			$diag_errors[] = $installed->get_error_message();
		}
		if ( is_object( $skin ) && method_exists( $skin, 'get_errors' ) ) {
			$skin_errors = $skin->get_errors();
			if ( is_wp_error( $skin_errors ) && $skin_errors->has_errors() ) {
				$diag_errors[] = implode( ' | ', $skin_errors->get_error_messages() );
			}
		}
		if ( $item_missing ) {
			$missing_target = $is_theme ? 'style.css' : $plugin_file;
			$diag_errors[]  = "Main " . ( $is_theme ? 'theme' : 'plugin' ) . " file missing at '{$missing_target}' after extraction.";
		}
		if ( empty( $diag_errors ) ) {
			$diag_errors[] = 'Package upgrade failed during ZIP installation.';
		}
		$err_msg = implode( ' ; ', array_unique( $diag_errors ) );

		return [
			'status'             => 'error',
			'details'            => $err_msg,
			'slug'               => $slug,
			'item_type'          => $is_theme ? 'theme' : 'plugin',
			'old_version'        => $old_version,
			'target_tag'         => $release_tag,
			'rollback_performed' => $rollback_performed,
		];
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
