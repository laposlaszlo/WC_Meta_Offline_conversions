# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.8] - 2026-02-16
- Add Minimal Data Mode to avoid Meta policy violations with health/medical products

## [1.0.7] - 2026-02-16
- Log full JSON response from Meta API for better debugging
- Add configurable event name setting in admin panel

## [1.0.6] - 2026-02-15
- No notable changes

## [1.0.5] - 2026-02-12
- Add a toggleable admin setting for cheque test mode.
- When enabled, send Purchase events for cheque orders on `on-hold` and `processing` statuses.
- Keep normal production behavior unchanged: completed status remains the primary trigger.

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
