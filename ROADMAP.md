# PHOS Roadmap

## Current Release

- Version: v1.0.0-rc1
- Purpose: UAT release candidate
- Focus: UI stabilization and production readiness
- Status: Frozen for UAT testing
- Release commit: c70e157716d6b7ad4f681cfa4ff5969ed0d2ca6b
- Previous UAT commit: b38760d15a5e519cc91d7ad073597af477adb38b

## Release Policy

- No new features before production go-live.
- Only P0/P1 bug fixes during UAT.
- Production should use the exact UAT-approved commit.
- After UAT sign-off, tag the approved commit as v1.0.0.

## Deferred Enhancements After Production

These items are future roadmap candidates and are not part of the current UAT release scope.

- Asset ROI dashboard
- Aadhaar OTP / customer KYC verification
- External API and webhooks
- Damage management workflow
- Barcode label designer
- Predictive maintenance
- QR asset portal for field staff
- Asset availability calendar
- Advanced partner/referral analytics
- Advanced import assistant
- Public mobile app, if planned later

## Near-Term Post-Go-Live Priorities

- Fix production bugs found during real operations.
- Improve speed/performance if users report slowness.
- Improve import templates based on real data.
- Add missing operational reports only after stable usage.
- Add new features in small versioned releases.

## Versioning Plan

- v1.0.0-rc1: UAT candidate
- v1.0.0: First production release
- v1.0.1: Bug fixes only
- v1.1.0: Small enhancements
- v2.0.0: Major workflow or architecture changes

## Rollback Principle

- Keep previous UAT commit documented.
- Keep release tags immutable.
- Never force-push release tags.
- Never commit storage backups or customer documents.
