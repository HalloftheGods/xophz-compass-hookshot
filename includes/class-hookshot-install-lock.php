<?php
/**
 * One release install per slug at a time, across every site of the network and every PHP process.
 *
 * The release bridge deduplicates with a transient, and a transient is stored per site. Two sites of
 * one network that both receive a repository's release events (doit.forthexp.com and
 * mycompassconsulting.com do) therefore installed the same release into the one shared plugins
 * directory at the same moment: each run deleted the directory the other was copying into, then
 * rolled back over the other's rollback. On 2026-10-26 that left xophz-compass-xp a half-copied
 * skeleton and seven sites fatal for two days.
 *
 * An exclusive flock() on a file under wp-content/hookshot is shared by every process on the host, so
 * the second run waits for the first and then sees the version it installed. The lock is released
 * when the object is destroyed, so every return path of the bridge releases it. The lock file is never
 * unlinked: a waiter that opened the old inode and a newcomer that created a new one would otherwise
 * both hold "the" lock. It cannot live in wp-content/upgrade: WP_Upgrader::unpack_package() empties
 * that directory on every run, which would unlink the lock while an install holds it.
 */
class Hookshot_Install_Lock {

	/** How long a run waits for another install of the same slug before it gives up. */
	const WAIT_SECONDS = 120;

	/** Pause between attempts while waiting. */
	const POLL_MICROSECONDS = 250000;

	/** @var resource|null */
	private $handle = null;

	/** @var string */
	private $path = '';

	private function __construct( $handle, $path ) {
		$this->handle = $handle;
		$this->path   = $path;
	}

	/**
	 * Holds the install lock for $slug, waiting up to $wait_seconds for a run that already holds it.
	 *
	 * @param string   $slug         Plugin, mu-plugin or theme directory name.
	 * @param int|null $wait_seconds Seconds to wait for a holder; null means WAIT_SECONDS, 0 means do not wait.
	 * @return Hookshot_Install_Lock|WP_Error The held lock, 'install_locked' when a holder outlasted the
	 *                                        wait, or 'install_lock_unavailable' when no lock file can be opened.
	 */
	public static function acquire( $slug, $wait_seconds = null ) {
		$wait_seconds = $wait_seconds === null ? self::WAIT_SECONDS : max( 0, (int) $wait_seconds );
		$path         = self::path_for( $slug );
		$dir          = self::work_dir();

		$handle = is_dir( $dir ) ? @fopen( $path, 'c' ) : false;
		if ( ! $handle ) {
			return new WP_Error(
				'install_lock_unavailable',
				"Cannot open the install lock file {$path}; refusing to install '{$slug}' without it."
			);
		}

		$deadline = microtime( true ) + $wait_seconds;
		do {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				return new self( $handle, $path );
			}
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			usleep( self::POLL_MICROSECONDS );
		} while ( true );

		fclose( $handle );
		return new WP_Error(
			'install_locked',
			"Another install of '{$slug}' is still running on this host after {$wait_seconds}s; this release event is left to it."
		);
	}

	/** The lock file for a slug: wp-content/hookshot/install-<slug>.lock. */
	public static function path_for( $slug ) {
		return self::work_dir( false ) . '/install-' . self::safe_slug( $slug ) . '.lock';
	}

	/** A directory name safe form of a slug. */
	public static function safe_slug( $slug ) {
		$safe = strtolower( preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $slug ) );
		return trim( $safe, '-.' ) ?: 'unnamed';
	}

	/**
	 * Hookshot's own scratch directory, wp-content/hookshot: locks and install stages live here because
	 * WordPress empties wp-content/upgrade on every upgrader run. Created on demand with an index.php.
	 *
	 * @param bool $create Create the directory when it is missing.
	 * @return string Path without a trailing slash.
	 */
	public static function work_dir( $create = true ) {
		$dir = rtrim( WP_CONTENT_DIR, '/\\' ) . '/hookshot';
		if ( $create && ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0755, true );
			}
			if ( is_dir( $dir ) && ! file_exists( $dir . '/index.php' ) ) {
				@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
		}
		return $dir;
	}

	public function path() {
		return $this->path;
	}

	public function release() {
		if ( is_resource( $this->handle ) ) {
			flock( $this->handle, LOCK_UN );
			fclose( $this->handle );
		}
		$this->handle = null;
	}

	public function __destruct() {
		$this->release();
	}
}
