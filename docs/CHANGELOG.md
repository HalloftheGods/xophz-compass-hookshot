# Changelog - Xophz Magic Hookshot

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-10-10]

### Security
- **Webhook secrets no longer leave the server (#5955, H1)**: every `hookshot/v1` dashboard route, reads included, now needs `manage_options` (reads were open to any logged-in user, subscribers included). Responses never contain `secret`, `signing_secret`, `auth_value` or a `github_token` inside `bridge_config`; they carry `has_secret`, `has_signing_secret` and `has_auth_value` booleans. `Hookshot_Webhook_Secrets` (`includes/class-hookshot-webhook-secrets.php`) makes those fields write-only: an empty value on update keeps the stored secret, and `clear` removes one. An incoming create generates a signing secret when none is given and returns it once, in the create response only.
- **Signed receive endpoint (#5955, M7)**: added `POST xophz/v1/hookshot/receive/{id}` and `/receive/{id}/verify`, authenticated by an HMAC signature header (`Hookshot_Signature::verify_required`), so no secret travels in the URL. GitHub's `X-Hub-Signature-256` header is accepted alongside the configured one. Dashboard `endpoint` fields, the incoming test response and the wp-admin meta box now show this URL and never the secret.
- **Deprecated, still served**: `xophz/v1/hookshot/incoming/{secret}` and `/hookshot/verify/{secret}` stay because 42 GitHub release webhooks on the plugin repositories still post to them. Responses carry `Deprecation` and `Link` (successor) headers, each use stamps `hookshot_legacy_last_used` (shown as `legacy_url_last_used`), new webhooks get no URL secret, and `add_filter( 'xophz_hookshot_legacy_secret_urls', '__return_false' )` retires the routes (410).
- **GitHub token through Connectors**: registered the `github_token` connector (`Hookshot_Connectors`, `includes/class-hookshot-connectors.php`; option `compass_github_token`, constant and env var `GITHUB_TOKEN`). The GitHub Plugin Release bridge reads it and no longer reads `bridge_config['github_token']`, the `GITHUB_TOKEN`/`GITHUB_PA_TOKEN` constants directly, or the encrypted Bugnet option. A one-time migration moves any `github_token` found in `hookshot_bridge_config` meta into the connector and deletes it from meta; the bridge's settings no longer offer the field.
- **Admin-only post types**: `compass_webhook` and `compass_wh_log` map every capability to `manage_options`, so editors can no longer open a webhook or log in wp-admin.
- **Log redaction**: incoming logs store `Authorization`, `Proxy-Authorization`, `Cookie` and `X-WP-Nonce` headers as `[redacted]`.
- **IP allowlist no longer trusts `X-Forwarded-For` (#5971)**: `Hookshot_Client_Ip` (`includes/class-hookshot-client-ip.php`) takes `REMOTE_ADDR`, and reads the forwarded header only when `REMOTE_ADDR` is in `XOPHZ_HOOKSHOT_TRUSTED_PROXIES` (or the `xophz_hookshot_trusted_proxies` filter); behind a trusted proxy the client is the right-most forwarded address that is not itself a proxy, and a malformed header resolves to no address (blocked). Allowlist entries may now be IPv4/IPv6 CIDR ranges as well as single IPs.
- **Behavior flags no longer come from the query string (#5971)**: the GitHub Plugin Release bridge used to honor `?force`, `?redeploy` and `?allow_prereleases` from any caller; the query string is no longer read. Payload `force`, `redeploy` and `allow_prereleases` count only when the request passed an HMAC check (`Hookshot_Signature::was_verified`); a webhook with no signing secret honors only its stored `allow_prereleases` and `allow_redeploy` settings.
- **No exception text to senders (#5971)**: the receiver's 500 response is `Internal processing error.` and a bridge that throws reports a generic error in both the response and the stored log. The exception text still goes to the server log and the admin failure email.

## [2026-09-13]

### Added
- **GitHub Sponsors SaaS Gate Bridge**: Registered `github_sponsors` bridge in `Hookshot_Bridges::register_default_bridges` to sync incoming GitHub Sponsors webhook events (`created`, `tier_changed`, `cancelled`) with `Gatekeeper_Entitlements` in `xophz-compass-gatekeeper`.
- **Automatic Sponsor Provisioning**: Automatically generates `CX-SP-` developer licenses, maps sponsor tiers ($9/mo Solo vs $49/mo Team Power Puff), and dispatches the `xophz_github_sponsorship_sync` action for marketing drips.

## [2026-09-07]

### Added
- **GitHub Release Event Support**: Enhanced `Hookshot_Bridges::bridge_github_plugin_release` in `includes/class-hookshot-bridges.php` to handle `action: 'released'` events alongside `published`. This allows releases converted from pre-release to full release to trigger deployments seamlessly.
- **Version Equality Guard**: Added smart pre-download version comparison in `includes/class-hookshot-bridges.php` to prevent downloading large ZIP packages and redundant reinstallations when the target version is already active on disk.
- **Pre-Release Gating**: Added release state guards skipping drafts and unapproved pre-releases by default, with an opt-in `allow_prereleases` bridge configuration.
- **Force Redeployment Mode**: Added support for `?force=1` / `?redeploy=1` query parameters, payload flags, and `allow_redeploy` bridge settings to bypass the deduplication lock and version check when identical-version redeployment is required.
- **Frontend Field Configuration**: Registered `allow_prereleases` and `allow_redeploy` in `github_plugin_release` bridge schema and updated field definitions in `src/routes/hookshot/views/hookshot-detail.vue`.
