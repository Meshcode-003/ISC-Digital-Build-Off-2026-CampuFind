
<?php

session_start();

require_once "../config/database.php";
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function clean($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = filter_input(INPUT_POST, "id", FILTER_VALIDATE_INT);
}

if (!$id || $id < 1) {
    die("Invalid item ID.");
}

// Retrieve the item
$stmt = $conn->prepare("SELECT * FROM lost_items WHERE id = ?");
$stmt->execute([$id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die("Item not found.");
}

$error = "";
$success = "";

// Save changes
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        die("Invalid request token.");
    }

    $item_name = trim($_POST["item_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $location_lost = trim($_POST["location_lost"] ?? "");
    $date_lost = trim($_POST["date_lost"] ?? "");
    $contact_name = trim($_POST["contact_name"] ?? "");
    $contact_phone = trim($_POST["contact_phone"] ?? "");

    if (
        $item_name === "" ||
        $category === "" ||
        $description === "" ||
        $location_lost === "" ||
        $date_lost === "" ||
        $contact_name === "" ||
        $contact_phone === ""
    ) {
        $error = "Please fill in all fields.";
    } elseif (
        !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_lost) ||
        !checkdate(
            (int)substr($date_lost, 5, 2),
            (int)substr($date_lost, 8, 2),
            (int)substr($date_lost, 0, 4)
        )
    ) {
        $error = "Please enter a valid date.";
    } else {

        $update = $conn->prepare("
            UPDATE lost_items
            SET
                item_name = ?,
                category = ?,
                description = ?,
                location_lost = ?,
                date_lost = ?,
                contact_name = ?,
                contact_phone = ?
            WHERE id = ?
        ");

        $update->execute([
            $item_name,
            $category,
            $description,
            $location_lost,
            $date_lost,
            $contact_name,
            $contact_phone,
            $id
        ]);

        $success = "Item information updated successfully.";

        // Refresh the displayed item
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
    }
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

<h1>Edit Lost Item</h1>

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
        value="<?= clean($_SESSION["csrf_token"]) ?>"
    >

    <label>Item Name</label>
    <input
        type="text"
        name="item_name"
        value="<?= clean($item["item_name"]) ?>"
        required
    >

    <label>Category</label>
    <input
        type="text"
        name="category"
        value="<?= clean($item["category"]) ?>"
        required
    >

    <label>Description</label>
    <textarea name="description" required><?= clean($item["description"]) ?></textarea>

    <label>Location Lost</label>
    <input
        type="text"
        name="location_lost"
        value="<?= clean($item["location_lost"]) ?>"
        required
    >

    <label>Date Lost</label>
    <input
        type="date"
        name="date_lost"
        value="<?= clean($item["date_lost"]) ?>"
        required
    >

    <label>Reporter Name</label>
    <input
        type="text"
        name="contact_name"
        value="<?= clean($item["contact_name"]) ?>"
        required
    >

    <label>Reporter Phone</label>
    <input
        type="text"
        name="contact_phone"
        value="<?= clean($item["contact_phone"]) ?>"
        required
    >

    <button type="submit">Save Changes</button>

</form>

</div>

</body>
</html>
