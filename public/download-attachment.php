<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

requireLogin();

// Only students and staff can download announcement attachments.
$role = $_SESSION['role_name'] ?? '';

if (!in_array($role, ['student', 'staff'], true)) {
    http_response_code(403);
    exit('You are not allowed to download this attachment.');
}

// Validate attachment ID.
$attachmentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$attachmentId || $attachmentId < 1) {
    http_response_code(404);
    exit('Attachment not found.');
}

try {

    // Get the user's current school and department.
    $userQuery = $pdo->prepare("
        SELECT school_id, department_id
        FROM users
        WHERE id = :user_id
        LIMIT 1
    ");

    $userQuery->execute([
        'user_id' => $_SESSION['user_id']
    ]);

    $user = $userQuery->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(403);
        exit('User account not found.');
    }

    $schoolId = $user['school_id'] !== null
        ? (int) $user['school_id']
        : null;

    $departmentId = $user['department_id'] !== null
        ? (int) $user['department_id']
        : null;

    $audience = $role === 'student' ? 'students' : 'staff';

    /*
    |--------------------------------------------------------------------------
    | Check whether the user can access the announcement.
    |--------------------------------------------------------------------------
    */

    $query = $pdo->prepare("
        SELECT
            a.file_path,
            a.original_filename,
            a.mime_type,
            a.file_size

        FROM notice_attachments a

        INNER JOIN notices n
            ON n.id = a.notice_id

        WHERE a.id = :attachment_id

        AND n.status = 'published'

        AND (
            n.audience = 'all'
            OR n.audience = :audience
        )

        AND (
            n.expires_at IS NULL
            OR n.expires_at >= CURRENT_TIMESTAMP
        )

        AND (
            NOT EXISTS (
                SELECT 1
                FROM notice_schools ns
                WHERE ns.notice_id = n.id
            )
            OR EXISTS (
                SELECT 1
                FROM notice_schools ns
                WHERE ns.notice_id = n.id
                AND ns.school_id = :school_id
            )
        )

        AND (
            NOT EXISTS (
                SELECT 1
                FROM notice_departments nd
                WHERE nd.notice_id = n.id
            )
            OR EXISTS (
                SELECT 1
                FROM notice_departments nd
                WHERE nd.notice_id = n.id
                AND nd.department_id = :department_id
            )
        )

        LIMIT 1
    ");

    $query->execute([
        'attachment_id' => $attachmentId,
        'audience' => $audience,
        'school_id' => $schoolId,
        'department_id' => $departmentId
    ]);

    $attachment = $query->fetch(PDO::FETCH_ASSOC);

    if (!$attachment) {
        http_response_code(404);
        exit('Attachment not found or you do not have permission to access it.');
    }

    /*
    |--------------------------------------------------------------------------
    | Validate the stored file path.
    |--------------------------------------------------------------------------
    */

    $relativePath = $attachment['file_path'];

    if (
        !is_string($relativePath) ||
        !preg_match(
            '#^storage/notice-attachments/[a-f0-9]{32}\.(pdf|doc|docx|xls|xlsx|ppt|pptx|jpg|jpeg|png|webp)$#',
            $relativePath
        )
    ) {
        http_response_code(404);
        exit('Attachment not found.');
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

    if (!is_file($absolutePath) || !is_readable($absolutePath)) {
        http_response_code(404);
        exit('Attachment file is unavailable.');
    }

    /*
    |--------------------------------------------------------------------------
    | Allow only the file types supported by the upload feature.
    |--------------------------------------------------------------------------
    */

    $allowedMimeTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    $mimeType = $attachment['mime_type'];

    if (!in_array($mimeType, $allowedMimeTypes, true)) {
        http_response_code(404);
        exit('Unsupported attachment type.');
    }

    $originalName = basename(
        str_replace('\\', '/', (string) $attachment['original_filename'])
    );

    // Images can open in the browser; documents will download.
    $disposition = str_starts_with($mimeType, 'image/')
        ? 'inline'
        : 'attachment';

    header('Content-Type: ' . $mimeType);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');

    header(
        "Content-Disposition: " . $disposition .
        "; filename=\"attachment\"; filename*=UTF-8''" .
        rawurlencode($originalName)
    );

    header('Content-Length: ' . filesize($absolutePath));

    readfile($absolutePath);
    exit;

} catch (Throwable $exception) {

    error_log('Attachment download error: ' . $exception->getMessage());

    http_response_code(500);
    exit('Unable to download the attachment. Please try again.');
}
