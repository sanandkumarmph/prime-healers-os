# Compact PHOS operational pages

The eight daily-work lists share a compact PHOS header and one closed filter
control immediately before their Create action: Rentals, Sales, Customers,
Business Partners, Renewal Center, Pickup Center, Communication Center and Tasks
Board. The filter dot identifies an applied filter. Opening the control exposes
the existing GET form, including its current values and Reset destination. The
native details control and forms also work without JavaScript.

The lists retain their decision tabs, counts, bulk selectors, exports, linked
reports and workflow actions. Familiar View, Call, WhatsApp and Export controls
use icons with accessible names. Workflow choices retain text. The Rentals
Actions heading and cells scroll with the table rather than staying pinned.
Pickup time subtitles show whole calendar days instead of long fractional-day
values; scheduling and overdue classification still use their existing rules.

The shared application header uses a smaller logo, search and account controls.
Account role and company context remain in the account menu. Page-specific
density styling is limited to the eight index routes; forms, record detail pages
and executive dashboard content retain their existing layouts. Selected tabs use
Prime Healers blue; warning, payment and status colors retain their meaning.

No controller, service, route, permission, metric definition, dependency or
database migration is changed. The previous dashboard and Knowledge Hub sources
and assets are preserved by the separate guarded UAT package in
`ops/phos-compact-pages-uat`. UAT activation and subsequent authenticated
acceptance are separate from local testing. Production rollout is outside this
change.

## Verification

The regression fixtures contain synthetic, nonempty records and restricted-role
variants. HTTP tests cover filter values, query-preserving links, exports,
mutating-action permission gates and assigned-only delivery scope. Browser tests
exercise the real shared and legacy filter scripts, native GET Apply/Reset,
Escape, focus, outside dismissal, mobile behavior and JavaScript-disabled forms.

The eight-page visual review uses actual Laravel-rendered HTML and real public
assets. It blocks external requests and non-GET methods, verifies asset status
and MIME type, and checks desktop and phone widths, table-local scrolling, popup
bounds, row density and duplicate page controls. It is a local acceptance check,
not a live UAT business-workflow test.
