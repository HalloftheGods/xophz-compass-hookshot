# 🪝 Xophz Magic Hookshot

[![Version](https://img.shields.io/badge/version-26.7.26-62c9ff.svg?style=for-the-badge)](https://github.com/HalloftheGods/xophz-compass-hookshot)
[![License](https://img.shields.io/badge/license-GPL--2.0+-blue.svg?style=for-the-badge)](http://www.gnu.org/licenses/gpl-2.0.txt)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-21759b.svg?style=for-the-badge&logo=wordpress&logoColor=white)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4.svg?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Category](https://img.shields.io/badge/COMPASS-True%20North%20ITSM-00f2fe.svg?style=for-the-badge)](https://github.com/HalloftheGods)

> **Enterprise-grade incoming & outgoing webhook engine, payload transformation, signature verification, and automated bridge routing for the Xophz COMPASS ecosystem.**

---

## ⚡ Overview

**Xophz Magic Hookshot** is the centralized webhook hub and automation bridge for WordPress and the COMPASS platform. It acts as an intelligent event gateway, allowing COMPASS to seamlessly latch onto external services (GitHub, Slack, Discord, Zapier, Make, custom APIs) and internal ecosystem tools (Questbook CRM, Bomb Bag MA, XP Gamification System).

Hookshot provides robust security guarantees, exponential backoff retries, health tracking, payload mapping, and zero-downtime auto-updates for plugins via GitHub release webhooks.

---

## ✨ Key Features

### 📥 Incoming Webhook Gateway
* **Signed REST Endpoint**: `POST /wp-json/xophz/v1/hookshot/receive/{id}`, where `{id}` is the public webhook id. Every request carries an HMAC signature header keyed by the webhook's signing secret; an unsigned or mis-signed request gets 401. No secret travels in the URL.
* **Verification Challenges**: `POST /wp-json/xophz/v1/hookshot/receive/{id}/verify`, signed the same way, echoes the `challenge` parameter.
* **Deprecated secret URLs**: `/hookshot/incoming/{secret}` and `/hookshot/verify/{secret}` put the secret in the URL path, where it lands in access logs and referrers. They still answer for webhooks that already hold a URL secret, because the GitHub release webhooks on the plugin repositories post to them, and every response carries a `Deprecation` header plus a `Link` to the signed endpoint. New webhooks get no URL secret. Retire the routes with `add_filter( 'xophz_hookshot_legacy_secret_urls', '__return_false' );` (they then answer 410).
* **Payload Safety**: Enforces maximum payload size limits (1MB default) and prevents depth loops (`X-Hookshot-Depth`).

### 📤 Outgoing Webhook Dispatcher
* **Action Hook Binding**: Automatically trigger webhooks when specific WordPress actions occur.
* **Async Dispatching**: Non-blocking background dispatches for high-concurrency environments.
* **Infinite Loop Safeguard**: Tracks call stack depth up to 5 levels to block recursive loops.

### 🛡️ Security & Authentication System
* **HMAC Signature Verification**:
  * **Stripe Format**: `t={timestamp},v1={signature}` with timestamp tolerance checks against replay attacks.
  * **GitHub Format**: Supports standard `sha256=` and `sha1=` signatures.
  * **Custom Headers**: Configurable signature header names (defaults to `X-Hookshot-Signature`; GitHub's `X-Hub-Signature-256` is also accepted).
* **IP Whitelisting**: Restrict incoming calls by source IP addresses (`X-Forwarded-For` aware).
* **Rate Limiting**: Custom per-webhook throughput caps backed by transients.
* **Multi-Auth Support**: Bearer Tokens, Basic Auth (Base64), and custom API Key headers (`X-API-Key`).
* **Write-only secrets**: dashboard responses never contain a secret. They carry `has_signing_secret`, `has_auth_value` and `has_secret` (the deprecated URL secret) booleans instead. Creating an incoming webhook without a signing secret generates one and returns it once, in the create response only. On update, a non-empty value replaces a secret and an empty one keeps it; `"clear": ["signing_secret", "auth_value", "secret"]` removes them.
* **Admin-only reads**: every dashboard route, read or write, needs `manage_options`, and the webhook and log post types map every capability to `manage_options`.
* **Credentials through Connectors**: the GitHub token for private release downloads is the `github_token` connector (Settings > Connectors; also the `GITHUB_TOKEN` environment variable or constant). It is never stored in webhook meta.

### 🌉 Automated Bridge System
Hookshot Bridges automatically turn raw incoming webhooks into executable actions across COMPASS:

| Bridge | Description | Key Capabilities |
| :--- | :--- | :--- |
| 📦 **`github_plugin_release`** | DevOps Auto-Deployment | Auto-updates plugins on GitHub releases (`published` / `released`). Supports Git pulls and automatic backup/rollback; private repositories download with the `github_token` connector. |
| 📇 **`questbook_contact`** | CRM Lead Capture | Auto-creates or updates contacts in Questbook CRM from payload data. |
| ✉️ **`bombbag_subscribe`** | Marketing Automation | Subscribes incoming leads directly to Bomb Bag mailing lists. |
| ⭐ **`xp_grant`** | Gamification Engine | Dynamically grants XP to users upon external triggers. |
| ⚡ **`wp_action`** | Custom Developer Hook | Fires custom WordPress actions with payload parameters. |

### 🔄 Transformation & Payload Mapping
* **JSONPath Mapping**: Map incoming/outgoing payload fields using dot-notation (`$.event`, `$.user.email`, or static values).
* **Presets**: Built-in payload mapping presets for **Slack**, **Discord**, **Zapier**, and **Make (Integromat)**.
* **Live Preview**: Preview payload transformations before persisting rules.

### 🔁 Resilience & Retry Engine
* **Exponential Backoff**: Automatic retries scheduled at 2 mins, 15 mins, 1 hr, and 6 hrs.
* **Action Scheduler Integration**: High-performance background scheduling with fallback to `WP-Cron`.
* **Dead Letter Queue**: Failed webhooks exceeding max retry attempts are moved to the Dead Letter Queue for inspection and manual replay.
* **Log Retention**: Built-in Garbage Collection (`Hookshot_GC`) automatically purges logs older than 30 days.

### 📊 Health Tracking & Monitoring
* **Real-time Status**: Categorizes webhooks as `Healthy` (Green), `Degraded` (Yellow, ≥10% failure rate), or `Critical` (Red, ≥50% failure rate).
* **Degraded Action Trigger**: Fires `xophz_hookshot_health_degraded` when failure rates spike.
* **REST Dashboard API**: Full suite of management endpoints under `/wp-json/hookshot/v1/`.

---

## 🚀 REST API Endpoints

### Public Webhook Routes (`xophz/v1`)
* `POST /wp-json/xophz/v1/hookshot/receive/{id}` - Incoming webhook ingestion, HMAC signature header required.
* `POST /wp-json/xophz/v1/hookshot/receive/{id}/verify` - Challenge-response endpoint, HMAC signature header required.
* `POST /wp-json/xophz/v1/hookshot/incoming/{secret}` - **Deprecated** secret-in-URL ingestion, kept for existing GitHub release webhooks.
* `POST /wp-json/xophz/v1/hookshot/verify/{secret}` - **Deprecated** secret-in-URL challenge endpoint.

### Dashboard REST API (`hookshot/v1`, `manage_options` only)
* `GET|POST /wp-json/hookshot/v1/webhooks` - List webhooks, or create one (an incoming create returns its generated signing secret once).
* `GET|PUT|DELETE /wp-json/hookshot/v1/webhooks/{id}` - Retrieve, update or delete a webhook configuration.
* `POST /wp-json/hookshot/v1/webhooks/{id}/test` - Send a test payload (incoming: returns the signed endpoint and signing instructions).
* `GET /wp-json/hookshot/v1/webhooks/{id}/logs` - Fetch execution logs for a webhook (`Authorization` and `Cookie` headers are redacted when logged).
* `GET /wp-json/hookshot/v1/webhooks/{id}/health` - Get health stats for a webhook.
* `GET /wp-json/hookshot/v1/dead-letters` - View dead letter queue.
* `POST /wp-json/hookshot/v1/dead-letters/{id}/retry` - Manually retry a dead letter event.
* `GET /wp-json/hookshot/v1/stats` - Aggregate system health stats.
* `GET /wp-json/hookshot/v1/bridges` - Available bridge configurations.
* `GET /wp-json/hookshot/v1/presets` - Transformation presets.

### Moving a GitHub release webhook to the signed endpoint
This order keeps deploys working at every step:
1. Pick a new signing secret. In each GitHub repository, Settings > Webhooks, put it in Secret and keep the old Payload URL. GitHub now signs deliveries with `X-Hub-Signature-256`.
2. In the Hookshot dashboard, open the incoming webhook and type the same value in Signing Secret (the field is write-only; leaving it empty keeps the current one). The deprecated route now verifies those signatures.
3. In each repository, change the Payload URL to the webhook's endpoint, `/wp-json/xophz/v1/hookshot/receive/{id}`.
4. When `legacy_url_last_used` on the webhook stops changing, clear the URL secret (`"clear": ["secret"]`) and retire the legacy routes with the filter above.

### What an incoming sender can and cannot change
* **IP allowlist**: a webhook's allowed IPs (IPs or CIDR ranges) are checked against `REMOTE_ADDR`. `X-Forwarded-For` is a header the sender writes, so it is read only when `REMOTE_ADDR` is a trusted proxy. Behind a load balancer or CDN, list its address in `define( 'XOPHZ_HOOKSHOT_TRUSTED_PROXIES', '10.0.0.0/8,203.0.113.5' );` (or return the list from the `xophz_hookshot_trusted_proxies` filter). The client is then the right-most forwarded address that is not itself a trusted proxy.
* **Release behavior flags**: the GitHub Plugin Release bridge reads `allow_prereleases` and `allow_redeploy` from the webhook's stored bridge settings. A top-level `allow_prereleases`, `force` or `redeploy` field in the payload counts only when the request carried a valid signature for the webhook's signing secret. Query-string `?force`, `?redeploy` and `?allow_prereleases` are ignored.
* **Errors**: a failing bridge or handler is logged on the server and mailed to the site admin; the sender gets a generic message, never the exception text.

---

## 🛠️ Installation & Setup

1. Clone or extract `xophz-compass-hookshot` into your WordPress plugins directory:
   ```bash
   wp-content/plugins/xophz-compass-hookshot
   ```
2. Ensure core **COMPASS** plugin (`xophz-compass`) is activated first.
3. Activate **Xophz Magic Hookshot** in WordPress Admin (`Plugins > Installed Plugins`).
4. Access the Webhook Management interface via COMPASS ITSM Dashboard or REST API.

---

## 🧪 Developer Hooks & Actions

Hookshot provides developer actions and filters for custom integrations:

```php
// Listen for incoming webhook dispatches
add_action( 'xophz_hookshot_incoming', function( $payload, $webhook_id ) {
    // Custom handling for incoming payload
}, 10, 2 );

// Modify outgoing payload before transmission
add_filter( 'xophz_hookshot_outgoing_payload', function( $payload, $webhook_id, $event ) {
    $payload['custom_meta'] = 'COMPASS-System';
    return $payload;
}, 10, 3 );

// Register custom bridges
add_action( 'xophz_hookshot_register_bridges', function() {
    Hookshot_Bridges::register( 'my_custom_bridge', [
        'name'        => 'Custom Automation',
        'description' => 'Triggers internal service on incoming webhook.',
        'icon'        => 'fad fa-cogs',
        'category'    => 'Custom',
        'fields'      => [ 'endpoint', 'token' ],
        'handler'     => 'my_custom_bridge_handler_callback',
    ] );
} );
```

---

## 📄 License & Attribution

Developed with ❤️ by **[Hall of the Gods, Inc.](https://www.hallofthegods.com/)**  
Licensed under the [GNU General Public License v2.0 or later](http://www.gnu.org/licenses/gpl-2.0.txt).
