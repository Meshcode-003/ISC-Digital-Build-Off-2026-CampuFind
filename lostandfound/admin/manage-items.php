<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

// Create CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function clean($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

// Search and filters.
$searchInput = $_GET['search'] ?? '';
$statusInput = $_GET['status'] ?? '';
$itemTypeInput = $_GET['item_type'] ?? '';
$search = is_string($searchInput) ? trim($searchInput) : '';
$status = is_string($statusInput) ? $statusInput : '';
$itemType = is_string($itemTypeInput) ? $itemTypeInput : '';

$allowedStatuses = [
    'pending',
    'approved',
    'rejected',
    'recovered'
];

$allowedTypes = ['lost', 'found'];

if (!in_array($status, $allowedStatuses, true)) {
    $status = '';
}

if (!in_array($itemType, $allowedTypes, true)) {
    $itemType = '';
}

// Build query.
$sql = "
    SELECT
        i.*,
        c.category_name AS category,

        (
            SELECT COUNT(*)
            FROM claims cl
            WHERE cl.item_id = i.id
        ) AS total_claims,

        (
            SELECT COUNT(*)
            FROM claims cl
            WHERE cl.item_id = i.id
            AND cl.status = 'approved'
        ) AS approved_claims,

        (
            SELECT im.image_path
            FROM item_images im
            WHERE im.item_id = i.id
            ORDER BY im.is_primary DESC, im.id ASC
            LIMIT 1
        ) AS image

    FROM items i

    LEFT JOIN categories c
        ON i.category_id = c.id

    WHERE 1 = 1
";

$params = [];

// Search item name or location.
if ($search !== '') {
    $sql .= "
        AND (
            i.item_name LIKE :search_name
            OR i.location LIKE :search_location
        )
    ";

    $params['search_name'] = "%$search%";
    $params['search_location'] = "%$search%";
}

// Filter by status.
if ($status !== '') {
    $sql .= " AND i.status = :status";
    $params['status'] = $status;
}

// Filter by item type.
if ($itemType !== '') {
    $sql .= " AND i.item_type = :item_type";
    $params['item_type'] = $itemType;
}

$sql .= " ORDER BY i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$items = $stmt->fetchAll();

// Status display labels.
function statusLabel(string $status): string
{
    return ucwords(str_replace('_', ' ', $status));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>CampusFind | Manage Items</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f5f9;
    color: #1e293b;
}

.header {
    background: #101c36;
    color: white;
    padding: 22px 30px;
}

.container {
    padding: 30px;
    max-width: 1450px;
    margin: auto;
}

.top-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

a {
    text-decoration: none;
}

.back-link {
    color: #2563eb;
    font-weight: bold;
}

.filters {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

input, select {
    padding: 12px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
}

button, .button {
    border: none;
    padding: 11px 15px;
    border-radius: 7px;
    cursor: pointer;
    color: white;
    font-weight: bold;
    display: inline-block;
}

.search-btn {
    background: #2563eb;
}

.edit-btn {
    background: #2563eb;
}

.delete-btn {
    background: #dc2626;
}

.recover-btn {
    background: #15803d;
}

.table-container {
    overflow-x: auto;
    background: white;
    border-radius: 10px;
    padding: 15px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

th, td {
    padding: 14px;
    text-align: left;
    border-bottom: 1px solid #e2e8f0;
    vertical-align: middle;
}

th {
    background: #f8fafc;
}

.item-image {
    width: 65px;
    height: 60px;
    object-fit: cover;
    border-radius: 7px;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.pending {
    background: #fef3c7;
    color: #92400e;
}

.approved {
    background: #dbeafe;
    color: #1d4ed8;
}

.rejected {
    background: #fee2e2;
    color: #b91c1c;
}

.recovered {
    background: #dcfce7;
    color: #166534;
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.inline-form {
    display: inline;
}

.empty {
    text-align: center;
    padding: 35px;
    color: #64748b;
}

@media(max-width: 700px) {
    .container {
        padding: 15px;
    }

    .header {
        padding: 20px;
    }
}
</style>

</head>

<body>

<div class="header">
    <h1>CampusFind Item Management</h1>
    <p>View, search and manage lost and found items.</p>
</div>

<div class="container">

    <div class="top-actions">

        <a href="dashboard.php" class="back-link">
            &larr; Back to Dashboard
        </a>

        <strong>
            Total items displayed: <?= count($items) ?>
        </strong>

    </div>

    <form method="GET" class="filters">

        <input
            type="text"
            name="search"
            placeholder="Search item name or location"
            value="<?= clean($search) ?>"
        >

        <select name="item_type">
            <option value="">All item types</option>

            <option value="lost"
                <?= $itemType === 'lost' ? 'selected' : '' ?>>
                Lost
            </option>

            <option value="found"
                <?= $itemType === 'found' ? 'selected' : '' ?>>
                Found
            </option>
        </select>

        <select name="status">
            <option value="">All statuses</option>

            <?php foreach ($allowedStatuses as $allowedStatus): ?>
                <option
                    value="<?= clean($allowedStatus) ?>"
                    <?= $status === $allowedStatus ? 'selected' : '' ?>
                >
                    <?= clean(statusLabel($allowedStatus)) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="submit" class="search-btn">
            Search
        </button>

        <a href="manage-items.php" class="button edit-btn">
            Clear Filters
        </a>

    </form>

    <div class="table-container">

        <table>

            <thead>
                <tr>
                    <th>Image</th>
                    <th>Item</th>
                    <th>Type</th>
                    <th>Category</th>
                    <th>Location</th>
                    <th>Date</th>
                    <th>Claims</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>

            <?php if (count($items) > 0): ?>

                <?php foreach ($items as $item): ?>

                    <tr>

                        <td>
                            <?php if (!empty($item['image'])): ?>

                                <?php
                                $imagePath = $item['image'];

                                if (
                                    !preg_match('/^https?:\/\//i', $imagePath)
                                    && strpos($imagePath, '../') !== 0
                                ) {
                                    $imagePath = '../' . $imagePath;
                                }
                                ?>

                                <img
                                    src="<?= clean($imagePath) ?>"
                                    class="item-image"
                                    alt="Item image"
                                >

                            <?php else: ?>
                                No image
                            <?php endif; ?>
                        </td>

                        <td>
                            <strong><?= clean($item['item_name']) ?></strong>
                        </td>

                        <td><?= clean(ucfirst($item['item_type'])) ?></td>

                        <td><?= clean($item['category']) ?></td>

                        <td><?= clean($item['location']) ?></td>

                        <td><?= clean($item['date_occurred']) ?></td>

                        <td>
                            <?= (int)$item['total_claims'] ?>
                        </td>

                        <td>
                            <span class="status <?= clean($item['status']) ?>">
                                <?= clean(statusLabel($item['status'])) ?>
                            </span>
                        </td>

                        <td>

                            <div class="actions">

                                <a
                                    href="edit-item.php?id=<?= (int)$item['id'] ?>"
                                    class="button edit-btn"
                                >
                                    Edit
                                </a>

                                <?php if ($item['status'] !== 'recovered'): ?>

                                    <?php if ((int)$item['approved_claims'] > 0): ?>

                                        <form
                                            method="POST"
                                            action="mark-returned.php"
                                            class="inline-form"
                                            onsubmit="return confirm('Confirm that the item has physically been handed over to the approved claimant?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= clean($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="item_id"
                                                value="<?= (int)$item['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="recover-btn"
                                            >
                                                Confirm Handover
                                            </button>

                                        </form>

                                    <?php endif; ?>

                                <?php else: ?>

                                    <span>Recovered</span>

                                <?php endif; ?>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="9" class="empty">
                        No items found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>
