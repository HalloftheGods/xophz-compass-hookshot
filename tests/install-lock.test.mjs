/**
 * Hookshot_Install_Lock: one release install per slug at a time, across every site of a network and
 * every PHP process on the host.
 *
 * Two sites of one network that both receive a repository's release events used to install the same
 * release into the one shared plugins directory at the same moment: each run deleted the directory
 * the other was copying into, then rolled back over the other's rollback. On 2026-10-26 that left
 * xophz-compass-xp a half-copied skeleton and seven sites fatal for two days. The per-site transient
 * could not see the other site; an flock() on a file under wp-content/hookshot can (not under
 * wp-content/upgrade, which WP_Upgrader::unpack_package() empties on every run).
 */

import { describe, it, assert } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const LOCK_CLASS = '/var/www/html/wp-content/plugins/xophz-compass-hookshot/includes/class-hookshot-install-lock.php';

/** Run a snippet with WP_CONTENT_DIR set by `contentDir` (a PHP expression) and the lock class loaded. */
function php(code, contentDir = "sys_get_temp_dir() . '/hookshot-lock-' . getmypid() . '-' . mt_rand()") {
  return runPhpJson(`
    define( 'WP_CONTENT_DIR', ${contentDir} );
    require_once '${LOCK_CLASS}';
    ${code}
  `);
}

describe('Hookshot install lock', () => {
  it('refuses a second acquire for the same slug while the first is held, and grants it once released', () => {
    const res = php(`
      $first  = Hookshot_Install_Lock::acquire( 'xophz-compass-xp' );
      $second = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 0 );
      $other  = Hookshot_Install_Lock::acquire( 'xophz-compass-gatekeeper', 0 );
      $first->release();
      $third  = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 0 );
      echo json_encode([
        'first'  => $first instanceof Hookshot_Install_Lock,
        'second' => is_wp_error( $second ) ? $second->get_error_code() : 'held',
        'other'  => $other instanceof Hookshot_Install_Lock,
        'third'  => $third instanceof Hookshot_Install_Lock,
        'file'   => is_file( WP_CONTENT_DIR . '/hookshot/install-xophz-compass-xp.lock' ),
        'index'  => is_file( WP_CONTENT_DIR . '/hookshot/index.php' ),
      ]);
    `);
    assert.strictEqual(res.first, true);
    assert.strictEqual(res.second, 'install_locked');
    assert.strictEqual(res.other, true, 'a different slug has its own lock');
    assert.strictEqual(res.third, true);
    assert.strictEqual(res.file, true, 'the lock file lives under wp-content/hookshot');
    assert.strictEqual(res.index, true, 'the work dir gets an index.php');
  });

  it('is released when the holder goes out of scope, so every return path of the bridge releases it', () => {
    const res = php(`
      function hold_then_return() {
        $lock = Hookshot_Install_Lock::acquire( 'xophz-compass-xp' );
        return $lock instanceof Hookshot_Install_Lock;
      }
      $held  = hold_then_return();
      $again = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 0 );
      echo json_encode([ 'held' => $held, 'again' => $again instanceof Hookshot_Install_Lock ]);
    `);
    assert.strictEqual(res.held, true);
    assert.strictEqual(res.again, true);
  });

  it('waits for another PHP process holding the slug before it proceeds', () => {
    const res = php(`
      $holder = 'define("WP_CONTENT_DIR", ' . var_export( WP_CONTENT_DIR, true ) . ');'
        . ' require ' . var_export( '${LOCK_CLASS}', true ) . ';'
        . ' $l = Hookshot_Install_Lock::acquire( "xophz-compass-xp" ); usleep( 1500000 );';
      exec( 'php -r ' . escapeshellarg( $holder ) . ' > /dev/null 2>&1 &' );
      usleep( 300000 );
      $busy    = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 0 );
      $started = microtime( true );
      $lock    = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 10 );
      echo json_encode([
        'busy'   => is_wp_error( $busy ) ? $busy->get_error_code() : 'held',
        'lock'   => $lock instanceof Hookshot_Install_Lock,
        'waited' => round( microtime( true ) - $started, 2 ),
      ]);
    `);
    assert.strictEqual(res.busy, 'install_locked', 'the other process holds the slug');
    assert.strictEqual(res.lock, true, 'the lock is granted once the other process exits');
    assert.ok(res.waited >= 0.8, `waited ${res.waited}s for the other process`);
  });

  it('reports an error instead of running unlocked when the lock directory cannot be created', () => {
    const res = php(`
      $lock = Hookshot_Install_Lock::acquire( 'xophz-compass-xp', 0 );
      echo json_encode([ 'code' => is_wp_error( $lock ) ? $lock->get_error_code() : 'held' ]);
    `, "( $f = sys_get_temp_dir() . '/hookshot-lock-file-' . mt_rand() ) && file_put_contents( $f, 'not a directory' ) ? $f : $f");
    assert.strictEqual(res.code, 'install_lock_unavailable');
  });
});
