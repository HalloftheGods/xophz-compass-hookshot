<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://www.hallofthegods.com
 * @since             1.0.0
 * @package           Xophz_Compass_Hookshot
 *
 * @wordpress-plugin
 * Category:          True North
 * Group:             ITSM
 * Plugin Name:       Xophz Magic Hookshot
 * Plugin URI:        https://github.com/HalloftheGods/xophz-compass-hookshot
 * Description:       Incoming and outgoing webhook management for the COMPASS ecosystem. Latch onto external APIs.
 * Version:           26.9.2
 * Author:            Hall of the Gods, Inc.
 * Author URI:        https://www.hallofthegods.com/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       xophz-compass-hookshot
 * Domain Path:       /languages
 * Update URI:        https://github.com/HalloftheGods/xophz-compass-hookshot
 * Color:             #A0AEC0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 */
define( 'XOPHZ_COMPASS_HOOKSHOT_VERSION', '26.9.2' );
define( 'XOPHZ_COMPASS_HOOKSHOT_DIR', plugin_dir_path( __FILE__ ) );

/**
 * The code that runs during plugin activation.
 */
function activate_xophz_compass_hookshot() {
	require_once XOPHZ_COMPASS_HOOKSHOT_DIR . 'includes/class-xophz-compass-hookshot-activator.php';
	Xophz_Compass_Hookshot_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 */
function deactivate_xophz_compass_hookshot() {
	flush_rewrite_rules();
}

register_activation_hook( __FILE__, 'activate_xophz_compass_hookshot' );
register_deactivation_hook( __FILE__, 'deactivate_xophz_compass_hookshot' );

/**
 * The core plugin class.
 */
require XOPHZ_COMPASS_HOOKSHOT_DIR . 'includes/class-xophz-compass-hookshot.php';

/**
 * Begins execution of the plugin.
 *
 * @since    1.0.0
 */
function run_xophz_compass_hookshot() {
	// Failsafe: Ensure core COMPASS is active
	if ( ! class_exists( 'Xophz_Compass' ) ) {
		add_action( 'admin_init', 'shutoff_xophz_compass_hookshot' );
		add_action( 'admin_notices', 'admin_notice_xophz_compass_hookshot' );

		function shutoff_xophz_compass_hookshot() {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}

		function admin_notice_xophz_compass_hookshot() {
			echo '<div class="error"><h2><strong>Xophz Hookshot</strong> requires Compass to run. It has self <strong>deactivated</strong>.</h2></div>';
			if ( isset( $_GET['activate'] ) ) {
				unset( $_GET['activate'] );
			}
		}
	} else {
		$plugin = new Xophz_Compass_Hookshot();
		$plugin->run();
	}
}

// Hook into plugins_loaded with a slightly later priority if needed, 
// but default 10 is usually fine if COMPASS loads first or we check class_exists inside the action.
add_action( 'plugins_loaded', 'run_xophz_compass_hookshot' );
