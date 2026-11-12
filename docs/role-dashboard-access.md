# Role and dashboard access

This batch makes assigned roles authoritative across login, active sessions, user administration and dashboards. Existing role records, assignments, financial definitions and widget selections are not rewritten.

## Administration

Roles now have a Dashboard access selector:

- **Automatic** retains the existing role/module rules and landing page.
- **Allow** opens the role dashboard after login. Module permissions, financial permissions and widget configuration still apply.
- **Block** prevents dashboard requests and uses the first permitted work page. It does not remove work-module access.

An active Super Admin retains dashboard access. Inactive accounts, inactive assigned roles and invalid organization/role links cannot sign in or continue an authenticated session. Legacy accounts without a role assignment retain their established profile.

User and role details display an effective-access summary. The existing Dashboard Settings page still selects and orders widgets by role; enabling a widget cannot override its permission boundary.

Delegated administrators cannot assign stronger roles, manage stronger accounts or grant permissions they do not hold. The last active Super Admin is protected. Administrative password changes and account deactivation revoke only the target account's database sessions.

## Dashboard boundaries

Executive and Team Performance access follows effective authority rather than a role's display name or stale legacy label. Finance-denied profiles do not receive financial widgets or amounts in dashboard view data and rental CSV exports. Authorized financial calculations and reporting remain unchanged.

Sales rental lists keep their existing own-record scope. Task-facing dashboard queues restrict identifiable customer details to assigned work before applying list limits. Organization-level operational counts retain their established scope and labels.

Saving sensitive role permissions persists the complete selection, including an explicit clear-all denial. Existing unedited legacy defaults remain compatible.

## Release scope

UAT only. No migrations, bulk account/role updates, dependency changes, mail configuration changes, new business features or production activation are included. Password recovery and visibility controls are preserved. Synthetic permission and workflow tests run against isolated databases; operator-driven UAT activation includes backups, cache recovery and runtime checks.
