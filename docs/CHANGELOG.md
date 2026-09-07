# Changelog - Xophz Magic Hookshot

All notable changes to this WordPress plugin submodule will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-09-07]

### Added
- **GitHub Release Event Support**: Enhanced `Hookshot_Bridges::bridge_github_plugin_release` in `includes/class-hookshot-bridges.php` to handle `action: 'released'` events alongside `published`. This allows releases converted from pre-release to full release to trigger deployments seamlessly.
- **Version Equality Guard**: Added smart pre-download version comparison in `includes/class-hookshot-bridges.php` to prevent downloading large ZIP packages and redundant reinstallations when the target version is already active on disk.
- **Pre-Release Gating**: Added release state guards skipping drafts and unapproved pre-releases by default, with an opt-in `allow_prereleases` bridge configuration.
- **Force Redeployment Mode**: Added support for `?force=1` / `?redeploy=1` query parameters, payload flags, and `allow_redeploy` bridge settings to bypass the deduplication lock and version check when identical-version redeployment is required.
- **Frontend Field Configuration**: Registered `allow_prereleases` and `allow_redeploy` in `github_plugin_release` bridge schema and updated field definitions in `src/routes/hookshot/views/hookshot-detail.vue`.
