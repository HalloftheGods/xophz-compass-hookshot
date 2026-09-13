# Changelog - Xophz Magic Hookshot

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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
