<?php

/**
 * Fired during plugin deactivation.
 *
 * @link       https://www.hallofthegods.com
 * @since      1.0.0
 * @package    Xophz_Compass_Hookshot
 * @subpackage Xophz_Compass_Hookshot/includes
 */

class Xophz_Compass_Hookshot_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

}
