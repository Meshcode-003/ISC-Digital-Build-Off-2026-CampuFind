<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireAdmin();

$csrfToken = generateCsrfToken();

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
        
    );
}

$error = '';
$success = '';

$form = [
    'title' => '',
    'body' => '',
    'category_id' => '',
    'audience' => 'all',
    'priority' => 'normal',
    'status' => 'draft',
    'expires_at' => ''
];

$selectedSchools = [];
$selectedDepartments = [];
// Keep track of uploaded files so they can be removed
// if saving the announcement fails.
$uploadedFilePaths = [];
// Load active categories and schools.
$categories = $pdo->query("
    SELECT id, category_name
    FROM notice_categories
    WHERE status = 'active'
    ORDER BY category_name
")->fetchAll(PDO::FETCH_ASSOC);

$schools = $pdo->query("
    SELECT id, school_name
    FROM schools
    WHERE status = 'active'
    ORDER BY school_name
")->fetchAll(PDO::FETCH_ASSOC);

// Load departments with their school names.
$departments = $pdo->query("
    SELECT
        d.id,
        d.school_id,
        d.department_name,
        s.school_name
    FROM departments d
    JOIN schools s ON s.id = d.school_id
    WHERE s.status = 'active'
    ORDER BY s.school_name, d.department_name
")->fetchAll(PDO::FETCH_ASSOC);

/**
 * Validate and save announcement attachments.
 */
function saveNoticeAttachments(
    array $files,
    int $noticeId,
    PDO $pdo,
    array &$uploadedFilePaths
): void {

    if (!isset($files['name']) || !is_array($files['name'])) {
        throw new RuntimeException('Invalid attachment submission.');
    }

    $allowedTypes = [
        'pdf'  => 'application/pdf',

        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

        'xls'  => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

        'ppt'  => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',

        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'webp' => 'image/webp'
    ];

    $storageDirectory = dirname(__DIR__) . '/storage/notice-attachments';

    if (!is_dir($storageDirectory) || !is_writable($storageDirectory)) {
        throw new RuntimeException('Attachment storage folder is unavailable.');
    }

    $fileCount = count($files['name']);

    if ($fileCount > 5) {
        throw new RuntimeException('You can upload a maximum of 5 attachments.');
    }

    $insertAttachment = $pdo->prepare("
        INSERT INTO notice_attachments (
            notice_id,
            uploaded_by,
            file_path,
            original_filename,
            mime_type,
            file_size
        ) VALUES (
            :notice_id,
            :uploaded_by,
            :file_path,
            :original_filename,
            :mime_type,
            :file_size
        )
    ");

    $finfo = finfo_open(FILEINFO_MIME_TYPE);

    if ($finfo === false) {
        throw new RuntimeException('Could not validate uploaded files.');
    }

    try {

        for ($i = 0; $i < $fileCount; $i++) {

            $errorCode = $files['error'][$i] ?? UPLOAD_ERR_NO_FILE;

            // No attachment was selected for this entry.
            if ($errorCode === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($errorCode !== UPLOAD_ERR_OK) {
                throw new RuntimeException(
                    'An attachment could not be uploaded. Please try again.'
                );
            }

            $temporaryPath = $files['tmp_name'][$i] ?? '';
            $originalName = $files['name'][$i] ?? '';
            $fileSize = (int) ($files['size'][$i] ?? 0);

            if (
                !is_string($temporaryPath) ||
                !is_uploaded_file($temporaryPath)
            ) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            if ($fileSize < 1 || $fileSize > 10 * 1024 * 1024) {
                throw new RuntimeException(
                    'Each attachment must be smaller than or equal to 10 MB.'
                );
            }

            // Remove any directory information from the original filename.
            $originalName = basename(
                str_replace('\\', '/', (string) $originalName)
            );

            $extension = strtolower(
                pathinfo($originalName, PATHINFO_EXTENSION)
            );

            if (!isset($allowedTypes[$extension])) {
                throw new RuntimeException(
                    'One or more attachments have an unsupported file extension.'
                );
            }

            // Detect the real file MIME type.
            $mimeType = finfo_file($finfo, $temporaryPath);

            if (
                $mimeType === false ||
                $mimeType !== $allowedTypes[$extension]
            ) {
                throw new RuntimeException(
                    'A file does not match its expected file type.'
                );
            }

            // Generate a random filename to avoid filename collisions.
            $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

            $destination = $storageDirectory . '/' . $storedName;

            if (!move_uploaded_file($temporaryPath, $destination)) {
                throw new RuntimeException(
                    'Could not save an uploaded attachment.'
                );
            }

            // Remember the physical path for possible cleanup.
            $uploadedFilePaths[] = $destination;

            // Save attachment details in MySQL.
            $relativePath = 'storage/notice-attachments/' . $storedName;

            $insertAttachment->execute([
                'notice_id' => $noticeId,
                'uploaded_by' => $_SESSION['user_id'],
                'file_path' => $relativePath,
                'original_filename' => substr($originalName, 0, 255),
                'mime_type' => $mimeType,
                'file_size' => $fileSize
            ]);
        }

    } finally {
        finfo_close($finfo);
    }
}
// Process submitted announcement.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        requireCsrfToken();

        $form['title'] = trim($_POST['title'] ?? '');
        $form['body'] = trim($_POST['body'] ?? '');
        $form['category_id'] = $_POST['category_id'] ?? '';
        $form['audience'] = $_POST['audience'] ?? '';
        $form['priority'] = $_POST['priority'] ?? '';
        $form['status'] = $_POST['status'] ?? '';
        $form['expires_at'] = trim($_POST['expires_at'] ?? '');

        // Validate selected school and department IDs.
        $readIds = function ($values): array {
            if (!is_array($values)) {
                throw new RuntimeException('Invalid school or department selection.');
            }

            $ids = [];

            foreach ($values as $value) {
                $id = filter_var($value, FILTER_VALIDATE_INT);

                if ($id === false || $id < 1) {
                    throw new RuntimeException('Invalid school or department ID.');
                }

                $ids[] = $id;
            }

            return array_values(array_unique($ids));
        };

        $selectedSchools = $readIds($_POST['school_ids'] ?? []);
        $selectedDepartments = $readIds($_POST['department_ids'] ?? []);

        // Validate required fields.
        if ($form['title'] === '' || strlen($form['title']) > 200) {
            throw new RuntimeException('Enter a title of up to 200 characters.');
        }

        if ($form['body'] === '') {
            throw new RuntimeException('Enter the announcement message.');
        }

        $categoryId = filter_var(
            $form['category_id'],
            FILTER_VALIDATE_INT
        );

        if (!$categoryId || $categoryId < 1) {
            throw new RuntimeException('Select an announcement category.');
        }

        if (!in_array($form['audience'], ['all', 'students', 'staff'], true)) {
            throw new RuntimeException('Select a valid audience.');
        }

        if (!in_array($form['priority'], ['low', 'normal', 'high', 'urgent'], true)) {
            throw new RuntimeException('Select a valid priority.');
        }

        if (!in_array($form['status'], ['draft', 'published'], true)) {
            throw new RuntimeException('Select a valid publishing option.');
        }

        // Confirm that the category is active.
        $checkCategory = $pdo->prepare("
            SELECT id
            FROM notice_categories
            WHERE id = :id AND status = 'active'
        ");

        $checkCategory->execute(['id' => $categoryId]);

        if (!$checkCategory->fetchColumn()) {
            throw new RuntimeException('The selected category is unavailable.');
        }

        // Confirm that selected schools exist and are active.
        if (!empty($selectedSchools)) {
            $placeholders = implode(',', array_fill(0, count($selectedSchools), '?'));

            $checkSchools = $pdo->prepare("
                SELECT id
                FROM schools
                WHERE status = 'active'
                AND id IN ($placeholders)
            ");

            $checkSchools->execute($selectedSchools);

            if (count($checkSchools->fetchAll(PDO::FETCH_COLUMN)) !== count($selectedSchools)) {
                throw new RuntimeException('One or more selected schools are invalid.');
            }
        }

        // Confirm that selected departments exist and belong
        // to the selected schools, when schools have been selected.
        if (!empty($selectedDepartments)) {
            $placeholders = implode(',', array_fill(0, count($selectedDepartments), '?'));

            $departmentSql = "
                SELECT id, school_id
                FROM departments
                WHERE id IN ($placeholders)
            ";

            $checkDepartments = $pdo->prepare($departmentSql);
            $checkDepartments->execute($selectedDepartments);

            $validDepartments = $checkDepartments->fetchAll(PDO::FETCH_ASSOC);

            if (count($validDepartments) !== count($selectedDepartments)) {
                throw new RuntimeException('One or more selected departments are invalid.');
            }

            foreach ($validDepartments as $department) {
                if (
                    !empty($selectedSchools)
                    && !in_array((int) $department['school_id'], $selectedSchools, true)
                ) {
                    throw new RuntimeException(
                        'Each selected department must belong to a selected school.'
                    );
                }
            }
        }

        // Validate optional expiry date.
        $expiresAt = null;

        if ($form['expires_at'] !== '') {
            $expiryDate = DateTime::createFromFormat(
                'Y-m-d\TH:i',
                $form['expires_at']
            );

            if (!$expiryDate || $expiryDate->format('Y-m-d\TH:i') !== $form['expires_at']) {
                throw new RuntimeException('Enter a valid expiry date.');
            }

            $expiresAt = $expiryDate->format('Y-m-d H:i:s');
        }

        // Save announcement and targeting in one transaction.
        $pdo->beginTransaction();

        $publishedAt = $form['status'] === 'published'
            ? date('Y-m-d H:i:s')
            : null;

        $insertNotice = $pdo->prepare("
            INSERT INTO notices (
                category_id,
                created_by,
                title,
                body,
                audience,
                priority,
                status,
                published_at,
                expires_at
            ) VALUES (
                :category_id,
                :created_by,
                :title,
                :body,
                :audience,
                :priority,
                :status,
                :published_at,
                :expires_at
            )
        ");

        $insertNotice->execute([
            'category_id' => $categoryId,
            'created_by' => $_SESSION['user_id'],
            'title' => $form['title'],
            'body' => $form['body'],
            'audience' => $form['audience'],
            'priority' => $form['priority'],
            'status' => $form['status'],
            'published_at' => $publishedAt,
            'expires_at' => $expiresAt
        ]);

        $noticeId = (int) $pdo->lastInsertId();

        // Save school targeting.
        $insertSchool = $pdo->prepare("
            INSERT INTO notice_schools (notice_id, school_id)
            VALUES (:notice_id, :school_id)
        ");

        foreach ($selectedSchools as $schoolId) {
            $insertSchool->execute([
                'notice_id' => $noticeId,
                'school_id' => $schoolId
            ]);
        }

        // Save department targeting.
        $insertDepartment = $pdo->prepare("
            INSERT INTO notice_departments (notice_id, department_id)
            VALUES (:notice_id, :department_id)
        ");

        foreach ($selectedDepartments as $departmentId) {
            $insertDepartment->execute([
                'notice_id' => $noticeId,
                'department_id' => $departmentId
            ]);
        }

        $pdo->commit();

        $success = $form['status'] === 'published'
            ? 'Announcement published successfully!'
            : 'Announcement saved as a draft successfully!';

        // Reset the form after successful submission.
        $form = [
            'title' => '',
            'body' => '',
            'category_id' => '',
            'audience' => 'all',
            'priority' => 'normal',
            'status' => 'draft',
            'expires_at' => ''
        ];

        $selectedSchools = [];
        $selectedDepartments = [];

    }  catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Remove uploaded files if the database operation fails.
    foreach ($uploadedFilePaths as $filePath) {
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    $error = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'Something went wrong while saving the announcement.';
}
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Create Announcement</title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>

<body class="bg-light">

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Create Announcement</h2>

        <a href="<?= BASE_URL ?>/admin/dashboard.php"
           class="btn btn-outline-secondary">
            Back to Admin Dashboard
        </a>
    </div>

    <?php if ($error !== ''): ?>
        <div class="alert alert-danger">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="alert alert-success">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">

            <form method="POST" enctype="multipart/form-data">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= e($csrfToken) ?>"
                >

                <div class="mb-3">
                    <label class="form-label">Announcement Title</label>

                    <input
                        type="text"
                        name="title"
                        class="form-control"
                        maxlength="200"
                        value="<?= e($form['title']) ?>"
                        required
                    >
                </div>

                <div class="mb-3">
                    <label class="form-label">Announcement Message</label>

                    <textarea
                        name="body"
                        class="form-control"
                        rows="6"
                        required
                    ><?= e($form['body']) ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label">Category</label>

                    <select name="category_id" class="form-select" required>
                        <option value="">Choose category</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int) $category['id'] ?>"
                                <?= (string) $form['category_id'] === (string) $category['id'] ? 'selected' : '' ?>
                            >
                                <?= e($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>

                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Audience</label>

                    <select name="audience" class="form-select" required>
                        <option value="all" <?= $form['audience'] === 'all' ? 'selected' : '' ?>>
                            Everyone
                        </option>

                        <option value="students" <?= $form['audience'] === 'students' ? 'selected' : '' ?>>
                            Students only
                        </option>

                        <option value="staff" <?= $form['audience'] === 'staff' ? 'selected' : '' ?>>
                            Staff only
                        </option>
                    </select>
                </div>

                <hr>

                <h5>Target Schools</h5>

                <p class="text-muted">
                    Leave all schools unchecked to target every school.
                </p>

                <?php foreach ($schools as $school): ?>
                    <div class="form-check mb-2">
                        <input
                            class="form-check-input school-checkbox"
                            type="checkbox"
                            name="school_ids[]"
                            value="<?= (int) $school['id'] ?>"
                            id="school<?= (int) $school['id'] ?>"
                            <?= in_array((int) $school['id'], $selectedSchools, true) ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label"
                            for="school<?= (int) $school['id'] ?>"
                        >
                            <?= e($school['school_name']) ?>
                        </label>
                    </div>
                <?php endforeach; ?>

                <hr>

                <h5>Target Departments</h5>

                <p class="text-muted">
                    Leave all departments unchecked to target every department
                    within the selected schools.
                </p>

                <?php foreach ($departments as $department): ?>
                    <div
                        class="form-check mb-2 department-option"
                        data-school-id="<?= (int) $department['school_id'] ?>"
                    >
                        <input
                            class="form-check-input department-checkbox"
                            type="checkbox"
                            name="department_ids[]"
                            value="<?= (int) $department['id'] ?>"
                            id="department<?= (int) $department['id'] ?>"
                            <?= in_array((int) $department['id'], $selectedDepartments, true) ? 'checked' : '' ?>
                        >

                        <label
                            class="form-check-label"
                            for="department<?= (int) $department['id'] ?>"
                        >
                            <?= e($department['department_name']) ?>
                            <small class="text-muted">
                                (<?= e($department['school_name']) ?>)
                            </small>
                        </label>
                    </div>
                <?php endforeach; ?>
<hr>

<h5>Attachments</h5>

<p class="text-muted">
    You can attach up to 5 documents or images.
    Each file must not exceed 10 MB.
</p>

<div class="mb-4">

    <label for="attachments" class="form-label">
        Select Documents or Images
    </label>

    <input
        type="file"
        name="attachments[]"
        id="attachments"
        class="form-control"
        accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp"
        multiple
    >

    <small class="text-muted">
        Supported: PDF, Word, Excel, PowerPoint, JPG, PNG and WebP.
    </small>

</div>
                <hr>

                <div class="mb-3">
                    <label class="form-label">Priority</label>

                    <select name="priority" class="form-select">
                        <?php foreach (['low', 'normal', 'high', 'urgent'] as $priority): ?>
                            <option
                                value="<?= e($priority) ?>"
                                <?= $form['priority'] === $priority ? 'selected' : '' ?>
                            >
                                <?= ucfirst($priority) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Expiry Date (Optional)</label>

                    <input
                        type="datetime-local"
                        name="expires_at"
                        class="form-control"
                        value="<?= e($form['expires_at']) ?>"
                    >
                </div>

                <div class="mb-4">
                    <label class="form-label">Publishing Option</label>

                    <select name="status" class="form-select">
                        <option value="draft" <?= $form['status'] === 'draft' ? 'selected' : '' ?>>
                            Save as Draft
                        </option>

                        <option value="published" <?= $form['status'] === 'published' ? 'selected' : '' ?>>
                            Publish Immediately
                        </option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">
                    Save Announcement
                </button>

                <a href="<?= BASE_URL ?>/admin/dashboard.php"
                   class="btn btn-secondary">
                    Cancel
                </a>

            </form>

        </div>
    </div>

</div>

<script>
const schoolCheckboxes = document.querySelectorAll('.school-checkbox');
const departmentOptions = document.querySelectorAll('.department-option');

function filterDepartments() {

    const selectedSchools = Array.from(schoolCheckboxes)
        .filter(checkbox => checkbox.checked)
        .map(checkbox => checkbox.value);

    departmentOptions.forEach(option => {

        const schoolId = option.dataset.schoolId;
        const checkbox = option.querySelector('input');

        const visible = selectedSchools.length === 0 ||
            selectedSchools.includes(schoolId);

        option.style.display = visible ? '' : 'none';

        if (!visible) {
            checkbox.checked = false;
        }
    });
}

schoolCheckboxes.forEach(checkbox => {
    checkbox.addEventListener('change', filterDepartments);
});

filterDepartments();
</script>

</body>
</html>
