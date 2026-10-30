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
native disclosures. Fragment links that change the query string navigate through
the server so filter resets and drilldowns load the corresponding results.
Global dashboard filters remain available from the compact icon immediately before
Detailed reports. The activity-period selector stays visible above the tabs, with
the selected range and native links for Today, Last 7 days, This month, Last month
and All dates. Custom range opens the same existing date inputs. Existing default
date scope and per-metric reporting periods are unchanged. Filters and date presets
work without JavaScript; enhanced controls support Escape and outside-click close.

Main prioritizes Dashboard, Rentals, Sales, Sales workspace, Customers, Business
Partners, Collections & cash and Rental availability. Knowledge Hub in Help contains
the permission-aware Workflow guides; existing guide URLs continue to work. Other
destinations, badges and permission conditions remain intact.
The shared layout now applies the same Prime Healers sidebar and topbar styling on
every PHOS page. Individual modules retain their own content presentation.

## UAT scope

The rollout is restricted to the nine runtime paths listed in
`ops/phos-tabbed-dashboard-uat/README.md`. It requires no migration, dependency
installation, report change or business-data transformation. The guarded UAT
package verifies the previously deployed source bytes and backs up only the
reviewed code/assets for recovery. Production deployment is not authorized.
