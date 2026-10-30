# PHOS access, mobile and security update

This update addresses password recovery, role-based access, mobile layouts, delivery signers and IST defaults. It is prepared for **UAT only**. It does not authorize a production deployment, rewrite existing role records, migrate historical timestamps or change financial calculations.

## Resulting behaviour

| Area | Behaviour |
| --- | --- |
| Password recovery | An unconfigured or log-only mailer is shown honestly as unavailable. Eligible, unknown, disabled and throttled accounts receive the same public request response once a delivering mailer is configured. SMTP runs after the HTTP response. |
| Reset links | Links use the configured application URL, never the incoming Host header. Expired and reused tokens fail. Successful resets rotate remember tokens and revoke the account's database sessions. Existing password-hash session validation rejects older credentials. |
| User and role administration | Inactive users and invalid, disabled or foreign-organization assigned roles fail closed. Delegated administrators cannot grant capabilities they do not hold, assign the reserved Super Admin role or change a stronger account/role. The last valid active organization owner is protected. |
| Dashboard | All six tabs remain. Data and exports respect server-side dashboard, financial and record permissions; hiding a card is not the security boundary. Assigned-task dashboards restrict customer names, phone links and detailed queues to assigned work. Team Performance requires effective owner authority. Authorized organization aggregates remain intact. |
| Mobile | Dashboard metrics use readable phone columns; Tasks attention labels and assignees do not overlap. Product text encoding, narrow Rental/Sale/Business Partner layouts and Rental Edit's save-control association are corrected. |
| Signature | A new captured signature requires a trimmed signer name, up to 120 characters. Delivery and pickup review/history show it. Existing unnamed evidence and unable-to-sign reasons remain supported. Names use the existing private proof metadata; no schema migration is needed. |
| IST | New reminder, quotation and availability defaults use the application timezone. Rental calendar-day calculations no longer depend on the browser's DST. Existing explicit UTC timestamp casts and historical snapshot timezones are preserved. |

## Security changes

- Login, recovery, reset and password-change routes have rate limits. Active-session checks enforce account/role status. Older non-database sessions missing the password-hash marker require a fresh sign-in after this update.
- Authenticated and recovery responses are private/no-store. Recovery pages suppress referrers. Secure responses add HSTS without affecting subdomains. A limited CSP restricts framing, base URLs, objects and form destinations while preserving established inline scripts.
- Apache/LiteSpeed rules deny direct public customer/delivery proof namespaces and sensitive dot/dependency files, including existing static files. Private proof retrieval also checks task assignment and organization scope.
- All application CSV exports use a shared writer that neutralizes formula-leading untrusted text, preserves numeric cells and uses standard CSV enclosure.
- PDF requests retain certificate validation; the unconditional HTTPS-error bypass is removed.
- Composer and npm lockfiles include compatible security updates. CI now audits both lockfiles, builds assets, exercises browser checks and runs the full PHP suite. Puppeteer 25 requires **Node 22.12 or newer**; the CI runtime is Node 24.
- The legacy customer-proof migration verifies private bytes before metadata activation or public cleanup. A private journal supports safe cleanup retries. `rentnexis:migrate-proof-files --summary` is a counts-only read-only audit; it must not be combined with `--apply`.

## Compatibility and role policy

Assigned roles are the authority when `role_id` exists; stale legacy role text cannot restore broader access. Legacy users without an assigned role keep their established valid profile. Existing nonempty, unmarked special-permission lists retain additive compatibility. Newly edited roles persist an explicit complete special-permission list, including a clear-all denial.

Legacy profiles and stored role templates have differences in module breadth and actions. This update enforces their effective permissions consistently and does not silently replace them. In particular, the existing aggregate finance-dashboard entitlement is preserved for authorized users; splitting it into different permissions for each financial metric is a separate policy decision.

## Validation and release boundaries

The final integrated local PHP regression run passed **806 tests / 7,813 assertions**. Frontend and Blade compilation and all **24 permanent browser tests** passed. The mobile matrix passed **205 checks on 29 synthetic pages** at 1440, 768, 390 and 320 pixels with no non-GET requests. Another **101 signer browser checks** cover real strokes, mobile field containment, required names, unavailable reasons and preservation across resizing/step changes. Eighteen isolated Apache HTTP cases verified both denials and allowed images/ACME paths.

The separate SMTP utility passed **20 offline tests** on Windows with one directory-symlink test skipped; Linux CI exercises that case. Tests use mocked SMTP/HTTP, not real credentials or message delivery.

Both final dependency audits reported no known advisories at the time of review. This is a snapshot of those registries, not a guarantee against all attacks. Tests use synthetic records; they do not establish the state of a deployed server.

UAT activation requires the separately collected read-only runtime report, a pinned source/dependency/asset package, verified backups and recovery, and a passing CI result for the reviewed commit. The previous file-only dashboard/compact-page deployment helpers are not suitable for this dependency update. Do not use them for this release.

## Operator verification still required

1. Verify effective UAT IST, HTTPS/session settings, PHP/extensions, reset-table existence, configured PDF Node/browser versions and Chromium isolation from the read-only diagnostic. Do not change the shared server's global Node/browser runtime by guesswork.
2. Deploy the reviewed UAT application and dependency package, then verify login, permissions, dashboard/mobile pages, private proof access and a controlled invoice PDF.
3. Configure **support@primehealers.com** with the separate [private Zoho setup utility](../ops/phos-access-security-uat/README.md). The operator supplies the account's exact SMTP host and app-specific password privately in the terminal. The utility authenticates once inside verified TLS, changes only eight mail keys, backs up and supports rollback; it sends no email.
4. Manually request one UAT reset to an inbox controlled by the operator. Check the sender, UAT HTTPS link, reset, login and token reuse without sharing the token or password.
5. Run the counts-only legacy-proof audit. Review any old public locations, missing files, conflicting copies or pending cleanup before a separate backed-up migration. Arbitrary orphan files cannot safely be identified or deleted from customer metadata alone.

The Linux PDF sandbox default is an outstanding runtime risk until the server report and an actual sandboxed PDF check establish what is supported. This update does not claim that server patching, firewall/WAF, recovery readiness, DNS/mail authentication, arbitrary historical public files or all third-party integrations have been audited. Production remains excluded.
