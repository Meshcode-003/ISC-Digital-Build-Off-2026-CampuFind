
<?php

session_start();

require_once "../config/database.php";
require_once "../includes/matching-engine.php";
// Generate security token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get item ID from URL
$item_id = filter_input(INPUT_GET, 'item_id', FILTER_VALIDATE_INT);

// Also accept item ID when the form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_VALIDATE_INT);
}

if (!$item_id || $item_id < 1) {
    die("Invalid item selected.");
}


// Retrieve the selected lost item
$sql = "SELECT * FROM lost_items WHERE id = ?";

$stmt = $conn->prepare($sql);
$stmt->execute([$item_id]);

$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    die("The selected item does not exist.");
}

if (($item['item_status'] ?? 'lost') === 'returned') {
    die("Sorry, this item has already been returned.");
}


// Variables for form messages
$error = "";
$success = "";


// Process claim submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Verify security token
    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $error = "Invalid form submission. Please refresh and try again.";
    } else {

        // Collect form information
        $claimant_name = trim($_POST['claimant_name'] ?? '');

        $claimant_phone = trim($_POST['claimant_phone'] ?? '');

        $claimant_email = trim($_POST['claimant_email'] ?? '');

        $claim_description = trim($_POST['claim_description'] ?? '');

        $ownership_evidence = trim($_POST['ownership_evidence'] ?? '');


        // Validate information
        if (
            $claimant_name === '' ||
            $claimant_phone === '' ||
            $claim_description === '' ||
            $ownership_evidence === ''
        ) {

            $error = "Please fill in all required fields.";

        } elseif (!preg_match("/^[a-zA-Z\s.'-]{2,150}$/", $claimant_name)) {

            $error = "Please enter a valid full name.";

        } elseif (!preg_match("/^[0-9+\-\s()]{7,30}$/", $claimant_phone)) {

            $error = "Please enter a valid phone number.";

        } elseif (
            $claimant_email !== '' &&
            !filter_var($claimant_email, FILTER_VALIDATE_EMAIL)
        ) {

            $error = "Please enter a valid email address.";

        } else {

         
try {

    // 1. Prepare the claimant's information
    $claimData = [
        'claim_description' => $claim_description,
        'ownership_evidence' => $ownership_evidence
    ];


    // 2. Run the Smart Match Assistant
    $analysis = analyzeClaim($item, $claimData);


    // 3. Save the claim and AI analysis in MySQL
    $sql = "INSERT INTO claims (
        item_id,
        claimant_name,
        claimant_phone,
        claimant_email,
        claim_description,
        ownership_evidence,
        status,
        ai_score,
        ai_recommendation,
        ai_explanation
    ) VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)";


    $stmt = $conn->prepare($sql);

    $stmt->execute([

        $item_id,

        $claimant_name,

        $claimant_phone,

        $claimant_email !== '' ? $claimant_email : null,

        $claim_description,

        $ownership_evidence,

        $analysis['score'],

        $analysis['recommendation'],

        $analysis['explanation']

    ]);


    $success = "Your ownership claim has been submitted successfully!";

    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));


} catch (PDOException $e) {

    error_log("Claim submission error: " . $e->getMessage());

    $error = "Unable to submit your claim right now. Please try again.";

} catch (Throwable $e) {

    error_log("Matching engine error: " . $e->getMessage());

    $error = "An error occurred while processing your claim.";

}


        }

    }

}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Claim Lost Item</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f6f9;
            margin: 0;
            padding: 30px 15px;
        }

        .claim-container {
            max-width: 650px;
            margin: 30px auto;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
        }

        h1 {
            text-align: center;
            color: #222;
        }

        .selected-item {
            background: #f1f3f5;
            padding: 18px;
            border-radius: 8px;
            margin-bottom: 25px;
        }

        .selected-item img {
            width: 100%;
            max-height: 230px;
            object-fit: contain;
            border-radius: 8px;
        }

        label {
            display: block;
            margin-top: 18px;
            font-weight: bold;
        }

        input, textarea {
            width: 100%;
            padding: 12px;
            margin-top: 7px;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 15px;
        }

        textarea {
            min-height: 100px;
            resize: vertical;
        }

        button {
            width: 100%;
            padding: 14px;
            margin-top: 25px;
            background: #d4a017;
            border: none;
            border-radius: 6px;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #a87900;
        }

        .message {
            padding: 12px;
            margin-bottom: 15px;
            border-radius: 6px;
        }

        .error {
            background: #ffe5e5;
            color: #b00020;
        }

        .success {
            background: #e1f7e7;
            color: #176b2c;
        }

        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #333;
        }

    </style>

</head>

<body>

<div class="claim-container">

    <h1>Claim This Item</h1>

    <p>
        Please provide accurate information to help us verify
        that this item belongs to you.
    </p>


    <div class="selected-item">

        <img
            src="../<?php echo htmlspecialchars($item['image'], ENT_QUOTES, 'UTF-8'); ?>"
            alt="Selected lost item"
        >

        <h2>
            <?php echo htmlspecialchars($item['item_name'], ENT_QUOTES, 'UTF-8'); ?>
        </h2>

        <p>
            <strong>Category:</strong>
            <?php echo htmlspecialchars($item['category'], ENT_QUOTES, 'UTF-8'); ?>
        </p>

        <p>
            <strong>Location:</strong>
            <?php echo htmlspecialchars($item['location_lost'], ENT_QUOTES, 'UTF-8'); ?>
        </p>

    </div>


    <?php if ($error): ?>

        <div class="message error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
        </div>

    <?php endif; ?>


    <?php if ($success): ?>

        <div class="message success">

            <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>

            <p>
                Your request is now pending administrator review.
            </p>

        </div>

        <a href="../lost-items.php" class="back-link">
            Return to Lost Items Gallery
        </a>

    <?php else: ?>


        <form method="POST" action="">

            <input
                type="hidden"
                name="item_id"
                value="<?php echo (int)$item_id; ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>"
            >


            <label>Full Name *</label>

            <input
                type="text"
                name="claimant_name"
                maxlength="150"
                required
                value="<?php echo htmlspecialchars($_POST['claimant_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            >


            <label>Phone Number *</label>

            <input
                type="tel"
                name="claimant_phone"
                maxlength="30"
                required
                value="<?php echo htmlspecialchars($_POST['claimant_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            >


            <label>Email Address (Optional)</label>

            <input
                type="email"
                name="claimant_email"
                maxlength="150"
                value="<?php echo htmlspecialchars($_POST['claimant_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
            >


            <label>Describe Your Lost Item *</label>

            <textarea
                name="claim_description"
                required
                maxlength="5000"
                placeholder="Describe the item you believe belongs to you..."
            ><?php echo htmlspecialchars($_POST['claim_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>


            <label>Provide Unique Ownership Evidence *</label>

            <textarea
                name="ownership_evidence"
                required
                maxlength="5000"
                placeholder="Mention unique features, serial numbers, contents or other details only the real owner may know..."
            ><?php echo htmlspecialchars($_POST['ownership_evidence'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>


            <button type="submit">
                Submit Ownership Claim
            </button>

        </form>

    <?php endif; ?>


    <a href="../lost-items.php" class="back-link">
        Back to Lost Items
    </a>

</div>

</body>
</html>
