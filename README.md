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
* **Secure REST Endpoints**: Dynamic endpoints under `/wp-json/xophz/v1/hookshot/incoming/{secret}`.
* **Verification Challenges**: Built-in verification endpoint `/wp-json/xophz/v1/hookshot/verify/{secret}` supporting challenge-response handshakes.
* **Payload Safety**: Enforces maximum payload size limits (1MB default) and prevents depth loops (`X-Hookshot-Depth`).

### 📤 Outgoing Webhook Dispatcher
* **Action Hook Binding**: Automatically trigger webhooks when specific WordPress actions occur.
* **Async Dispatching**: Non-blocking background dispatches for high-concurrency environments.
* **Infinite Loop Safeguard**: Tracks call stack depth up to 5 levels to block recursive loops.

### 🛡️ Security & Authentication System
* **HMAC Signature Verification**:
  * **Stripe Format**: `t={timestamp},v1={signature}` with timestamp tolerance checks against replay attacks.
  * **GitHub Format**: Supports standard `sha256=` and `sha1=` signatures.
  * **Custom Headers**: Configurable signature header names (defaults to `X-Hookshot-Signature`).
* **IP Whitelisting**: Restrict incoming calls by source IP addresses (`X-Forwarded-For` aware).
* **Rate Limiting**: Custom per-webhook throughput caps backed by transients.
* **Multi-Auth Support**: Bearer Tokens, Basic Auth (Base64), and custom API Key headers (`X-API-Key`).

### 🌉 Automated Bridge System
Hookshot Bridges automatically turn raw incoming webhooks into executable actions across COMPASS:

| Bridge | Description | Key Capabilities |
| :--- | :--- | :--- |
| 📦 **`github_plugin_release`** | DevOps Auto-Deployment | Auto-updates plugins on GitHub releases (`published` / `released`). Supports Git pulls, automatic backup/rollback, and private repo tokens. |
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
* **REST Dashboard API**: Full suite of management endpoints under `/wp-json/xophz-hookshot/v1/`.

---

## 🚀 REST API Endpoints

### Public Webhook Routes (`xophz/v1`)
* `POST /wp-json/xophz/v1/hookshot/incoming/{secret}` - Incoming webhook ingestion.
* `POST /wp-json/xophz/v1/hookshot/verify/{secret}` - Challenge-response endpoint.

### Dashboard REST API (`xophz-hookshot/v1`)
* `GET /wp-json/xophz-hookshot/v1/webhooks` - List all configured webhooks.
* `GET|POST /wp-json/xophz-hookshot/v1/webhooks/{id}` - Retrieve or update a webhook configuration.
* `POST /wp-json/xophz-hookshot/v1/webhooks/{id}/test` - Send a test payload.
* `GET /wp-json/xophz-hookshot/v1/webhooks/{id}/logs` - Fetch execution logs for a webhook.
* `GET /wp-json/xophz-hookshot/v1/webhooks/{id}/health` - Get health stats for a webhook.
* `GET /wp-json/xophz-hookshot/v1/dead-letters` - View dead letter queue.
* `POST /wp-json/xophz-hookshot/v1/dead-letters/{id}/retry` - Manually retry a dead letter event.
* `GET /wp-json/xophz-hookshot/v1/stats` - Aggregate system health stats.
* `GET /wp-json/xophz-hookshot/v1/bridges` - Available bridge configurations.
* `GET /wp-json/xophz-hookshot/v1/presets` - Transformation presets.

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
