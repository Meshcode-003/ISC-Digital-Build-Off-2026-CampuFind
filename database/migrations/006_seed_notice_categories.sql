-- Seed the initial Notices categories without replacing administrator-managed rows.
USE campusfind;

INSERT INTO notice_categories (category_name, description)
SELECT seed.category_name, seed.description
FROM (
    SELECT 'General Announcement' AS category_name,
           'General campus information and notices' AS description
    UNION ALL SELECT 'Academic',
           'Academic schedules, examinations and results'
    UNION ALL SELECT 'Events',
           'Campus events, meetings and activities'
    UNION ALL SELECT 'Fees and Finance',
           'Fees payments, financial information and deadlines'
    UNION ALL SELECT 'Urgent Notice',
           'Important and urgent campus announcements'
    UNION ALL SELECT 'Student Affairs',
           'Student welfare and student-related information'
    UNION ALL SELECT 'Staff Information',
           'Information intended for university staff'
) AS seed
WHERE NOT EXISTS (
    SELECT 1
    FROM notice_categories existing
    WHERE existing.category_name = seed.category_name
);
