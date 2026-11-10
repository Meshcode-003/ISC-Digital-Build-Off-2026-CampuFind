<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Invalid request method.');
}

$userId = (int) $_SESSION['user_id'];


// Verify CSRF token.

$submittedToken = $_POST['csrf_token'] ?? '';

if (
    !is_string($submittedToken) ||
    !hash_equals(generateCsrfToken(), $submittedToken)
) {
    exit('Invalid security token. Please return to the form and try again.');
}


// Collect form information.

$itemName = trim($_POST['item_name'] ?? '');
$categoryId = filter_var(
    $_POST['category_id'] ?? null,
    FILTER_VALIDATE_INT
);

$description = trim($_POST['description'] ?? '');
$location = trim($_POST['location'] ?? '');
$dateOccurred = trim($_POST['date_occurred'] ?? '');
$contactDetails = trim($_POST['contact_details'] ?? '');
$imageData = $_POST['captured_image'] ?? '';


// Validate required information.

if (
    $itemName === '' ||
    !$categoryId ||
    $description === '' ||
    $location === '' ||
    $dateOccurred === '' ||
    $imageData === ''
) {
    exit('Please complete all required fields and capture a photograph.');
}

if (
    mb_strlen($itemName) > 150 ||
    mb_strlen($description) > 5000 ||
    mb_strlen($location) > 255 ||
    mb_strlen($contactDetails) > 25
) {
    exit('One or more fields exceed the allowed length.');
}


// Validate date.

$date = DateTime::createFromFormat('!Y-m-d', $dateOccurred);

if (
    !$date ||
    $date->format('Y-m-d') !== $dateOccurred ||
    $dateOccurred > date('Y-m-d')
) {
    exit('Please enter a valid date.');
}


// Verify that the selected category exists and is active.

$categoryStatement = $pdo->prepare(
    "SELECT id
     FROM categories
     WHERE id = :category_id
       AND status = 'active'
     LIMIT 1"
);

$categoryStatement->execute([
    'category_id' => $categoryId
]);

if (!$categoryStatement->fetch()) {
    exit('The selected category is unavailable.');
}


// Validate captured JPEG image.

if (
    !is_string($imageData) ||
    !preg_match('/^data:image\/jpeg;base64,/', $imageData)
) {
    exit('Invalid photograph format.');
}

$encodedImage = substr(
    $imageData,
    strpos($imageData, ',') + 1
);

$image = base64_decode($encodedImage, true);

if ($image === false || strlen($image) > 5 * 1024 * 1024) {
    exit('Invalid photograph or image exceeds 5MB.');
}

$imageInfo = getimagesizefromstring($image);

if (
    $imageInfo === false ||
    $imageInfo['mime'] !== 'image/jpeg'
) {
    exit('The photograph must be a valid JPEG image.');
}


// Prepare image storage.

$imageDirectory = __DIR__ . '/uploads/items';

if (
    !is_dir($imageDirectory) &&
    !mkdir($imageDirectory, 0755, true) &&
    !is_dir($imageDirectory)
) {
    exit('Unable to create image storage directory.');
}

$imageName = 'item_' . bin2hex(random_bytes(16)) . '.jpg';

$imagePath = 'uploads/items/' . $imageName;

$absoluteImagePath = $imageDirectory . '/' . $imageName;

if (file_put_contents($absoluteImagePath, $image) === false) {
    exit('Unable to save photograph.');
}


// Save the item and photograph in CampusFind.

try {

    $pdo->beginTransaction();

    // Insert the lost item.

    $itemStatement = $pdo->prepare(
        "INSERT INTO items (
            user_id,
            category_id,
            item_type,
            item_name,
            description,
            date_occurred,
            location,
            contact_details,
            status
        ) VALUES (
            :user_id,
            :category_id,
            'lost',
            :item_name,
            :description,
            :date_occurred,
            :location,
            :contact_details,
            'pending'
        )"
    );

    $itemStatement->execute([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'item_name' => $itemName,
        'description' => $description,
        'date_occurred' => $dateOccurred,
        'location' => $location,
        'contact_details' => $contactDetails !== ''
            ? $contactDetails
            : null
    ]);

    $itemId = (int) $pdo->lastInsertId();


    // Insert the photograph into item_images.

    $imageStatement = $pdo->prepare(
        "INSERT INTO item_images (
            item_id,
            image_path,
            original_filename,
            file_size,
            mime_type,
            is_primary
        ) VALUES (
            :item_id,
            :image_path,
            :original_filename,
            :file_size,
            'image/jpeg',
            1
        )"
    );

    $imageStatement->execute([
        'item_id' => $itemId,
        'image_path' => $imagePath,
        'original_filename' => $imageName,
        'file_size' => strlen($image)
    ]);

    $pdo->commit();

    header('Location: index.php?report=submitted');
    exit;

} catch (Throwable $exception) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (file_exists($absoluteImagePath)) {
        unlink($absoluteImagePath);
    }

    error_log('Lost item report error: ' . $exception->getMessage());

    http_response_code(500);

    exit('Unable to submit your report. Please try again.');

}