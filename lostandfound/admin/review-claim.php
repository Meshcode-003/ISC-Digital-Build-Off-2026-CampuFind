<?php
declare(strict_types=1);

// Load configuration, session, authentication and database connection.
require_once dirname(__DIR__, 2) . '/includes/auth.php';
requireAdmin();

function e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$error = '';
$success = '';

// Generate CSRF token.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get claim ID.
$claimIdInput = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['claim_id'] ?? null)
    : ($_GET['id'] ?? null);

$claim_id = filter_var($claimIdInput, FILTER_VALIDATE_INT);

if (!$claim_id || $claim_id < 1) {
    die('Invalid claim ID.');
}

// Process administrator decision.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Invalid request. Please refresh and try again.';

    } else {

        $newStatusInput = $_POST['status'] ?? '';
        $newStatus = is_string($newStatusInput) ? $newStatusInput : '';
        $reasonInput = $_POST['rejection_reason'] ?? '';
        $rejectionReason = is_string($reasonInput)
            ? trim($reasonInput)
            : '';

        $allowedDecisions = [
            'approved',
            'rejected'
        ];

        if (!in_array($newStatus, $allowedDecisions, true)) {

            $error = 'Invalid decision selected.';

        } elseif (
            $newStatus === 'rejected'
            && $rejectionReason === ''
        ) {

            $error = 'Please provide a reason for rejecting this claim.';

        } elseif (strlen($rejectionReason) > 5000) {

            $error = 'The rejection reason cannot exceed 5000 characters.';

        } else {

            try {

                $pdo->beginTransaction();

                // Lock the selected claim.
                $stmt = $pdo->prepare(
                    "SELECT id, item_id
                     FROM claims
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$claim_id]);
                $selectedClaim = $stmt->fetch();

                if (!$selectedClaim) {
                    throw new RuntimeException('Claim not found.');
                }

                // Lock the corresponding CampusFind item.
                $stmt = $pdo->prepare(
                    "SELECT id, status
                     FROM items
                     WHERE id = ?
                     FOR UPDATE"
                );

                $stmt->execute([$selectedClaim['item_id']]);
                $selectedItem = $stmt->fetch();

                if (!$selectedItem) {
                    throw new RuntimeException(
                        'The original item could not be found.'
                    );
                }

                // Do not approve an item already marked as recovered.
                if (
                    $newStatus === 'approved' &&
                    $selectedItem['status'] === 'recovered'
                ) {
                    throw new RuntimeException(
                        'This item has already been marked as recovered.'
                    );
                }

                // Prevent two claims for the same item from being approved.
                if ($newStatus === 'approved') {

                    $stmt = $pdo->prepare(
                        "SELECT COUNT(*)
                         FROM claims
                         WHERE item_id = ?
                         AND status = 'approved'
                         AND id != ?"
                    );

                    $stmt->execute([
                        $selectedClaim['item_id'],
                        $claim_id
                    ]);

                    if ((int)$stmt->fetchColumn() > 0) {
                        throw new RuntimeException(
                            'Another claimant has already been approved for this item.'
                        );
                    }
                }

                // Save the decision and identify the administrator.
                $stmt = $pdo->prepare(
                    "UPDATE claims
                     SET status = ?,
                         rejection_reason = ?,
                         reviewed_by = ?,
                         reviewed_at = NOW()
                     WHERE id = ?"
                );

                $stmt->execute([
                    $newStatus,
                    $newStatus === 'rejected' ? $rejectionReason : null,
                    (int)$_SESSION['user_id'],
                    $claim_id
                ]);

                $pdo->commit();

                $success = 'Claim decision saved successfully.';

                // Generate a fresh CSRF token.
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                error_log(
                    'Claim review error: ' . $exception->getMessage()
                );

                $error = $exception instanceof PDOException
                    ? 'Unable to save the decision. Please try again.'
                    : $exception->getMessage();
            }
        }
    }
}

// Retrieve claim, item, category, claimant and primary image information.
$sql = "SELECT
            cl.*,
            CONCAT(u.first_name, ' ', u.last_name) AS claimant_name,
            u.phone AS claimant_phone,
            u.email AS claimant_email,

            i.item_name,
            i.description AS original_description,
            i.location AS location_lost,
            i.date_occurred AS date_lost,
            i.status AS item_status,
            i.item_type,

            c.category_name AS category,

            (
                SELECT im.image_path
                FROM item_images im
                WHERE im.item_id = i.id
                ORDER BY im.is_primary DESC, im.id ASC
                LIMIT 1
            ) AS image

        FROM claims cl

        INNER JOIN users u
            ON cl.claimant_id = u.id

        INNER JOIN items i
            ON cl.item_id = i.id

        LEFT JOIN categories c
            ON i.category_id = c.id

        WHERE cl.id = ?";

$stmt = $pdo->prepare($sql);
$stmt->execute([$claim_id]);

$claim = $stmt->fetch();

if (!$claim) {
    die('Claim not found.');
}

// Prepare image path.
$imagePath = $claim['image'] ?? '';
$imageUrl = '';

if ($imagePath !== '') {

    if (preg_match('/^https?:\/\//i', $imagePath)) {
        $imageUrl = $imagePath;
    } else {
        $imageUrl = '../' . ltrim($imagePath, '/');
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Review Ownership Claim</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f4f6fa;
    color: #263238;
}

.container {
    max-width: 1150px;
    margin: auto;
    padding: 30px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.back-button {
    background: #17243a;
    color: white;
    text-decoration: none;
    padding: 12px 17px;
    border-radius: 6px;
}

.panel {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 22px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.04);
}

.panel h2 {
    margin-top: 0;
    margin-bottom: 20px;
}

.details-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.detail-box {
    background: #f7f8fb;
    padding: 17px;
    border-radius: 8px;
    overflow-wrap: anywhere;
}

.detail-box strong {
    display: block;
    margin-bottom: 8px;
    color: #657080;
}

.item-image {
    width: 100%;
    max-height: 350px;
    object-fit: contain;
    background: #f4f6fa;
    border-radius: 8px;
}

.ai-score {
    font-size: 35px;
    font-weight: bold;
    color: #167a48;
}

.ai-explanation {
    background: #edf6ff;
    padding: 17px;
    border-radius: 8px;
    margin-top: 15px;
}

.ai-words {
    margin-top: 10px;
    color: #526173;
    font-size: 14px;
}

.status {
    display: inline-block;
    padding: 7px 13px;
    border-radius: 20px;
    font-weight: bold;
}

.pending {
    background: #fff1ce;
    color: #946200;
}

.approved {
    background: #d9f7e4;
    color: #18743c;
}

.rejected {
    background: #ffe0e0;
    color: #a52020;
}

textarea {
    width: 100%;
    min-height: 120px;
    padding: 14px;
    border: 1px solid #ccc;
    border-radius: 7px;
    font-family: Arial, sans-serif;
    font-size: 15px;
    resize: vertical;
}

.decision-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 20px;
}

.decision-buttons button {
    padding: 13px 20px;
    border: none;
    border-radius: 6px;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

.approve {
    background: #16834b;
}

.reject {
    background: #c0392b;
}

.review {
    background: #d49a12;
}

.decision-buttons button:hover {
    opacity: 0.85;
}

.message {
    padding: 15px;
    border-radius: 7px;
    margin-bottom: 20px;
}

.success {
    background: #d9f7e4;
    color: #18743c;
}

.error {
    background: #ffe0e0;
    color: #a52020;
}

@media(max-width: 700px) {

    .container {
        padding: 15px;
    }

    .details-grid {
        grid-template-columns: 1fr;
    }

    .header {
        align-items: flex-start;
        flex-direction: column;
    }

}

</style>

</head>

<body>

<div class="container">


<div class="header">

    <div>

        <h1>Claim Investigation</h1>

        <p>Examine the evidence before making a decision.</p>

    </div>

    <a href="claims.php" class="back-button">
        Back to Claims
    </a>

</div>


<?php if ($success): ?>

    <div class="message success">
        <?php echo e($success); ?>
    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="message error">
        <?php echo e($error); ?>
    </div>

<?php endif; ?>


<!-- ORIGINAL ITEM -->

<section class="panel">

    <h2>Original Lost Item</h2>

    <img
        src="<?php echo e($imageUrl); ?>"
        alt="Original lost item"
        class="item-image"
    >

    <div class="details-grid">

        <div class="detail-box">

            <strong>Item Name</strong>

            <?php echo e($claim['item_name']); ?>

        </div>

        <div class="detail-box">

            <strong>Category</strong>

            <?php echo e($claim['category']); ?>

        </div>

        <div class="detail-box">

            <strong>Location Lost</strong>

            <?php echo e($claim['location_lost']); ?>

        </div>

        <div class="detail-box">

            <strong>Date Lost</strong>

            <?php echo e($claim['date_lost']); ?>

        </div>

        <div class="detail-box">

            <strong>Original Description</strong>

            <?php echo nl2br(e($claim['original_description'])); ?>

        </div>

        <div class="detail-box">

            <strong>Item Status</strong>

            <?php echo e($claim['item_status']); ?>

        </div>

    </div>

</section>


<!-- CLAIMANT INFORMATION -->

<section class="panel">

    <h2>Claimant Information</h2>

    <div class="details-grid">

        <div class="detail-box">

            <strong>Full Name</strong>

            <?php echo e($claim['claimant_name']); ?>

        </div>

        <div class="detail-box">

            <strong>Phone Number</strong>

            <?php echo e($claim['claimant_phone']); ?>

        </div>

        <div class="detail-box">

            <strong>Email Address</strong>

            <?php echo e($claim['claimant_email'] ?: 'Not provided'); ?>

        </div>

        <div class="detail-box">

            <strong>Claim Submission Date</strong>

            <?php echo e($claim['created_at']); ?>

        </div>

    </div>

</section>


<!-- DESCRIPTION COMPARISON -->

<section class="panel">

    <h2>Ownership Evidence Comparison</h2>

    <div class="details-grid">

        <div class="detail-box">

            <strong>Original Item Description</strong>

            <?php echo nl2br(e($claim['original_description'])); ?>

        </div>

        <div class="detail-box">

            <strong>Claimant's Description</strong>

            <?php echo nl2br(e($claim['claim_description'])); ?>

        </div>

    </div>


    <div class="detail-box" style="margin-top:20px;">

        <strong>Additional Ownership Evidence</strong>

        <?php echo nl2br(e($claim['proof_description'])); ?>

    </div>

</section>


<!-- ADMIN DECISION -->

<section class="panel">

    <h2>Administrator Decision</h2>

    <p>
        Current Status:

        <span class="status <?php echo e($claim['status']); ?>">

            <?php echo e(
                ucwords(str_replace('_', ' ', $claim['status']))
            ); ?>

        </span>

    </p>

    <br>

    <form method="POST">

        <input
            type="hidden"
            name="claim_id"
            value="<?php echo (int)$claim_id; ?>"
        >

        <input
            type="hidden"
            name="csrf_token"
            value="<?php echo e($_SESSION['csrf_token']); ?>"
        >

        <label for="rejection_reason">
            <strong>Rejection Reason</strong>

        </label>

        <br><br>

        <textarea
            name="rejection_reason"
            id="rejection_reason"
            maxlength="5000"
            placeholder="Required when rejecting a claim."
        ><?php echo e($claim['rejection_reason']); ?></textarea>


        <div class="decision-buttons">

            <button
                type="submit"
                name="status"
                value="approved"
                class="approve"
                onclick="return confirm('Are you sure you want to approve this ownership claim?');"
            >
                Approve Claim
            </button>



            <button
                type="submit"
                name="status"
                value="rejected"
                class="reject"
                onclick="return confirm('Are you sure you want to reject this claim?');"
            >
                Reject Claim
            </button>

        </div>

    </form>

</section>


</div>

</body>
</html>
