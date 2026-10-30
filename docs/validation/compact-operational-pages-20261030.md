# Compact operational pages — 30 October 2026

The implementation changes presentation on the eight requested index pages and
the shared application header. It changes no controllers, routes, financial
calculations, permissions or migrations. Source review retained the original
forms, named inputs, CSRF/method fields and workflow actions. The duplicate
quick-search/filter entry points remain in the DOM but are hidden in favor of
the complete native header filter.

## Local validation

- Complete PHP suite: 740 tests, 5,957 assertions passed.
- After the last density refinements, focused nonempty-list, query and role
  checks: 25 tests, 310 assertions passed. Actual Laravel HTML was exported for
  browser review from these synthetic fixtures.
- The final Renewal workflow-label refinement also passed the seven operations
  regressions (113 assertions), with fresh exports of all four operations pages.
- Existing dashboard and new compact-filter browser regressions: 13 Node test
  results passed. JavaScript and native no-JavaScript GET navigation, active
  filter state, persisted legacy open state, dismissal and focus are covered.
- The negative control restores the previous broad legacy filter selector and
  fails the three JavaScript interaction cases, demonstrating that the new
  exclusion is necessary. The no-JavaScript case still passes.
- Frontend production build passed. No dependency files changed.
- Final eight-page rendered browser acceptance: 303 checks passed, with no
  browser errors or non-GET requests. Covered 1440, 1280, 1024, 768, 390 and 320
  pixel widths, including short phones, JavaScript-disabled forms, actual asset
  status/MIME/typography, active-chip contrast, filter bounds, permission
  variants, static Rental Actions and reachable Renewal/Tasks Actions after
  table-local scrolling. No screenshot uses live business data.
- A final 38-check Renewal supplement passed after restoring explicit desktop
  Reminder/Renew/Pickup labels, including the refreshed screenshots, popup
  geometry, scrolling and JavaScript-disabled controls.
- Independent application and rollout-helper reviews found no remaining
  material blocker before final rendered acceptance.
- The separate UAT helper passed 38 offline checks, with one Windows symlink
  privilege skip. All three embedded PHP probes compile; deterministic ZIP
  reconstruction parity was verified. These are local recovery rehearsals,
  without contacting or changing the UAT server.

The full PHP run precedes the final view-only density adjustments. The focused
25-test rerun covers the eight affected views after those adjustments; the final
pull-request CI also runs the complete suite against the committed source.

Representative final fixture row/card heights were approximately 126 px for
Rentals, 100 for Sales, 59 for Customers, 66 for Business Partners, 93 for
Renewals, 138 for Pickups, 42 for Communications and 61 for Tasks. These vary
with record contents and are not fixed heights that clip longer information.

## UAT boundary

The installed source baseline is
`c0fef0f918ab2ea01be4bc3222f811d9f4a3d664`. The separate thirteen-file UAT package
verifies nine exact originals and four absent new paths, and preserves the
previous executive dashboard, Knowledge Hub and business source. It uses a new
private backup and rollback helper. No migrations, business fixtures or
production deployment are authorized by this package. Authenticated UAT
acceptance remains pending operator activation and browser verification.
