# Owner-managed staff accounts

Sign in as the shop owner and open **Settings → Staff & Permissions**.

- **Add staff**: enter the name, unique login email, password and confirmation. New accounts are active and start with no permissions. Share credentials with the staff member directly; the application does not send email.
- **Save permissions**: grant only required access on that staff card. POS needs both **Access POS** and **Create sales & bills**. Discounts, credit, cancellation, printing, inventory and reports remain separate grants.
- **Edit details**: change name or login email. Name-only edits keep existing sessions; an email change signs them out. Password and permissions are preserved.
- **Reset password**: enter and confirm the new password, then explicitly confirm the reset. Existing sessions stop working. Passwords cannot be recovered or viewed.
- **Deactivate / Activate**: confirm the desired status. Deactivation blocks sign-in, revokes existing sessions and preserves permissions and transaction references. Reactivation requires a fresh sign-in and retains the saved permissions. No staff records are deleted.

All actions use save/cancel popups. Escape/Cancel closes a popup and clears its password fields. Non-JavaScript links open equivalent standard forms. Server validation remains authoritative, and failed submissions never echo passwords back into HTML or JSON.

Only owners can manage staff in their own shop. Posted role/shop IDs cannot create an owner/admin or move accounts between shops; the backend fixes role to `staff` and derives shop from the authenticated owner. Owner/admin accounts cannot be edited through these routes. Existing permission update URLs and AJAX behaviour are retained.

## Setup and session behaviour

```sh
/Applications/XAMPP/xamppfiles/bin/php database/migrations/staff-management.php
```

This idempotent migration adds `users.session_version` with default 1. It preserves accounts, password hashes, permissions and business records. It is also included in the base schema for fresh installs.

Login stamps the current version into the session; each authenticated request compares it against the database. Password reset, email change and status changes increment the version transactionally. Old sessions therefore cannot resume even if an account is reactivated. Existing pre-upgrade sessions remain compatible while their database version is still 1; omitting a version cannot bypass a later reset. Inactive users also fail the existing active-account check.

Passwords use the same 12–72-byte policy as the existing account creation script and `password_hash(PASSWORD_DEFAULT)` / `password_verify`. No plaintext passwords, password retrieval, public registration or self-service password reset is introduced. Failed account writes roll back. Unique email constraints prevent duplicate accounts during repeated/concurrent creation.

Initial owner/superadmin provisioning can still use the existing CLI script. Owners do not need that script for day-to-day staff management.

## Files and tests

- `app/Services/StaffAccountService.php`: owner/shop checks, identity/password validation and account transactions.
- `app/Controllers/StaffController.php`, `routes/web.php`: account form/write routes alongside existing permissions.
- `app/Core/Auth.php`, `app/Controllers/AuthController.php`: session-version validation/stamping.
- `app/Models/User.php`: safe staff profile/status/last-login listing; no hashes returned.
- `resources/views/settings/staff*.php`, `public/assets/{js/staff.js,css/staff.css}`, shared layout: responsive management UI and fallback forms.
- `database/schema.sql`, `database/migrations/staff-management.php`: additive session version.
- `tests/Staff{Test.php,HttpTest.py,BrowserTest.mjs}`, updated authentication fixtures and workspace expectations: account validation, permissions, CSRF, role/shop isolation, credential login, revocation, deactivation/reactivation, popup workflows and mobile layouts.

```sh
RUN_STAFF_TESTS=1 RUN_STAFF_BROWSER=1 /Applications/XAMPP/xamppfiles/bin/php tests/StaffTest.php
```

The suite creates/drops its own database and starts an isolated HTTP server. Browser checks exercise creation/edit/reset, cancelled and failed forms, permission saves, status changes and widths 360/390/430. No staff credentials are changed in the working shop by these tests.
