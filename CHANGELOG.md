# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.4] - 2026-02-12
- Add in-plugin `Event Log` section on the WooCommerce settings page.
- Persist plugin log entries in WordPress options and display recent entries in admin.
- Add `Clear Log` admin action with nonce protection and success notice.
- Add bulk process start/finish informational entries to the admin event log.

## [1.0.3] - 2026-02-12
- Release v1.0.2

## [1.0.2] - 2026-02-12
- Fix release packaging by excluding nested `*.zip` files.
- Clarify install instructions: use release asset ZIP, not GitHub source ZIP.
- Ignore plugin ZIP build artifacts and remove committed ZIP artifact.
- Fix `scripts/update-changelog.sh` variable interpolation error.
