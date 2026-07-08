# PHOS v1.0.0-rc1

- Version: v1.0.0-rc1
- Release Type: Release Candidate
- Date: 2026-07-07
- Branch: ui-stabilization-uat-20260707
- Previous UAT Commit: b38760d15a5e519cc91d7ad073597af477adb38b

## Summary

First formal PHOS release candidate checkpoint before UAT deployment.

Major UI stabilization completed across:

- Dashboard
- Rental
- Sales
- Renewal
- Deliveries
- Inventory Intelligence
- Product Master
- Asset Register
- Stock History
- Invoice Center
- Company Settings
- Roles
- Import Wizard
- Business Partners

## Known Issues

None blocking UAT.

## Rollback

Rollback to previous UAT commit:

```bash
git checkout uat
git reset --hard b38760d15a5e519cc91d7ad073597af477adb38b
git push --force-with-lease origin uat
```

Server rollback:

```bash
git checkout uat
git reset --hard b38760d15a5e519cc91d7ad073597af477adb38b
php artisan optimize:clear
```
