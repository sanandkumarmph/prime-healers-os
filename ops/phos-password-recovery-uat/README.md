# Password recovery and Zoho mail — UAT only

This first release contains only password-recovery behaviour and mail setup. It
does not include role redesign, mobile changes, signatures, date handling, PDF
changes, dependency upgrades or database migrations.

The application shows an honest unavailable message until a delivering mailer is
configured. Eligible and unknown addresses receive the same public request
response; links use the configured application URL. A successful reset revokes
only that account's database sessions and remember token.

`setup-zoho-mail.py` configures the fixed support mailbox in the fixed UAT
application. It verifies encrypted SMTP authentication without sending email,
preserves unrelated settings, and saves private recovery information. Enter the
account's exact documented SMTP host and app password directly in the server
terminal. Do not put credentials in chat, command arguments or repository files.

Run it only through the separately verified, pinned operator command after the
password-recovery source release is active. Application configuration must
require TLS. The helper changes exactly eight mail settings, rebuilds the
configuration cache, and verifies unrelated effective configuration remains
unchanged. It never updates shared software or accesses production.

Acceptance is a controlled request from the Forgot password page, receipt of the
email, one successful reset, rejection of the used link, and a normal login.
The utility's SMTP authentication test alone does not establish inbox delivery.
