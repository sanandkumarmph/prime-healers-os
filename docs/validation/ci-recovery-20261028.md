# Restore complete CI coverage

The previous CI run stopped at cache preparation because `.env.example` had an unquoted display name. After fixing that, the suite also needed Pest rather than direct PHPUnit because the regression directory contains both test styles. The import-template closure tests now explicitly boot `Tests\TestCase`. The testing environment uses in-memory SQLite instead of the inherited Rentnexis database name. The workflow runs every configured unit and feature test, including all regressions, once.

The first complete local regression run exposed 31 failures. These were investigated rather than skipped:

- Collection checks assert numeric totals, retaining the linked-versus-orphaned payment contract without depending on a currency prefix.
- Two inventory fixtures explicitly freeze May 2026 as their current month. Their original stock reconciliation expectations are retained.
- Dashboard visibility checks inspect the permission-controlled widget state rather than matching release-note text. Unauthorized navigation checks inspect actual route links, and delivery action checks inspect actionable links rather than hidden modal headings.
- UI checks follow the existing rental wizard, workspace navigation, contact targets, asset counters, and updated labels. Profitability coverage checks an authorized finance user and explicitly denies an operations user without profit permission.
- Active filter panels expose their active state, open before JavaScript runs, and reset through the existing shared filter handler.
- A stronger pickup assertion exposed a real mismatch: a rental marked returned could still offer pickup completion when item progress was stale. The taskboard and its cards now use the same completion rule as logistics metrics; cancelled tasks remain cancelled. The regression checks zero pending pickups, the picked-up state, and absence of the completion action.

Validation: the complete configured local suite passed with **636 tests / 4,262 assertions**. Earlier focused correction tests, disposable MariaDB concurrency checks, and migration guards remain documented separately. GitHub CI must be verified on the published head. UAT deployment and manual acceptance are separate pending steps.
