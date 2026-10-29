# Tabbed executive dashboard

The existing executive command centre now has one Dashboard menu and one selected
view at a time: Overview, Team, Operations, Revenue, Inventory or Activity.

This is a presentation change. Existing metric services, financial calculations,
controller queries, tenant scope, assignment scope and report/export routes are
unchanged. The only controller change redirects the old Business overview URL,
retaining its query parameters, to the unified dashboard.

## Content preservation

- Overview retains the executive health indicators, command cards, communication
  pulse, upcoming workload, business performance and recent-activity preview.
- The former compact view's six figures, four attention queues and invoice-aging bands remain accessible
  within Overview using the same widget and module permission checks.
- Deposit charges use the existing filtered rental sum. Actual deposit receipts,
  refunds and cash held are explicitly not inferred from generic customer payments.
- Team retains all thirteen existing table columns, attribution links, financial
  toggle and period/city/role filters. Role-specific workload and relationship
  signals retain their existing conditions.
- Operations retains lifecycle indicators, priority work, team capacity, logistics
  and the Critical/Today/This Week record queues.
- Revenue retains its complete finance and commercial panels and historical
  charts. Roles that previously saw the standalone business-direction chart but
  have no Revenue tab retain it in Overview.
- Inventory retains availability, readiness, risks, movements, warehouses and
  detailed rankings, including the existing rankings-only role variant.
- Activity retains communication, notifications, unresolved priorities, event
  history and recent customer/rental records.

Previously disabled legacy markup remains disabled. No metric definitions were
silently changed to match the illustrative design. Calculation/label issues
identified during design review require a separately reviewed change.

## Navigation and permissions

`dashboard_tab` is validated against the tabs available under the existing section
visibility predicates. Unknown or unavailable values select Overview. Inaccessible
panels are not rendered; hiding a panel is never an authorization mechanism.
Delivery-facing roles retain their existing assigned-task screen.

Tabs are real links preserving existing query filters. Without JavaScript the
server renders only the selected panel as visible. With JavaScript, tab switching
retains forms and expanded details, updates the URL, and supports Back/Forward and
keyboard navigation. Existing section-fragment links open their owning panel and
native disclosures. Global dashboard filters remain available above every tab.

The sidebar consolidates only Business overview/Dashboard. Other destinations,
ordering and permission conditions remain intact. Logo-derived blue, burgundy and
green are scoped to the dashboard route; other module layouts are unchanged.

## UAT scope

The rollout is restricted to the seven runtime paths listed in
`ops/phos-tabbed-dashboard-uat/README.md`. It requires no migration, dependency
installation, report change or business-data transformation. The guarded UAT
package verifies the previously deployed source bytes and backs up only the
reviewed code/assets for recovery. Production deployment is not authorized.
