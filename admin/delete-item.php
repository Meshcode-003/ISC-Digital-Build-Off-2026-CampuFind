
<?php

session_start();

require_once "../config/database.php";
require_once dirname(__DIR__, 2) . '/includes/auth.php';
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    exit("Method not allowed.");
}

// Verify CSRF token
if (
    !isset($_POST["csrf_token"]) ||
    !isset($_SESSION["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    die("Invalid request token.");
}

$item_id = filter_input(INPUT_POST, "item_id", FILTER_VALIDATE_INT);

if (!$item_id || $item_id < 1) {
    die("Invalid item ID.");
}

try {

    $conn->beginTransaction();

    // Lock the item while checking it
    $stmt = $conn->prepare("
        SELECT id
        FROM lost_items
        WHERE id = ?
        FOR UPDATE
    ");

    $stmt->execute([$item_id]);

    if (!$stmt->fetch()) {
        throw new Exception("Item not found.");
    }

    // Check whether this item has claims
    $check = $conn->prepare("
        SELECT COUNT(*)
        FROM claims
        WHERE item_id = ?
    ");

    $check->execute([$item_id]);

    $claimCount = (int)$check->fetchColumn();

    if ($claimCount > 0) {
        throw new Exception(
            "This item has claims. It cannot be deleted because its claim history must be preserved."
        );
    }

    // Delete item
    $delete = $conn->prepare("
        DELETE FROM lost_items
        WHERE id = ?
    ");

    $delete->execute([$item_id]);

    $conn->commit();

    header("Location: manage-items.php?message=deleted");
    exit;

} catch (Exception $e) {

    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    die("Unable to delete item: " . htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        "UTF-8"
    ));
}
