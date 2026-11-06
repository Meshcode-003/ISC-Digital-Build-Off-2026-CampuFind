<?php
declare(strict_types=1);

// Load authentication, session and database connection
require_once dirname(__DIR__) . '/includes/auth.php';

// Only admins and super admins can manage claims
requireAdmin();

// =====================================
// SEARCH AND FILTER VALUES
// =====================================

$searchInput = $_GET['search'] ?? '';
$statusInput = $_GET['status'] ?? '';

$search = is_string($searchInput) ? trim($searchInput) : '';
$status = is_string($statusInput) ? $statusInput : '';

$allowedStatuses = [
    'pending',
    'under_review',
    'approved',
    'rejected'
];

// =====================================
// BUILD QUERY CONDITIONS
// =====================================

$conditions = [];
$params = [];

if ($search !== '') {

    $conditions[] = "(
        CONCAT(u.first_name, ' ', u.last_name) LIKE ?
        OR u.phone LIKE ?
        OR u.email LIKE ?
        OR i.item_name LIKE ?
    )";

    $term = "%$search%";

    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

if (in_array($status, $allowedStatuses, true)) {

    $conditions[] = "cl.status = ?";

    $params[] = $status;
}

// =====================================
// RETRIEVE CLAIMS FROM CAMPUSFIND
// =====================================

$sql = "SELECT
            cl.*,

            CONCAT(u.first_name, ' ', u.last_name)
                AS claimant_name,

            u.phone AS claimant_phone,
            u.email AS claimant_email,

            i.item_name,
            i.item_type,
            i.location AS item_location,

            c.category_name

        FROM claims cl

        INNER JOIN users u
            ON cl.claimant_id = u.id

        INNER JOIN items i
            ON cl.item_id = i.id

        LEFT JOIN categories c
            ON i.category_id = c.id";

if (!empty($conditions)) {

    $sql .= " WHERE " . implode(" AND ", $conditions);

}

$sql .= " ORDER BY cl.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================
// SAFE OUTPUT FUNCTION
// =====================================

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

?>


<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Claims</title>

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
    max-width: 1400px;
    margin: auto;
    padding: 30px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 25px;
    gap: 15px;
}

.header a {
    text-decoration: none;
    color: white;
    background: #17243a;
    padding: 12px 18px;
    border-radius: 6px;
}

.filter-box {
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 25px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

input, select {
    padding: 12px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 14px;
}

input {
    flex: 1;
    min-width: 200px;
}

button {
    background: #d4a017;
    color: white;
    border: none;
    padding: 12px 20px;
    border-radius: 6px;
    cursor: pointer;
}

.table-box {
    background: white;
    padding: 20px;
    border-radius: 10px;
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

th, td {
    padding: 15px 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
    font-size: 14px;
}

th {
    background: #f8f9fc;
}

.status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
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

.under_review {
    background: #e0eaff;
    color: #2452a0;
}

.review-button {
    display: inline-block;
    background: #17243a;
    color: white;
    padding: 9px 13px;
    text-decoration: none;
    border-radius: 5px;
}

.empty {
    text-align: center;
    padding: 30px;
}

@media(max-width: 600px) {
    .container {
        padding: 15px;
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
            <h1>Claim Management</h1>
            <p>Review and manage ownership requests.</p>
        </div>

        <a href="dashboard.php">
            Back to Dashboard
        </a>

    </div>


    <!-- SEARCH AND FILTER -->

    <form method="GET" class="filter-box">

        <input
            type="text"
            name="search"
            placeholder="Search claimant, phone or item..."
            value="<?php echo e($search); ?>"
        >

        <select name="status">

            <option value="">All Claims</option>

            <option value="pending"
                <?php echo $status === 'pending' ? 'selected' : ''; ?>>
                Pending
            </option>

            <option value="under_review"
                <?php echo $status === 'under_review' ? 'selected' : ''; ?>>
                Under Review
            </option>

            <option value="approved"
                <?php echo $status === 'approved' ? 'selected' : ''; ?>>
                Approved
            </option>

            <option value="rejected"
                <?php echo $status === 'rejected' ? 'selected' : ''; ?>>
                Rejected
            </option>

        </select>

        <button type="submit">
            Search
        </button>

    </form>


    <!-- CLAIMS TABLE -->

    <div class="table-box">

        <h2>Ownership Requests</h2>

        <table>

            <thead>

                <tr>
                    <th>Claimant</th>
                    <th>Phone</th>
                    <th>Lost Item</th>
                    <th>AI Score</th>
                    <th>Recommendation</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>

            </thead>

            <tbody>

            <?php if (count($claims) > 0): ?>

                <?php foreach ($claims as $claim): ?>

                    <tr>

                        <td>
                            <?php echo e($claim['claimant_name']); ?>
                        </td>

                        <td>
                            <?php echo e($claim['claimant_phone']); ?>
                        </td>

                        <td>
                            <?php echo e($claim['item_name']); ?>
                        </td>

                        <td>
                            <?php echo $claim['ai_score'] !== null
                                ? e($claim['ai_score']) . '%'
                                : 'N/A'; ?>
                        </td>

                        <td>
                            <?php echo e($claim['ai_recommendation'] ?? 'Not analyzed'); ?>
                        </td>

                        <td>

                            <span class="status <?php echo e($claim['status']); ?>">

                                <?php echo e(
                                    ucwords(str_replace('_', ' ', $claim['status']))
                                ); ?>

                            </span>

                        </td>

                        <td>
                            <?php echo e($claim['created_at']); ?>
                        </td>

                        <td>

                            <a
                                class="review-button"
                                href="review-claim.php?id=<?php echo (int)$claim['id']; ?>"
                            >
                                Review Claim
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="8" class="empty">
                        No claims found.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

</body>
</html>
