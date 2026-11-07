# CampusFind

CampusFind is a unified campus platform bringing together three services:

- **Lost & Found** — report, search for, and recover lost belongings.
- **Campus Notices** — access clear, organized campus announcements.
- **Find a Mentor** — discover mentors and build meaningful connections.

The shared account foundation remains in the existing `users` and `roles` tables. Lost & Found, Campus Notices, and Mentorship remain separate services using the same account and database connection. Campus Notices and Mentorship currently have authenticated starter pages; their workflows are not implemented.

## Local setup with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. For a fresh installation, import [`database/campusfind.sql`](database/campusfind.sql) in phpMyAdmin. It creates the `campusfind` database and the foundational Lost & Found schema.
3. Copy `.env.example` to `.env` and set `DB_USERNAME` and `DB_PASSWORD` to match your local MySQL account. The defaults work with a standard XAMPP installation where `root` has no password.
4. Include `config/database.php` from PHP code to create the PDO connection in `$pdo`.

The `.env` file is local configuration and is excluded from Git. The SQL script is intended for an empty database and seeds the initial roles, categories, and system settings.

## Authentication

Open `http://localhost/campusfind/public/register.php` to create a student or staff account, then sign in at `http://localhost/campusfind/public/login.php`. Passwords are stored as hashes. Students and staff are sent to the protected account page at `http://localhost/campusfind/public/dashboard.php`; admin and super-admin accounts are sent to the protected Lost & Found console at `http://localhost/campusfind/lostandfound/admin/dashboard.php`. Sign out using the page’s form.

## Database additions

The approved additive migration [`database/migrations/001_add_notices_and_mentorship.sql`](database/migrations/001_add_notices_and_mentorship.sql) adds the Campus Notices and Mentorship tables without altering existing tables or records. Back up the database, then apply this migration once to the existing `campusfind` database. It reuses `users` and does not create another authentication or notification system. The live schema uses the singular `notification` and `activity` tables; no `notifications` or `activity_logs` tables exist.

## Landing page

Open `http://localhost/campusfind/` to view the responsive three-service landing page. Each card opens its own module: Lost & Found at `/campusfind/lostandfound/`, Campus Notices at `/campusfind/notices/`, and Mentorship at `/campusfind/mentorship/`. The Notices and Mentorship pages are authenticated starter skeletons. Lost & Found administration is in `/campusfind/lostandfound/admin/`.

## Admin module

The Lost & Found admin console uses the shared role check against the current `users` and `roles` records. It supports dashboard summaries, claim review, item management, and recovered-item handover using the existing `items` and `claims` schema. The admin pages do not rely on AI claim fields or an `under_review` status.
