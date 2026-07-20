<?php

/**
 * Fired during plugin activation.
 *
 * @link       https://www.hallofthegods.com
 * @since      1.0.0
 * @package    Xophz_Compass_Hookshot
 * @subpackage Xophz_Compass_Hookshot/includes
 */

class Xophz_Compass_Hookshot_Activator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		// Ensure COMPASS is installed.
		if ( ! class_exists( 'Xophz_Compass' ) ) {
			deactivate_plugins( plugin_basename( dirname( __FILE__, 2 ) . '/xophz-compass-hookshot.php' ) );
			wp_die( __( 'Xophz Hookshot requires Xophz COMPASS to be installed and active.', 'xophz-compass-hookshot' ), 'Plugin Dependency Error', [ 'back_link' => true ] );
		}

		// Flush rewrite rules for custom post types
		flush_rewrite_rules();
	}

}
