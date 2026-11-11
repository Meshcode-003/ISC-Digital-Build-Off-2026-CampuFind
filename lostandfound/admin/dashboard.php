<?php
declare(strict_types=1);
// Protect this page: only admins and super admins
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/notifications.php';

requireAdmin();

// Load CSRF protection for logout
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

// =====================================
// DASHBOARD STATISTICS
// =====================================

// Count lost items in CampusFind
$totalItems = $pdo->query(
    "SELECT COUNT(*) FROM items WHERE item_type = 'lost'"
)->fetchColumn();

// Count all claims
$totalClaims = $pdo->query(
    "SELECT COUNT(*) FROM claims"
)->fetchColumn();

// Count pending claims
$pendingClaims = $pdo->query(
    "SELECT COUNT(*) FROM claims WHERE status = 'pending'"
)->fetchColumn();

// Count approved claims
$approvedClaims = $pdo->query(
    "SELECT COUNT(*) FROM claims WHERE status = 'approved'"
)->fetchColumn();

// Count rejected claims
$rejectedClaims = $pdo->query(
    "SELECT COUNT(*) FROM claims WHERE status = 'rejected'"
)->fetchColumn();

// Count cancelled claims
$cancelledClaims = $pdo->query(
    "SELECT COUNT(*) FROM claims WHERE status = 'cancelled'"
)->fetchColumn();

// =====================================
// RETRIEVE RECENT CLAIMS
// =====================================

$sql = "SELECT
            cl.*,
            CONCAT(u.first_name, ' ', u.last_name) AS claimant_name,
            i.item_name,
            c.category_name
        FROM claims cl

        INNER JOIN users u
            ON cl.claimant_id = u.id

        INNER JOIN items i
            ON cl.item_id = i.id

        LEFT JOIN categories c
            ON i.category_id = c.id

        ORDER BY cl.created_at DESC
        LIMIT 10";

$stmt = $pdo->prepare($sql);
$stmt->execute();

$recentClaims = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

<title>Lost & Found Admin Dashboard</title>
<link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/notifications.css">

<style>

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f4f6fa;
    color: #263238;
}

.dashboard {
    display: flex;
    min-height: 100vh;
}

/* SIDEBAR */

.sidebar {
    width: 250px;
    background: #17243a;
    color: white;
    padding: 25px 18px;
    flex-shrink: 0;
}

.logo {
    font-size: 21px;
    font-weight: bold;
    margin-bottom: 40px;
}

.sidebar h4 {
    color: #9caec5;
    font-size: 12px;
    margin: 25px 10px 12px;
    text-transform: uppercase;
}

.sidebar a {
    display: block;
    padding: 13px;
    margin-bottom: 8px;
    color: #dce5f0;
    text-decoration: none;
    border-radius: 7px;
    transition: 0.3s;
}

.sidebar a:hover,
.sidebar a.active {
    background: #d4a017;
    color: white;
}

/* MAIN CONTENT */

.main-content {
    flex: 1;
    min-width: 0;
    padding: 30px;
}

.topbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 30px;
}

.topbar h1 {
    font-size: 27px;
}

.topbar p {
    color: #78828f;
    margin-top: 7px;
}

/* STATISTICS */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    padding: 23px;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,0.04);
    border-left: 4px solid #d4a017;
}

.stat-card p {
    color: #77818e;
    font-size: 14px;
}

.stat-card h2 {
    margin-top: 12px;
    font-size: 30px;
}

/* ANALYTICS */

.analytics {
    background: white;
    padding: 25px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.analytics h2 {
    margin-bottom: 20px;
}

.match-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.match-box {
    padding: 18px;
    background: #f4f6fa;
    border-radius: 8px;
}

.match-box h3 {
    font-size: 24px;
    margin-bottom: 7px;
}

.match-box p {
    color: #77818e;
    font-size: 13px;
}

/* TABLE */

.table-container {
    background: white;
    padding: 25px;
    border-radius: 12px;
    overflow-x: auto;
}

.table-container h2 {
    margin-bottom: 20px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 750px;
}

th, td {
    padding: 15px 12px;
    text-align: left;
    border-bottom: 1px solid #edf0f4;
    font-size: 14px;
}

th {
    background: #f8f9fc;
    color: #647080;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    display: inline-block;
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

.cancelled {
    background: #e0eaff;
    color: #2452a0;
}

.match-score {
    font-weight: bold;
}

.review-link {
    background: #17243a;
    color: white;
    padding: 8px 12px;
    border-radius: 5px;
    text-decoration: none;
    display: inline-block;
}

.review-link:hover {
    background: #d4a017;
}

.empty {
    text-align: center;
    padding: 30px;
    color: #78828f;
}

/* MOBILE */

@media(max-width: 900px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

}

@media(max-width: 650px) {

    .dashboard {
        display: block;
    }

    .sidebar {
        width: 100%;
    }

    .sidebar nav {
        display: flex;
        flex-wrap: wrap;
    }

    .sidebar a {
        margin-right: 5px;
    }

    .main-content {
        padding: 18px;
    }

    .stats-grid,
    .match-grid {
        grid-template-columns: 1fr;
    }

    .topbar {
        align-items: flex-start;
        flex-direction: column;
    }

}

</style>

    <script src="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8') ?>/campusfind-theme.css">
</head>


<body>

<div class="dashboard">


<!-- SIDEBAR -->

<aside class="sidebar">

    <div class="logo">
        Lost & Found
    </div>

    <?php renderNotificationBell($pdo, (int) $_SESSION['user_id']); ?>

    <h4>Main Menu</h4>

    <nav>

        <a href="dashboard.php" class="active">
            Dashboard
        </a>

        <a href="claims.php">
            Manage Claims
        </a>

        <a href="manage-items.php">
            Manage Lost Items
        </a>

    </nav>

    <h4>Public Website</h4>

    <nav>

        <a href="../index.php">
            Lost &amp; Found Module
        </a>

        <a href="<?= BASE_URL ?>/dashboard.php">
            My Account
        </a>

    </nav>

</aside>


<!-- MAIN DASHBOARD -->

<main class="main-content">



<div class="topbar">

    <div>
        <h1>Administration Dashboard</h1>

        <p>
            Welcome back,
            <?= e($_SESSION['first_name']) ?>!
            Manage your Lost & Found system.
        </p>
    </div>

    <div>

        <strong>
            <?= e($_SESSION['role_name']) ?>
        </strong>

        <p>
            <?= e($_SESSION['username']) ?>
        </p>

        <form method="POST"
              action="<?= BASE_URL ?>/logout.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(generateCsrfToken()) ?>"
            >

            <button type="submit">
                Logout
            </button>

        </form>

    </div>

</div>



<!-- STATISTICS -->

<div class="stats-grid">


    <div class="stat-card">

        <p>Total Reported Items</p>

        <h2><?php echo e($totalItems); ?></h2>

    </div>


    <div class="stat-card">

        <p>Total Claims</p>

        <h2><?php echo e($totalClaims); ?></h2>

    </div>


    <div class="stat-card">

        <p>Pending Claims</p>

        <h2><?php echo e($pendingClaims); ?></h2>

    </div>


    <div class="stat-card">

        <p>Approved Claims</p>

        <h2><?php echo e($approvedClaims); ?></h2>

    </div>


    <div class="stat-card">

        <p>Rejected Claims</p>

        <h2><?php echo e($rejectedClaims); ?></h2>

    </div>


    <div class="stat-card">

        <p>Cancelled Claims</p>

        <h2><?php echo e($cancelledClaims); ?></h2>

    </div>


</div>


<!-- RECENT CLAIMS -->

<section class="table-container">

    <h2>Recent Claim Submissions</h2>

    <table>

        <thead>

            <tr>

                <th>Claimant</th>

                <th>Lost Item</th>

                <th>Status</th>

                <th>Date</th>

            </tr>

        </thead>


        <tbody>

        <?php if (count($recentClaims) > 0): ?>

            <?php foreach ($recentClaims as $claim): ?>

                <tr>

                    <td>
                        <?php echo e($claim['claimant_name']); ?>
                    </td>

                    <td>
                        <?php echo e($claim['item_name']); ?>
                    </td>

                    <td>

                        <span class="status <?php echo e($claim['status']); ?>">

                            <?php echo e(ucwords(str_replace('_', ' ', $claim['status']))); ?>

                        </span>

                    </td>

                    <td>
                        <?php echo e($claim['created_at']); ?>
                    </td>

                </tr>

            <?php endforeach; ?>

        <?php else: ?>

            <tr>

                <td colspan="4" class="empty">

                    No claims have been submitted yet.

                </td>

            </tr>

        <?php endif; ?>

        </tbody>

    </table>

</section>


</main>

</div>

</body>

</html>
