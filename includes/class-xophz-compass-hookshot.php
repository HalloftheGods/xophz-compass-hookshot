<?php

class Xophz_Compass_Hookshot {

	protected $plugin_name;
	protected $version;

	public function __construct() {
		$this->version = defined( 'XOPHZ_COMPASS_HOOKSHOT_VERSION' ) ? XOPHZ_COMPASS_HOOKSHOT_VERSION : '1.0.0';
		$this->plugin_name = 'xophz-compass-hookshot';

		$this->load_dependencies();
	}

	private function load_dependencies() {
		$dir = XOPHZ_COMPASS_HOOKSHOT_DIR . 'includes/';

		require_once $dir . 'class-xophz-compass-hookshot-cpt.php';
		require_once $dir . 'class-xophz-compass-hookshot-rest.php';
		require_once $dir . 'class-hookshot-signature.php';
		require_once $dir . 'class-hookshot-auth.php';
		require_once $dir . 'class-hookshot-transform.php';
		require_once $dir . 'class-hookshot-health.php';
		require_once $dir . 'class-hookshot-retry.php';
		require_once $dir . 'class-hookshot-notifier.php';
		require_once $dir . 'class-hookshot-bridges.php';
		require_once $dir . 'class-hookshot-rest-dashboard.php';
		require_once $dir . 'class-hookshot-gc.php';
		require_once $dir . 'class-xophz-compass-hookshot-sender.php';

		require_once XOPHZ_COMPASS_HOOKSHOT_DIR . 'admin/class-xophz-compass-hookshot-admin.php';
	}

	public function run() {
		$cpt = new Xophz_Compass_Hookshot_CPT();
		$cpt->init();

		$rest = new Xophz_Compass_Hookshot_REST();
		$rest->init();

		$sender = new Xophz_Compass_Hookshot_Sender();
		$sender->init();

		$bridges = new Hookshot_Bridges();
		$bridges->init();

		$dashboard_rest = new Hookshot_REST_Dashboard();
		$dashboard_rest->init();

		Hookshot_Retry::get_instance()->init();
		Hookshot_Notifier::init();

		$gc = new Hookshot_GC();
		$gc->init();

		add_filter( 'cron_schedules', [ 'Hookshot_Retry', 'register_cron_interval' ] );

		if ( is_admin() ) {
			$admin = new Xophz_Compass_Hookshot_Admin( $this->plugin_name, $this->version );
			$admin->init();
		}
	}

	public function get_plugin_name() {
		return $this->plugin_name;
	}

	public function get_version() {
		return $this->version;
	}
}

