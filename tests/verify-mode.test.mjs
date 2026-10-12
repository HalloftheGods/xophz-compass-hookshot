/**
 * Hookshot_Signature::verify(): the bearer mode for senders that post a fixed
 * Authorization header (RevenueCat), and the unchanged HMAC default.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';
import { runPhpJson } from '../../xophz-compass/tests/harness/php-executor.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PLUGIN = path.resolve(HERE, '..');
const SIGNATURE = '/var/www/html/wp-content/plugins/xophz-compass-hookshot/includes/class-hookshot-signature.php';

/** A JS value as a PHP expression (nowdoc JSON, decoded to arrays). */
function lit(value) {
  return `json_decode(<<<'JSON'\n${JSON.stringify(value)}\nJSON\n, true)`;
}

/** Run a snippet with post meta from `meta`, a fake request, and the signature class loaded. */
function php(meta, request, code) {
  return runPhpJson(`
    $GLOBALS['fake_meta'] = ${lit(meta)};
    function get_post_meta( $id, $key, $single = false ) {
      return $GLOBALS['fake_meta'][ $key ] ?? '';
    }
    class Fake_Request extends WP_REST_Request {
      public $fake_headers = [];
      public $fake_body = '';
      public function get_header( $name ) { return $this->fake_headers[ $name ] ?? null; }
      public function get_body() { return $this->fake_body; }
    }
    require_once '${SIGNATURE}';
    $request = new Fake_Request();
    $request->fake_headers = ${lit(request.headers || {})};
    $request->fake_body = ${lit(request.body || '')};
    ${code}
  `);
}

const verdict = `
  $result = Hookshot_Signature::verify( $request, 7 );
  echo json_encode([
    'ok'       => $result === true,
    'error'    => is_wp_error( $result ),
    'status'   => is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? null ) : null,
    'verified' => Hookshot_Signature::was_verified( 7 ),
  ]);
`;

describe('Hookshot verify: bearer mode', () => {
  const bearer = { hookshot_verify_mode: 'bearer', hookshot_signing_secret: 'whsec-bearer-secret' };

  it('bearer accepts the exact header and marks verified', () => {
    const res = php(bearer, { headers: { authorization: 'Bearer whsec-bearer-secret' } }, verdict);
    assert.strictEqual(res.ok, true);
    assert.strictEqual(res.verified, true);
  });

  it('bearer refuses a wrong secret', () => {
    const res = php(bearer, { headers: { authorization: 'Bearer another-secret' } }, verdict);
    assert.strictEqual(res.error, true);
    assert.strictEqual(res.status, 401);
    assert.strictEqual(res.verified, false);
  });

  it('bearer refuses a header without the Bearer prefix', () => {
    const res = php(bearer, { headers: { authorization: 'whsec-bearer-secret' } }, verdict);
    assert.strictEqual(res.error, true);
    assert.strictEqual(res.status, 401);
  });

  it('bearer refuses a missing header', () => {
    const res = php(bearer, { headers: {} }, verdict);
    assert.strictEqual(res.error, true);
    assert.strictEqual(res.status, 401);
    assert.strictEqual(res.verified, false);
  });

  it('bearer refuses when no secret is stored', () => {
    const res = php(
      { hookshot_verify_mode: 'bearer' },
      { headers: { authorization: 'Bearer ' } },
      verdict
    );
    assert.strictEqual(res.error, true);
    assert.strictEqual(res.status, 401);
    assert.strictEqual(res.verified, false);
  });
});

describe('Hookshot verify: hmac mode', () => {
  it('hmac stays the default and still checks Stripe signatures', () => {
    const body = '{"id":"evt_test_1"}';
    const res = php(
      { hookshot_signing_secret: 'whsec-hmac-secret', hookshot_sig_header: 'Stripe-Signature' },
      { body, headers: {} },
      `
        $request->fake_headers['stripe_signature'] = Hookshot_Signature::sign( $request->fake_body, 'whsec-hmac-secret' );
        ${verdict}
      `
    );
    assert.strictEqual(res.ok, true);
    assert.strictEqual(res.verified, true);
  });

  it('hmac ignores a matching bearer header', () => {
    const res = php(
      { hookshot_signing_secret: 'whsec-hmac-secret' },
      { headers: { authorization: 'Bearer whsec-hmac-secret' } },
      verdict
    );
    assert.strictEqual(res.error, true);
    assert.strictEqual(res.verified, false);
  });
});

describe('Hookshot verify: source hygiene', () => {
  it('files contain no em dashes', () => {
    for (const rel of ['includes/class-hookshot-signature.php', 'includes/class-hookshot-rest-dashboard.php']) {
      assertNoEmDashes(fs.readFileSync(path.join(PLUGIN, rel), 'utf8'), rel);
    }
  });
});
