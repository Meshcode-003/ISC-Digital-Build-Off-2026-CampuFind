<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

requireAdmin();
$csrfToken = generateCsrfToken();

function clean($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$idInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['id'] ?? null)
    : ($_GET['id'] ?? null);
$id = filter_var($idInput, FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    http_response_code(400);
    exit('Invalid item ID.');
}

$error = '';
$success = '';
$categories = $pdo->query(
    'SELECT id, category_name FROM categories ORDER BY category_name'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $itemNameInput = $_POST['item_name'] ?? '';
    $categoryInput = $_POST['category_id'] ?? '';
    $descriptionInput = $_POST['description'] ?? '';
    $locationInput = $_POST['location'] ?? '';
    $dateInput = $_POST['date_occurred'] ?? '';
    $contactInput = $_POST['contact_details'] ?? '';

    $itemName = is_string($itemNameInput) ? trim($itemNameInput) : '';
    $categoryId = filter_var($categoryInput, FILTER_VALIDATE_INT);
    $description = is_string($descriptionInput) ? trim($descriptionInput) : '';
    $location = is_string($locationInput) ? trim($locationInput) : '';
    $dateOccurred = is_string($dateInput) ? trim($dateInput) : '';
    $contactDetails = is_string($contactInput) ? trim($contactInput) : '';

    $validDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOccurred) === 1
        && checkdate(
            (int) substr($dateOccurred, 5, 2),
            (int) substr($dateOccurred, 8, 2),
            (int) substr($dateOccurred, 0, 4)
        );
    $categoryExists = false;
    foreach ($categories as $category) {
        if ((int) $category['id'] === $categoryId) {
            $categoryExists = true;
            break;
        }
    }

    if (
        $itemName === ''
        || strlen($itemName) > 150
        || !$categoryExists
        || $description === ''
        || $location === ''
        || strlen($location) > 255
        || !$validDate
        || strlen($contactDetails) > 25
    ) {
        $error = 'Please provide valid item details, category, location, date, and contact information.';
    } else {
        $update = $pdo->prepare(
            'UPDATE items
             SET item_name = :item_name,
                 category_id = :category_id,
                 description = :description,
                 location = :location,
                 date_occurred = :date_occurred,
                 contact_details = :contact_details
             WHERE id = :id'
        );
        $update->execute([
            'item_name' => $itemName,
            'category_id' => $categoryId,
            'description' => $description,
            'location' => $location,
            'date_occurred' => $dateOccurred,
            'contact_details' => $contactDetails !== '' ? $contactDetails : null,
            'id' => $id,
        ]);
        $success = 'Item information updated successfully.';
    }
}

$stmt = $pdo->prepare(
    'SELECT i.*, c.category_name
     FROM items i
     INNER JOIN categories c ON c.id = i.category_id
     WHERE i.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $id]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    exit('Item not found.');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Lost Item</title>

<style>

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f3f5f9;
    color: #1e293b;
}

.container {
    max-width: 750px;
    margin: 35px auto;
    padding: 25px;
    background: white;
    border-radius: 12px;
}

h1 {
    margin-top: 0;
}

label {
    display: block;
    margin-top: 15px;
    font-weight: bold;
}

input, textarea {
    width: 100%;
    padding: 12px;
    margin-top: 7px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    box-sizing: border-box;
    font-family: inherit;
}

textarea {
    min-height: 110px;
}

button {
    margin-top: 20px;
    padding: 13px 20px;
    background: #2563eb;
    color: white;
    border: none;
    border-radius: 7px;
    cursor: pointer;
    font-weight: bold;
}

.back {
    display: inline-block;
    margin-bottom: 20px;
    color: #2563eb;
    text-decoration: none;
}

.error {
    padding: 12px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 7px;
}

.success {
    padding: 12px;
    background: #dcfce7;
    color: #166534;
    border-radius: 7px;
}

</style>

</head>

<body>

<div class="container">

<a href="manage-items.php" class="back">← Back to Items</a>

<h1>Edit Item</h1>

<p>Update the information below.</p>

<?php if ($error): ?>
    <p class="error"><?= clean($error) ?></p>
<?php endif; ?>

<?php if ($success): ?>
    <p class="success"><?= clean($success) ?></p>
<?php endif; ?>

<form method="POST">

    <input type="hidden" name="id" value="<?= (int)$item["id"] ?>">

    <input
        type="hidden"
        name="csrf_token"
        value="<?= clean($csrfToken) ?>"
    >

    <label>Item Name</label>
    <input
        type="text"
        name="item_name"
        value="<?= clean($item["item_name"]) ?>"
        required
    >

    <label for="category_id">Category</label>
    <select id="category_id" name="category_id" required>
        <?php foreach ($categories as $category): ?>
            <option
                value="<?= (int) $category['id'] ?>"
                <?= (int) $item['category_id'] === (int) $category['id'] ? 'selected' : '' ?>
            >
                <?= clean($category['category_name']) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label>Description</label>
    <textarea name="description" required><?= clean($item["description"]) ?></textarea>

    <label for="location">Location</label>
    <input
        type="text"
        id="location"
        name="location"
        maxlength="255"
        value="<?= clean($item["location"]) ?>"
        required
    >

    <label for="date_occurred">Date Occurred</label>
    <input
        type="date"
        id="date_occurred"
        name="date_occurred"
        value="<?= clean($item["date_occurred"]) ?>"
        required
    >

    <label for="contact_details">Contact Details</label>
    <input
        type="text"
        id="contact_details"
        name="contact_details"
        maxlength="25"
        value="<?= clean($item["contact_details"]) ?>"
    >

    <button type="submit">Save Changes</button>

</form>

</div>

</body>
</html>
