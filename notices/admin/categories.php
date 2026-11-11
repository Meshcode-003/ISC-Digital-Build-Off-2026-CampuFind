<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/notices.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireModuleAdmin('notices');
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = $_POST['category_name'] ?? '';
        $description = $_POST['description'] ?? '';
        if (!is_string($name) || !is_string($description)) {
            $error = 'Invalid category data.';
        } else {
            $name = trim($name);
            $description = trim($description);
            if ($name === '' || strlen($name) > 100 || strlen($description) > 255) {
                $error = 'Enter a category name (up to 100 characters) and a description up to 255 characters.';
            } else {
                try {
                    $statement = $pdo->prepare(
                        'INSERT INTO notice_categories (category_name, description)
                         VALUES (:name, :description)'
                    );
                    $statement->execute([
                        'name' => $name,
                        'description' => $description !== '' ? $description : null,
                    ]);
                    $success = 'Category added.';
                } catch (PDOException $exception) {
                    if ($exception->getCode() !== '23000') {
                        throw $exception;
                    }
                    $error = 'That category name already exists.';
                }
            }
        }
    } elseif ($action === 'toggle') {
        $categoryId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$categoryId || $categoryId < 1) {
            $error = 'Select a valid category.';
        } else {
            $statement = $pdo->prepare(
                "UPDATE notice_categories
                 SET status = IF(status = 'active', 'inactive', 'active')
                 WHERE id = :id"
            );
            $statement->execute(['id' => $categoryId]);
            if ($statement->rowCount() === 0) {
                $error = 'Category not found or unchanged.';
            } else {
                $success = 'Category status updated.';
            }
        }
    } else {
        http_response_code(400);
        exit('Unsupported category action.');
    }
}

$categories = $pdo->query(
    'SELECT id, category_name, description, status
     FROM notice_categories
     ORDER BY category_name'
)->fetchAll();
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage notice categories | CampusFind</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/modules.css">
    <link rel="stylesheet" href="<?= noticeEscape(NOTICES_URL) ?>/notices.css">
    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>
<body>
    <header class="notice-header"><a class="brand" href="<?= noticeEscape(NOTICES_URL) ?>/admin/">Notices administration</a></header>
    <main class="notice-main admin-main">
        <a class="back-link" href="<?= noticeEscape(NOTICES_URL) ?>/admin/">← Admin dashboard</a>
        <h1>Manage categories</h1>
        <?php if ($error !== ''): ?><p class="form-message error-message" role="alert"><?= noticeEscape($error) ?></p><?php endif; ?>
        <?php if ($success !== ''): ?><p class="form-message success-message" role="status"><?= noticeEscape($success) ?></p><?php endif; ?>
        <form class="editor-card" method="post">
            <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
            <input type="hidden" name="action" value="create">
            <label>Category name<input name="category_name" maxlength="100" required></label>
            <label>Description<input name="description" maxlength="255"></label>
            <button class="button button-primary" type="submit">Add category</button>
        </form>
        <div class="category-list">
            <?php foreach ($categories as $category): ?>
                <article class="category-row">
                    <div><strong><?= noticeEscape($category['category_name']) ?></strong><p><?= noticeEscape($category['description'] ?? '') ?></p></div>
                    <span class="status-pill status-<?= noticeEscape($category['status']) ?>"><?= noticeEscape(ucfirst($category['status'])) ?></span>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?= noticeEscape($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                        <button class="small-action" type="submit"><?= $category['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </main>
</body>
</html>
