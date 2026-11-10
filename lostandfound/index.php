<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/notifications.php';

requireLogin();

$userId = (int) $_SESSION['user_id'];

$escape = static fn ($value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

// Retrieve approved lost items, their categories and primary images.

$sql = "
    SELECT
        i.id,
        i.user_id,
        i.item_name,
        i.description,
        i.date_occurred,
        i.location,
        i.status,
        c.category_name,
        (
            SELECT image_path
            FROM item_images
            WHERE item_id = i.id
            ORDER BY is_primary DESC, id ASC
            LIMIT 1
        ) AS image_path
    FROM items i
    LEFT JOIN categories c ON c.id = i.category_id
    WHERE i.item_type = 'lost'
      AND i.status = 'approved'
    ORDER BY i.created_at DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$items = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Lost Items | CampusFind</title>

    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.css">

    <style>
        .claim-button {
            display: inline-block;
            background: #d4a017;
            color: #fff;
            padding: 12px 20px;
            margin-top: 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
            transition: 0.3s;
        }

        .claim-button:hover {
            background: #a87900;
            transform: translateY(-2px);
        }

        .item-status {
            display: inline-block;
            padding: 5px 10px;
            margin-top: 8px;
            border-radius: 5px;
            background: #e8f0fe;
            color: #174ea6;
            font-size: 12px;
        }

        .item-image img {
            width: 100%;
            height: 220px;
            object-fit: cover;
            border-radius: 8px;
        }

        .empty-message {
            text-align: center;
            padding: 35px;
            grid-column: 1 / -1;
        }

        .claim-disabled {
            display: inline-block;
            margin-top: 15px;
            color: #777;
        }
    </style>

</head>

<body>

<header class="navbar">

    <div class="logo">Lost & Found</div>

    <nav>
        <?php renderNotificationBell($pdo, $userId); ?>
        <a href="../public/dashboard.php">Go to my CampusFind account</a>
        <a href="index.php">Home</a>
        <a href="report-item.php">Report Item</a>
        <a href="lost-items.php">Lost Items</a>
        <?php if (in_array($_SESSION['role_name'] ?? '', ['student', 'staff'], true)): ?>
            <a href="claimant/my-claims.php">My Claims</a>
        <?php endif; ?>
    </nav>

</header>

<main class="gallery-container">

    <div class="gallery-heading">

        <h1>Lost Items</h1>

        <p>
            Browse approved reports of missing items.
            You might find something that belongs to you.
        </p>

    </div>

    <div class="search-container">

        <input
            type="text"
            id="searchInput"
            placeholder="Search by item name, category or location..."
        >

    </div>

    <div class="items-grid" id="itemsGrid">

        <?php if (count($items) > 0): ?>

            <?php foreach ($items as $item): ?>

                <div class="item-card">

                    <div class="item-image">

                        <?php if (!empty($item['image_path'])): ?>

                            <img
                                src="<?= $escape($item['image_path']) ?>"
                                alt="<?= $escape($item['item_name']) ?>"
                            >

                        <?php else: ?>

                            <div class="no-image">
                                No photograph available
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="item-content">

                        <span class="item-category">
                            <?= $escape($item['category_name'] ?? 'Uncategorised') ?>
                        </span>

                        <h2>
                            <?= $escape($item['item_name']) ?>
                        </h2>

                        <p class="item-description">
                            <?= $escape($item['description']) ?>
                        </p>

                        <div class="item-details">

                            <p>
                                <strong>Location:</strong>
                                <?= $escape($item['location']) ?>
                            </p>

                            <p>
                                <strong>Date Lost:</strong>
                                <?= $escape($item['date_occurred']) ?>
                            </p>

                            <span class="item-status">
                                Approved
                            </span>

                        </div>

                        <?php if ((int) $item['user_id'] === $userId): ?>

                            <p class="claim-disabled">
                                This is your own report.
                            </p>

                        <?php else: ?>

                            <a
                                href="claimant/submit-claim.php?item_id=<?= (int) $item['id'] ?>"
                                class="claim-button"
                            >
                                Claim This Item
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        <?php else: ?>

            <div class="empty-message">

                <h2>No Approved Lost Items Yet</h2>

                <p>
                    There are currently no approved lost-item reports.
                    Please check again later.
                </p>

                <a href="report-item.php" class="claim-button">
                    Report an Item
                </a>

            </div>

        <?php endif; ?>

    </div>

</main>

<script>

const searchInput = document.getElementById("searchInput");
const itemCards = document.querySelectorAll(".item-card");

searchInput.addEventListener("input", function () {

    const searchValue = this.value.toLowerCase();

    itemCards.forEach(function (card) {

        const itemInformation = card.textContent.toLowerCase();

        card.style.display = itemInformation.includes(searchValue)
            ? "block"
            : "none";

    });

});

</script>

</body>
</html>
