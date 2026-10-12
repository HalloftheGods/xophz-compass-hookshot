/**
 * Signed receive reads the webhook id from the URL only. A JSON body with its own top-level "id"
 * (every Stripe event: "id": "evt_...") must not replace it, or every such delivery gets a 401.
 * Runs against the real WordPress in the u-wordpress container and removes what it creates.
 */

import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { describe, it, assert, assertNoEmDashes } from '../../xophz-compass/tests/harness/test-framework.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));

function wp(code) {
  const script = `<?php\ndefine('WP_USE_THEMES', false);\nrequire '/var/www/html/wp-load.php';\n${code}`;
  const r = spawnSync('docker', ['exec', '-i', 'u-wordpress', 'php'], { input: script, encoding: 'utf8', timeout: 60000 });
  if (r.status !== 0) throw new Error(`PHP failed: ${r.stderr || r.stdout}`);
  return JSON.parse(r.stdout.trim().split('\n').pop());
}

/** Creates a signed incoming webhook, posts `body` (signed) through REST dispatch, returns the status. */
function signedDelivery(body, { contentType = 'application/json' } = {}) {
  return wp(`
    $id = wp_insert_post( array( 'post_title' => 'route-params test', 'post_type' => 'compass_webhook', 'post_status' => 'publish' ) );
    update_post_meta( $id, 'hookshot_type', 'incoming' );
    update_post_meta( $id, 'hookshot_status', 'active' );
    update_post_meta( $id, 'hookshot_sig_header', 'Stripe-Signature' );
    $secret = 'whsec_' . wp_generate_password( 32, false );
    Hookshot_Webhook_Secrets::save( $id, array( 'signing_secret' => $secret ) );
    $body = ${JSON.stringify(body)};
    $t    = time();
    $req  = new WP_REST_Request( 'POST', '/xophz/v1/hookshot/receive/' . $id );
    ${contentType ? `$req->set_header( 'content-type', '${contentType}' );` : ''}
    $req->set_header( 'stripe-signature', "t={$t},v1=" . hash_hmac( 'sha256', "{$t}.{$body}", $secret ) );
    $req->set_body( $body );
    $res = rest_do_request( $req );
    wp_delete_post( $id, true );
    echo json_encode( array( 'status' => $res->get_status() ) );
  `);
}

describe('Hookshot signed receive: route params come from the URL', () => {
  it('a Stripe-shaped JSON body with a top-level id is accepted', () => {
    const res = signedDelivery(JSON.stringify({ id: 'evt_1PqRsT', object: 'event', type: 'checkout.session.completed', data: { object: {} } }));
    assert.strictEqual(res.status, 200);
  });

  it('a JSON body whose id is a number for another webhook is still routed by the URL', () => {
    const res = signedDelivery(JSON.stringify({ id: 1, type: 'ping' }));
    assert.strictEqual(res.status, 200);
  });

  it('a body without an id keeps working', () => {
    const res = signedDelivery(JSON.stringify({ action: 'published', release: { tag_name: 'v1' } }));
    assert.strictEqual(res.status, 200);
  });

  it('a wrong signature is still refused', () => {
    const res = wp(`
      $id = wp_insert_post( array( 'post_title' => 'route-params test', 'post_type' => 'compass_webhook', 'post_status' => 'publish' ) );
      update_post_meta( $id, 'hookshot_type', 'incoming' );
      update_post_meta( $id, 'hookshot_status', 'active' );
      update_post_meta( $id, 'hookshot_sig_header', 'Stripe-Signature' );
      Hookshot_Webhook_Secrets::save( $id, array( 'signing_secret' => 'whsec_' . wp_generate_password( 32, false ) ) );
      $req = new WP_REST_Request( 'POST', '/xophz/v1/hookshot/receive/' . $id );
      $req->set_header( 'content-type', 'application/json' );
      $req->set_header( 'stripe-signature', 't=' . time() . ',v1=' . str_repeat( '0', 64 ) );
      $req->set_body( '{"id":"evt_x"}' );
      $res = rest_do_request( $req );
      wp_delete_post( $id, true );
      echo json_encode( array( 'status' => $res->get_status() ) );
    `);
    assert.strictEqual(res.status, 401);
  });

  it('files contain no em dashes', () => {
    for (const f of ['../includes/class-xophz-compass-hookshot-rest.php', 'route-params.test.mjs']) {
      assertNoEmDashes(fs.readFileSync(path.join(HERE, f), 'utf8'), f);
    }
  });
});
