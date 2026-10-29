# Tabbed executive dashboard validation — 29 October 2026

These checks use synthetic records and local disposable databases. They do not
constitute live UAT acceptance or a production deployment.

## Recorded validation

- Complete SQLite regression suite: 717 tests, 5,349 assertions passed before the
  final additional compact-queue preservation case.
- Final tabbed-dashboard suite: 10 tests, 622 assertions passed, including distinct
  nonzero counts for all four former compact-view action queues and disabled-widget
  exclusions across tabs.
- Disposable MariaDB 10.11.16: 42 dashboard/team cases, 716 assertions passed before
  the final additional compact-queue case. The harness verifies the loopback
  instance's data directory and creates a fresh `phos_validation_*` database.
- Browser acceptance: 106 checks passed using actual Laravel-rendered synthetic
  pages, compiled app assets and the Prime Healers logo. Checked all six views at
  320, 390, 736, 1024 and 1440 pixels, including role exclusions, JavaScript-disabled
  links, history, keyboard navigation, fragment links, filters and Alpine controls.
- Blade cache compilation, PHP/JavaScript syntax and whitespace checks passed.
- UAT rollout helper: 27 offline tests passed with one Windows symlink-privilege
  skip. Recovery rehearsals include partial activation and persistent cache-probe
  failure, with baseline files restored before probing/restarting the application.

The only controller change is the old Business overview redirect. No metric
service, report/export handler, business query, migration or role configuration was
changed. Actual customer data was not used in the local browser fixtures.

## Live UAT acceptance

Pending execution of the pinned, guarded operator package. Existing SSH key
authentication is rejected; no remote dashboard files have been changed by these
local tests. The operator must verify UAT completion and the deployed dashboard
before acceptance. Production rollout remains unauthorized.
