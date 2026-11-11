<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/notices.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireModuleAdmin('notices');

$noticeId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$noticeId || $noticeId < 1) {
    http_response_code(404);
    exit('Notice not found.');
}

$statement = $pdo->prepare('SELECT * FROM notices WHERE id = :id LIMIT 1');
$statement->execute(['id' => $noticeId]);
$notice = $statement->fetch();
if (!$notice) {
    http_response_code(404);
    exit('Notice not found.');
}

$schoolStatement = $pdo->prepare('SELECT school_id FROM notice_schools WHERE notice_id = :id');
$schoolStatement->execute(['id' => $noticeId]);
$selectedSchools = array_map('intval', $schoolStatement->fetchAll(PDO::FETCH_COLUMN));
$departmentStatement = $pdo->prepare('SELECT department_id FROM notice_departments WHERE notice_id = :id');
$departmentStatement->execute(['id' => $noticeId]);
$selectedDepartments = array_map('intval', $departmentStatement->fetchAll(PDO::FETCH_COLUMN));

$error = '';
$success = '';
$fields = [
    'title', 'summary', 'what_happening', 'who_affected',
    'action_required', 'location', 'contact_info', 'body',
];
$form = [];
foreach ($fields as $field) {
    $form[$field] = (string) ($notice[$field] ?? '');
}
$form['category_id'] = (string) $notice['category_id'];
$form['audience'] = $notice['audience'];
$form['priority'] = $notice['priority'];
$form['status'] = $notice['status'];
$form['is_pinned'] = (bool) $notice['is_pinned'];
$form['published_at'] = noticeFormatDateTime($notice['published_at'], 'Y-m-d\TH:i:s');
$form['expires_at'] = noticeFormatDateTime($notice['expires_at'], 'Y-m-d\TH:i:s');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    foreach ($fields as $field) {
        $value = $_POST[$field] ?? '';
        if (!is_string($value)) {
            $error = 'Invalid announcement form data.';
            break;
        }
        $form[$field] = trim($value);
    }
    foreach (['category_id', 'audience', 'priority', 'status', 'published_at', 'expires_at'] as $field) {
        $value = $_POST[$field] ?? '';
        if (!is_string($value)) {
            $error = 'Invalid announcement form data.';
            break;
        }
        $form[$field] = trim($value);
    }
    $form['is_pinned'] = isset($_POST['is_pinned']) && $_POST['is_pinned'] === '1';

    $readIds = static function (mixed $values): array {
        if (!is_array($values)) {
            throw new RuntimeException('Invalid school or department selection.');
        }
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new RuntimeException('Invalid school or department selection.');
            }
            $ids[] = $id;
        }
        return array_values(array_unique($ids));
    };

    try {
        if ($error !== '') {
            throw new RuntimeException($error);
        }
        $selectedSchools = $readIds($_POST['school_ids'] ?? []);
        $selectedDepartments = $readIds($_POST['department_ids'] ?? []);
        if ($form['title'] === '' || strlen($form['title']) > 200 || $form['body'] === '') {
            throw new RuntimeException('Enter a title of up to 200 characters and a message.');
        }
        foreach ([
            'summary' => 300, 'what_happening' => 500, 'who_affected' => 500,
            'action_required' => 500, 'location' => 255, 'contact_info' => 255,
        ] as $field => $limit) {
            if (strlen($form[$field]) > $limit) {
                throw new RuntimeException('One or more structured fields are too long.');
            }
        }
        $categoryId = filter_var($form['category_id'], FILTER_VALIDATE_INT);
        if (!$categoryId || !in_array($form['audience'], ['all', 'students', 'staff'], true)
            || !in_array($form['priority'], ['low', 'normal', 'high', 'urgent'], true)
            || !in_array($form['status'], ['draft', 'published', 'archived'], true)) {
            throw new RuntimeException('Select valid category, audience, priority, and status values.');
        }

        $checkCategory = $pdo->prepare(
            "SELECT id FROM notice_categories WHERE id = :id AND status = 'active'"
        );
        $checkCategory->execute(['id' => $categoryId]);
        if (!$checkCategory->fetchColumn()) {
            throw new RuntimeException('The selected category is unavailable.');
        }

        if ($selectedSchools) {
            $marks = implode(',', array_fill(0, count($selectedSchools), '?'));
            $check = $pdo->prepare("SELECT id FROM schools WHERE status = 'active' AND id IN ({$marks})");
            $check->execute($selectedSchools);
            if (count($check->fetchAll(PDO::FETCH_COLUMN)) !== count($selectedSchools)) {
                throw new RuntimeException('One or more selected schools are invalid.');
            }
        }
        if ($selectedDepartments) {
            $marks = implode(',', array_fill(0, count($selectedDepartments), '?'));
            $check = $pdo->prepare(
                "SELECT id, school_id FROM departments WHERE id IN ({$marks})"
            );
            $check->execute($selectedDepartments);
            $validDepartments = $check->fetchAll();
            if (count($validDepartments) !== count($selectedDepartments)) {
                throw new RuntimeException('One or more selected departments are invalid.');
            }
            foreach ($validDepartments as $department) {
                if ($selectedSchools && !in_array((int) $department['school_id'], $selectedSchools, true)) {
                    throw new RuntimeException('Each selected department must belong to a selected school.');
                }
            }
        }

        $publishedAt = null;
        if ($form['status'] === 'published') {
            $databaseNow = (string) $pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            if ($form['published_at'] === '') {
                $publishedAt = $databaseNow;
            } else {
                $publishedAt = noticeParseLocalDateTime($form['published_at']);
                if ($publishedAt === null) {
                    throw new RuntimeException('Enter a valid publication date.');
                }
            }
        } elseif ($form['status'] === 'archived') {
            $publishedAt = $notice['published_at'];
        }
        $expiresAt = null;
        if ($form['expires_at'] !== '') {
            $expiresAt = noticeParseLocalDateTime($form['expires_at']);
            if ($expiresAt === null) {
                throw new RuntimeException('Enter a valid expiry date.');
            }
        }
        if ($expiresAt !== null && $publishedAt !== null && $expiresAt <= $publishedAt) {
            throw new RuntimeException('Expiry must be after the publication date.');
        }
        if ($form['status'] === 'published' && $expiresAt !== null && $expiresAt <= $databaseNow) {
            throw new RuntimeException('A published notice must have an expiry date in the future.');
        }

        $pdo->beginTransaction();
        $update = $pdo->prepare(
            'UPDATE notices
             SET category_id = :category_id, title = :title, summary = :summary,
                 what_happening = :what_happening, who_affected = :who_affected,
                 action_required = :action_required, location = :location,
                 contact_info = :contact_info, body = :body, audience = :audience,
                 priority = :priority, is_pinned = :is_pinned, status = :status,
                 published_at = :published_at, expires_at = :expires_at
             WHERE id = :id'
        );
        $update->execute([
            'category_id' => $categoryId,
            'title' => $form['title'],
            'summary' => $form['summary'] !== '' ? $form['summary'] : null,
            'what_happening' => $form['what_happening'] !== '' ? $form['what_happening'] : null,
            'who_affected' => $form['who_affected'] !== '' ? $form['who_affected'] : null,
            'action_required' => $form['action_required'] !== '' ? $form['action_required'] : null,
            'location' => $form['location'] !== '' ? $form['location'] : null,
            'contact_info' => $form['contact_info'] !== '' ? $form['contact_info'] : null,
            'body' => $form['body'],
            'audience' => $form['audience'],
            'priority' => $form['priority'],
            'is_pinned' => $form['is_pinned'] ? 1 : 0,
            'status' => $form['status'],
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt,
            'id' => $noticeId,
        ]);

        $pdo->prepare('DELETE FROM notice_schools WHERE notice_id = :id')->execute(['id' => $noticeId]);
        $pdo->prepare('DELETE FROM notice_departments WHERE notice_id = :id')->execute(['id' => $noticeId]);
        $insertSchool = $pdo->prepare('INSERT INTO notice_schools (notice_id, school_id) VALUES (:notice_id, :school_id)');
        foreach ($selectedSchools as $schoolId) {
            $insertSchool->execute(['notice_id' => $noticeId, 'school_id' => $schoolId]);
        }
        $insertDepartment = $pdo->prepare('INSERT INTO notice_departments (notice_id, department_id) VALUES (:notice_id, :department_id)');
        foreach ($selectedDepartments as $departmentId) {
            $insertDepartment->execute(['notice_id' => $noticeId, 'department_id' => $departmentId]);
        }
        notifyNoticeAudience((int) $noticeId);
        $pdo->commit();
        $success = 'Notice updated.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (!$exception instanceof RuntimeException) {
            error_log('Update notice failed: ' . $exception->getMessage());
        }
        $error = $exception instanceof RuntimeException
            ? $exception->getMessage()
            : 'Unable to update this notice.';
    }
}

$categories = $pdo->query(
    "SELECT id, category_name FROM notice_categories WHERE status = 'active' ORDER BY category_name"
)->fetchAll();
$schools = $pdo->query(
    "SELECT id, school_name FROM schools WHERE status = 'active' ORDER BY school_name"
)->fetchAll();
$departments = $pdo->query(
    "SELECT d.id, d.school_id, d.department_name, s.school_name
     FROM departments d INNER JOIN schools s ON s.id = d.school_id
     WHERE s.status = 'active' ORDER BY s.school_name, d.department_name"
)->fetchAll();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit notice | CampusFind</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/modules.css">
    <link rel="stylesheet" href="<?= noticeEscape(NOTICES_URL) ?>/notices.css">
    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>
<body>
    <header class="notice-header"><a class="brand" href="<?= noticeEscape(NOTICES_URL) ?>/admin/">Notices administration</a></header>
    <main class="notice-main admin-main">
        <a class="back-link" href="<?= noticeEscape(NOTICES_URL) ?>/admin/">← Admin dashboard</a>
        <h1>Edit notice</h1>
        <?php if ($error !== ''): ?><p class="form-message error-message" role="alert"><?= noticeEscape($error) ?></p><?php endif; ?>
        <?php if ($success !== ''): ?><p class="form-message success-message" role="status"><?= noticeEscape($success) ?></p><?php endif; ?>
        <form class="editor-card" method="post">
            <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
            <label>Title<input name="title" maxlength="200" value="<?= noticeEscape($form['title']) ?>" required></label>
            <label>Short summary<input name="summary" maxlength="300" value="<?= noticeEscape($form['summary']) ?>"></label>
            <?php foreach ([
                'what_happening' => 'What is happening?',
                'who_affected' => 'Who is affected?',
                'action_required' => 'What action is required?',
                'location' => 'Location',
                'contact_info' => 'Contact information',
            ] as $field => $label): ?>
                <label><?= noticeEscape($label) ?><input name="<?= noticeEscape($field) ?>" value="<?= noticeEscape($form[$field]) ?>"></label>
            <?php endforeach; ?>
            <label>Full notice<textarea name="body" rows="8" required><?= noticeEscape($form['body']) ?></textarea></label>
            <div class="editor-grid">
                <label>Category<select name="category_id" required>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (int) $form['category_id'] === (int) $category['id'] ? 'selected' : '' ?>><?= noticeEscape($category['category_name']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Audience<select name="audience">
                    <?php foreach (['all' => 'Everyone', 'students' => 'Students', 'staff' => 'Staff'] as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $form['audience'] === $key ? 'selected' : '' ?>><?= noticeEscape($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Priority<select name="priority">
                    <?php foreach (['low', 'normal', 'high', 'urgent'] as $key): ?>
                        <option value="<?= $key ?>" <?= $form['priority'] === $key ? 'selected' : '' ?>><?= noticeEscape(noticePriorityLabel($key)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Status<select name="status">
                    <?php foreach (['draft', 'published', 'archived'] as $key): ?>
                        <option value="<?= $key ?>" <?= $form['status'] === $key ? 'selected' : '' ?>><?= noticeEscape(ucfirst($key)) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Publish date<input type="datetime-local" step="1" name="published_at" value="<?= noticeEscape($form['published_at']) ?>"><small>Times use CampusFind server local time.</small></label>
                <label>Expiry date<input type="datetime-local" step="1" name="expires_at" value="<?= noticeEscape($form['expires_at']) ?>"><small>Times use CampusFind server local time.</small></label>
            </div>
            <label class="inline-check"><input type="checkbox" name="is_pinned" value="1" <?= $form['is_pinned'] ? 'checked' : '' ?>> Pin this notice</label>
            <fieldset>
                <legend>Target schools <small>(none selected means all schools)</small></legend>
                <div class="check-grid">
                    <?php foreach ($schools as $school): ?>
                        <label class="inline-check"><input class="school-checkbox" type="checkbox" name="school_ids[]" value="<?= (int) $school['id'] ?>" <?= in_array((int) $school['id'], $selectedSchools, true) ? 'checked' : '' ?>> <?= noticeEscape($school['school_name']) ?></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset>
                <legend>Target departments <small>(none selected means all departments in selected schools)</small></legend>
                <div class="check-grid">
                    <?php foreach ($departments as $department): ?>
                        <label class="inline-check department-option" data-school-id="<?= (int) $department['school_id'] ?>">
                            <input class="department-checkbox" type="checkbox" name="department_ids[]" value="<?= (int) $department['id'] ?>" <?= in_array((int) $department['id'], $selectedDepartments, true) ? 'checked' : '' ?>>
                            <?= noticeEscape($department['department_name']) ?> <small><?= noticeEscape($department['school_name']) ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="form-actions"><button class="button button-primary" type="submit">Save changes</button><a class="button button-outline" href="<?= noticeEscape(NOTICES_URL) ?>/admin/">Cancel</a></div>
        </form>
    </main>
    <script>
        const schools = document.querySelectorAll('.school-checkbox');
        const departments = document.querySelectorAll('.department-option');
        function filterDepartments() {
            const selected = Array.from(schools).filter((input) => input.checked).map((input) => input.value);
            departments.forEach((option) => {
                const visible = selected.length === 0 || selected.includes(option.dataset.schoolId);
                option.hidden = !visible;
                if (!visible) option.querySelector('input').checked = false;
            });
        }
        schools.forEach((input) => input.addEventListener('change', filterDepartments));
        filterDepartments();
    </script>
</body>
</html>
