# CampusFind

CampusFind is a unified campus platform bringing together three services:

- **Lost & Found** — report, search for, and recover lost belongings.
- **Campus Notices** — access clear, organized campus announcements.
- **Find a Mentor** — discover mentors and build meaningful connections.

The shared account foundation remains in the existing `users` and `roles` tables. Lost & Found, Campus Notices, and Mentorship remain separate services using the same account and database connection. The Mentorship module supports mentor applications and admin review, mentor discovery and profiles, connection requests, private messaging, and session scheduling. Campus Notices remains a separate starter module.

## Local setup with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. For a fresh installation, import [`database/campusfind.sql`](database/campusfind.sql) in phpMyAdmin. It creates the `campusfind` database and the foundational Lost & Found schema.
3. Copy `.env.example` to `.env` and set `DB_USERNAME` and `DB_PASSWORD` to match your local MySQL account. The defaults work with a standard XAMPP installation where `root` has no password.
4. Include `config/database.php` from PHP code to create the PDO connection in `$pdo`.

The `.env` file is local configuration and is excluded from Git. The SQL script is intended for an empty database and seeds the initial roles, categories, and system settings.

## Authentication

Open `http://localhost/campusfind/public/register.php` to create a student or staff account, then visit `http://localhost/campusfind/` to sign in. The root `index.php` serves the shared login form. After sign-in, student and staff accounts are sent to the three-service landing page, Super Admins go to administrator registration, and admins are redirected to their single assigned module or a module chooser when they have multiple assignments. An admin with no assignments is told to contact a Super Admin. Passwords are stored as hashes.

## Database additions

The additive migrations in [`database/migrations/`](database/migrations/) preserve existing tables and records:

1. [`001_add_notices_and_mentorship.sql`](database/migrations/001_add_notices_and_mentorship.sql) adds the Campus Notices tables and the core mentor profile, expertise, and request tables.
2. [`002_mentorship_workflows.sql`](database/migrations/002_mentorship_workflows.sql) adds mentor applications and application expertise, WhatsApp contact preferences, conversations, messages, and sessions. It also creates a conversation for any already-accepted mentorship request.
3. [`003_module_admin_assignments.sql`](database/migrations/003_module_admin_assignments.sql) stores administrator-to-module assignments for Lost Items, Notices, and Mentorship.

Back up the database, then apply unapplied migrations in order to the existing `campusfind` database. Migrations 001 and 002 have already been applied in the current XAMPP environment; migration 003 is applied as part of this deployment. These migrations reuse `users` and do not create another authentication or notification system. The application uses the existing singular `notification` table for user alerts.

## Super Admin: administrator registration

The Super Admin can register an administrator at `/campusfind/superadmin/admins/create.php`. The form creates an account using the existing `users` table and password hash, records one or more module assignments in `module_admin_assignments`, and writes an `ADMIN_CREATED` entry to the existing `activity` table in the same transaction. Super Admins are sent to this page after login.

The live database initially had no Super Admin account. To bootstrap the first one, open PowerShell in the CampusFind installation and set a process-only password before running the CLI script. Enter the name and account details at the prompts; the password is not echoed or stored in the script:

```powershell
$env:CAMPUSFIND_SUPERADMIN_PASSWORD = Read-Host "Initial Super Admin password" -AsSecureString | `
    ForEach-Object { [System.Net.NetworkCredential]::new("", $_).Password }
C:\xampp\php\php.exe C:\xampp\htdocs\campusfind\database\create_superadmin.php
Remove-Item Env:CAMPUSFIND_SUPERADMIN_PASSWORD
```

The bootstrap script is CLI-only and refuses to create a second Super Admin. Module assignment storage is implemented in this slice; enforcement of those assignments across every module-admin route is a subsequent phase. Until that authorization work is complete, do not treat assignments as effective access restrictions or provision module administrators for production access.

## Mentorship workflows

- Signed-in student and staff accounts can browse active mentors, search by name or expertise, and send a request. A mentor application must be approved by an admin or super admin before the profile becomes discoverable.
- Applicants submit a headline, biography, qualifications, experience, and expertise. Admins review applications at `/campusfind/mentorship/admin/applications.php`; an approval creates the mentor profile and copies its expertise in one database transaction. A rejection includes feedback and sends an in-app notification.
- Mentors manage their profile, availability, discovery visibility, and WhatsApp opt-in at `/campusfind/mentorship/profile.php`. WhatsApp contact is shown only when the mentor explicitly opts in and has a valid number on their shared account; the number itself is not displayed on the profile.
- Both sides can track requests at `/campusfind/mentorship/requests.php`. Mentors can accept or decline pending requests, students can cancel pending requests, and either participant can mark an accepted connection complete.
- A conversation is created when a request is accepted. Only the two participants in an accepted or completed connection can read or send messages. The page refreshes new messages periodically.
- Participants in an accepted connection can schedule, complete, or cancel sessions at `/campusfind/mentorship/sessions.php?id=<request-id>`. Online links must use HTTPS. Times are interpreted in the CampusFind server's local timezone.
- Mutating forms use the existing session authentication and CSRF protection. User content is validated and escaped; request and conversation access is checked against the signed-in user on every route.

## Landing page

Open `http://localhost/campusfind/` to sign in. Student and staff accounts are redirected to the responsive three-service landing page at `/campusfind/index.html`; each card opens its own module: Lost & Found at `/campusfind/lostandfound/`, Campus Notices at `/campusfind/notices/`, and Mentorship at `/campusfind/mentorship/`. Super Admins are redirected to administrator registration; regular admins continue to the Lost & Found admin console at `/campusfind/lostandfound/admin/`. The Notices page remains a separate starter skeleton.

## Admin module

The Lost & Found admin console uses the shared role check against the current `users` and `roles` records. It supports dashboard summaries, claim review, item management, and recovered-item handover using the existing `items` and `claims` schema. The admin pages do not rely on AI claim fields or an `under_review` status.
