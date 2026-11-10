<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin();

$statement = $pdo->query(
    "SELECT id, category_name
     FROM categories
     WHERE status = 'active'
     ORDER BY category_name ASC"
);

$categories = $statement->fetchAll();

$csrfToken = generateCsrfToken();

$escape = static fn ($value): string => htmlspecialchars(
    (string) $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Report Lost Item | CampusFind</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.css">
</head>

<body>

<header class="navbar">

    <div class="logo">Lost & Found</div>

    <nav>
        <?php renderNotificationBell($pdo, (int) $_SESSION['user_id']); ?>
        <a href="index.php">Home</a>
        <a href="report-item.php">Report Lost Item</a>
        <a href="lost-items.php">Lost Items</a>
    </nav>

</header>

<main class="report-container">

    <div class="form-heading">

        <h1>Report a Lost Item</h1>

        <p>
            Share details about the item you have lost. An administrator will review your report before it appears in the gallery.
        </p>

    </div>

    <form
        id="reportForm"
        action="save-item.php"
        method="POST"
        enctype="multipart/form-data"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= $escape($csrfToken) ?>"
        >

        <div class="form-group">

            <label>Item Name</label>

            <input
                type="text"
                name="item_name"
                maxlength="150"
                placeholder="e.g. Samsung Galaxy Phone"
                required
            >

        </div>

        <div class="form-group">

            <label>Item Category</label>

            <select name="category_id" required>

                <option value="">Select category</option>

                <?php foreach ($categories as $category): ?>

                    <option value="<?= (int) $category['id'] ?>">
                        <?= $escape($category['category_name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="form-group">

            <label>Description</label>

            <textarea
                name="description"
                maxlength="5000"
                placeholder="Describe the item, including its colour, brand and identifying features..."
                required
            ></textarea>

        </div>

        <div class="form-group">

            <label>Location Lost</label>

            <input
                type="text"
                name="location"
                maxlength="255"
                placeholder="Where did you lose the item?"
                required
            >

        </div>

        <div class="form-group">

            <label>Date Lost</label>

            <input
                type="date"
                name="date_occurred"
                max="<?= date('Y-m-d') ?>"
                required
            >

        </div>

        <div class="form-group">

            <label>Contact Phone Number</label>

            <input
                type="tel"
                name="contact_details"
                maxlength="25"
                placeholder="Enter your phone number"
            >

        </div>

        <div class="camera-section">

            <h2>Capture Item Photograph</h2>

            <video
                id="camera"
                autoplay
                playsinline
            ></video>

            <canvas id="canvas" hidden></canvas>

            <img
                id="photoPreview"
                alt="Captured item preview"
                hidden
            >

            <input
                type="hidden"
                name="captured_image"
                id="capturedImage"
            >

            <div class="camera-buttons">

                <button type="button" id="startCamera">
                    Open Camera
                </button>

                <button type="button" id="capturePhoto">
                    Capture Photo
                </button>

                <button type="button" id="retakePhoto">
                    Retake Photo
                </button>

            </div>

        </div>

        <button type="submit" class="submit-button">
            Submit Lost Item Report
        </button>

    </form>

</main>

<script src="assets/js/camera.js"></script>

</body>
</html>