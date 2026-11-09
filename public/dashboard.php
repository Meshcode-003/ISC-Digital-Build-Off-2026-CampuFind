<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireLogin();

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

$name = trim(
    ($_SESSION['first_name'] ?? '') . ' ' .
    ($_SESSION['last_name'] ?? '')
);

if ($name === '') {
    $name = $_SESSION['username'] ?? 'Campus Member';
}

$role = $_SESSION['role_name'] ?? 'user';

$services = [
    [
        'title' => 'Mentorship',
        'description' => 'Connect with experienced mentors, receive guidance and grow academically and professionally.',
        'icon' => '🎓',
        'link' => '../mentorship/index.php',
        'button' => 'Explore Mentorship',
        'class' => 'mentorship'
    ],
    [
        'title' => 'Lost & Found',
        'description' => 'Report lost belongings, browse found items and help reunite people with their property.',
        'icon' => '🔎',
        'link' => '../lostandfound/lost-items.php',
        'button' => 'Explore Lost & Found',
        'class' => 'lost'
    ],
    [
        'title' => 'Notices & Announcements',
        'description' => 'Stay updated with university announcements, important notices, events and documents.',
        'icon' => '📢',
        'link' => '../notices/index.php',
        'button' => 'View Announcements',
        'class' => 'notices'
    ]
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>UEAB CAMPUS VIBE | <?= $escape(APP_NAME) ?></title>

    <link rel="stylesheet" href="<?= BASE_URL ?>/dashboard.css">
</head>

<body>

    <header class="navbar">

        <a href="index.php" class="brand">
            UEAB <span>CAMPUS VIBE</span>
        </a>

        <nav class="main-nav">
            <a href="#home" class="active">Home</a>
            <a href="#services">Campus Services</a>
        </nav>

        <div class="account">

            <div class="user-info">
                <span class="user-greeting">Welcome back,</span>
                <strong><?= $escape($name) ?></strong>
                <small><?= $escape($role) ?></small>
            </div>

            <form method="post" action="<?= BASE_URL ?>/logout.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= $escape(generateCsrfToken()) ?>"
                >

                <button type="submit" class="logout-btn">
                    Sign Out
                </button>

            </form>

        </div>

    </header>


    <main>

        <section class="welcome-section" id="home">

            <div class="welcome-content">

                <span class="section-label">
                    UNIVERSITY OF EASTERN AFRICA, BARATON
                </span>

                <h1>
                    Your campus.<br>
                    Your community.
                </h1>

                <p>
                    Welcome to Campus Vibe, your central place to
                    connect, discover opportunities, find lost
                    belongings and stay informed about life at UEAB.
                </p>

                <a href="#services" class="explore-link">
                    Explore Campus Services
                    <span>→</span>
                </a>

            </div>

            <div class="welcome-image">
                <img
                    src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTPIQ0VVuRx3DoJ751xyYf5017DLq-t7rfS4ycNd3fn-WY_Q_NHpSrhYSA&s=10"
                    alt="University of Eastern Africa, Baraton campus"
                >
            </div>

        </section>


        <section class="services-section" id="services">

            <div class="section-heading">

                <div>
                    <span class="section-label">DISCOVER CAMPUS VIBE</span>
                    <h2>Everything starts here.</h2>
                    <p>
                        Explore the services available to make your
                        university experience better.
                    </p>
                </div>

                <span class="service-count">03 SERVICES</span>

            </div>


            <div class="services-list">

                <?php foreach ($services as $index => $service): ?>

                    <article class="service-item <?= $escape($service['class']) ?>">

                        <div class="service-number">
                            0<?= $index + 1 ?>
                        </div>

                        <div class="service-icon">
                            <?= $service['icon'] ?>
                        </div>

                        <div class="service-details">

                            <h3>
                                <?= $escape($service['title']) ?>
                            </h3>

                            <p>
                                <?= $escape($service['description']) ?>
                            </p>

                        </div>

                        <a
                            href="<?= $escape($service['link']) ?>"
                            class="service-link"
                        >
                            <?= $escape($service['button']) ?>
                            <span>→</span>
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        </section>


        <section class="community-section">

            <div class="community-content">

                <span class="section-label">CAMPUS LIFE</span>

                <h2>
                    A better campus experience begins with connection.
                </h2>

                <p>
                    Whether you are looking for something, sharing
                    important information or seeking guidance,
                    Campus Vibe brings the university community closer.
                </p>

            </div>

        </section>

    </main>


    <footer class="footer">

        <div class="footer-brand">
            UEAB CAMPUS VIBE
        </div>

        <p>
            University of Eastern Africa, Baraton
        </p>

        <p>
            Connecting our campus community, one service at a time.
        </p>

        <div class="footer-bottom">
            &copy; <?= date('Y') ?> CampusFind. All rights reserved.
        </div>

    </footer>

</body>
</html>
