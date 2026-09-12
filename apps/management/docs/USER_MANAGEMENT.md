# Management users

Implements SPEC FR-USER-001–005, approved 2026-09-06. This is staff access for one business,
not customer records, tenant provisioning, Chatwoot accounts or an AI user-administration API.

## Operator workflow

1. Open **จัดการผู้ใช้** at `/admin/users` as an administrator.
2. Select **เพิ่มผู้ใช้**, enter a name and login email, and choose the minimum necessary role.
3. Save, then select **สร้างลิงก์ตั้งรหัสผ่าน** and **คัดลอกลิงก์ส่วนตัว**.
4. Send the link privately to that person. Do not paste it into AI chats, tickets or public docs.
5. The recipient sets a password of at least 12 characters and signs in.

There is no automatic email delivery in this slice. The default password-link lifetime is 60 minutes,
using Laravel's configured password broker. Creating another link invalidates the preceding link.
Tokens are hashed in `password_reset_tokens`; consumed tokens cannot be reused.

The link keeps email/token in the browser fragment, not the request path or query. The reset page
reads and removes the fragment, has no external assets, sets `Referrer-Policy: no-referrer`, and sends
credentials only in the CSRF-protected POST body. Do not enable request-body logging, session replay
or analytics on authentication pages. Reset tokens are excluded from validation flash input.

## Permissions

| Capability | Admin | Editor | Viewer |
|---|---|---|---|
| Read catalog/category/FAQ/knowledge lists | Yes | Yes | Yes |
| Add/edit/delete business content and use imports | Yes | Yes | No |
| Manage business profile | Yes | Yes | No |
| Manage private document intake | Yes | No | No |
| Issue/revoke API or MCP keys | Yes | No | No |
| Manage staff users/password links | Yes | No | No |

Authorization is enforced in middleware, request validation and content policies, not only navigation.
The viewer role currently reads list views; edit-form detail routes remain blocked.
User records cannot be hard-deleted through the UI/API. Disable them instead.

## Account and credential lifecycle

- An enabled administrator must always remain. Account access changes use database transactions and
  a stable row lock before counting enabled admins. MySQL multi-connection stress testing is still pending.
- Disabling an account rejects new logins and existing sessions. A monotonically increasing session
  version also invalidates old sessions after role/email changes and password resets.
- New keys issued from the web UI include `api_tokens.user_id`. Access changes revoke owned keys.
  Owner-bound key validation independently checks that its owner remains an enabled administrator.
- **Existing ownerless keys and CLI-issued service keys are not guessed or reassigned.** They remain
  service credentials and are unaffected by disabling a staff account. Review/revoke these separately.
- Re-enabling a user does not reactivate revoked keys. The user must sign in again.
- Audit records contain only actor ID, target ID, event and timestamp. The UI shows the latest 20 events.
  This is account-management history, not catalog revision history or AI CRUD auditing.
- Database/API access for AI is unchanged: no SQL access and no user-management MCP tools.

## Migration and deployment gate

Migration: `2026_09_06_000001_add_staff_access_management.php`.
Adds nullable staff role, enabled state and session version to users, optional owner to API keys,
and the `user_management_events` table. Existing `is_admin` accounts keep administrator access;
legacy non-admin accounts without an explicit staff role have no admin-area access.

Before production deployment:

1. Obtain approval for the exact production migration/deployment. Back up Management MySQL and record
   the current image/release. Do not run migrations/seeding implicitly during container startup.
2. Build and verify on the approved VM, not local Docker. Apply the single reviewed migration before
   switching the Management application image. Do not reseed or change existing passwords.
3. Verify login, existing admin access, user pages, role boundaries and password setup with a controlled
   test account. No production accounts or credentials were issued during development verification.
4. Retain the additive schema on rollback. Reverting to an old image also removes new access checks;
   restrict admin traffic during rollback instead of assuming disabled users stay blocked on old code.
   Do not run `migrate:rollback` after audit data exists without an explicit data-loss review.

Frontend verification uses synthetic fixtures. Real mail delivery, production migration, end-to-end
browser form submission and concurrent MySQL demotions must be verified separately.
