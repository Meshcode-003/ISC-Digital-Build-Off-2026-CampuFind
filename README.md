# CampusFind

CampusFind is a campus lost-and-found application.

## Local setup with XAMPP

1. Start Apache and MySQL from the XAMPP Control Panel.
2. Import [`database/campusfind.sql`](database/campusfind.sql) in phpMyAdmin. It creates the `campusfind` database and the tables modeled on the existing lost-and-found project.
3. Copy `.env.example` to `.env` and set `DB_USERNAME` and `DB_PASSWORD` to match your local MySQL account. The defaults work with a standard XAMPP installation where `root` has no password.
4. Include `config/database.php` from PHP code to create the PDO connection in `$pdo`.

The `.env` file is local configuration and is excluded from Git. The SQL script is intended for an empty database and seeds the initial roles, categories, and system settings.
