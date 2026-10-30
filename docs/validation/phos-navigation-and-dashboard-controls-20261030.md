# PHOS navigation and dashboard controls — 30 October 2026

This update prioritizes the daily-work sidebar across PHOS and replaces the large
dashboard filter dock with a compact header control. The activity-period selector
stays visible above the tabs. Its preset links use the existing from/to date
filters; All dates retains the existing default scope. The legacy single-date
query is cleared when selecting a new period. Metric definitions are unchanged.

The global navigation style is part of the shared layout, independent of the
dashboard stylesheet. Existing permissions, badges and route highlighting are
preserved. Knowledge Hub includes the existing authorized workflow-guide catalog,
without seeding or rewriting knowledge/business records. Existing published
articles and old guide URLs remain available.

## Validation

- Final focused dashboard, sidebar, presentation and Knowledge Hub checks passed:
  29 tests, 881 assertions with synthetic fixture export enabled.
- The final header controls regression passed: 2 tests, 56 assertions, including
  month boundaries and clearing a legacy single-date filter through All dates.
- Shared navigation/sidebar/mobile/cache checks passed: 7 tests, 105 assertions.
- Committed browser navigation/form/preset regression: 8 Node test results passed,
  including the parent suite, with JavaScript enabled and disabled.
- Actual Laravel-rendered browser acceptance: 193 checks passed with zero browser
  errors. Covered all six tabs at 320, 390, 736, 1024 and 1440 pixels, header icon
  placement, visible periods, real GET filter/preset navigation, dismissal/focus,
  shared profile/Knowledge Hub navigation and expandable guide content.
- After the final card-alignment refinement, 10 additional targeted Knowledge Hub
  browser checks passed at desktop/mobile sizes with zero browser errors. Closed
  cards retain compact height while another guide is expanded.
- Short-phone physical click checks: 6 passed at 320×680 and 390×760. The period
  menu and advanced filters scroll within the available space above bottom
  navigation; Custom range and Apply remain clickable.
- The nine-file rollout helper passed 33 offline tests, with one Windows symlink
  privilege skip. Recovery cases restore the exact installed source and metadata,
  including both Knowledge Hub files, without touching previous backups.

## UAT upgrade

The operator-confirmed installed baseline is
`4b70936f20410fddd3d9707b30907f5000c0b2a9`. The package must verify the exact baseline
bytes of all nine reviewed destinations, back up every original with its metadata,
and restore that baseline on failure. Previous private deployment backups remain
untouched. No migrations, dependency installs, business-data writes or production
deployment are part of this update. Live acceptance of this revision is pending
the guarded operator command and subsequent browser verification.
