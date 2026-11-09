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

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f6f9;
            color: #1f2937;
        }

        /* Navigation */

        .navbar {
            min-height: 75px;
            background: #102b25;
            color: white;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 7%;
            gap: 20px;
        }

        .brand {
            font-size: 23px;
            font-weight: 800;
            letter-spacing: 1px;
            color: #facc15;
        }

        .account {
            display: flex;
            align-items: center;
            gap: 18px;
            font-size: 14px;
        }

        .account form {
            margin: 0;
        }

        .logout-btn {
            background: transparent;
            border: 1px solid #facc15;
            color: #facc15;
            padding: 9px 17px;
            border-radius: 7px;
            cursor: pointer;
            font-weight: bold;
        }

        .logout-btn:hover {
            background: #facc15;
            color: #102b25;
        }

        /* Hero image */

        .hero {
            min-height: 440px;
            height: 52vh;
            max-height: 620px;
            position: relative;
            background-image:
                linear-gradient(
                    rgba(5, 25, 20, 0.42),
                    rgba(5, 25, 20, 0.72)
                ),
                url("https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcTPIQ0VVuRx3DoJ751xyYf5017DLq-t7rfS4ycNd3fn-WY_Q_NHpSrhYSA&s=10");
            background-size: cover;
            background-position: center;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 30px;
            color: white;
        }

        .hero-content {
            max-width: 850px;
        }

        .hero-label {
            color: #facc15;
            font-weight: bold;
            letter-spacing: 4px;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .hero h1 {
            font-size: clamp(38px, 7vw, 76px);
            font-weight: 900;
            letter-spacing: 3px;
            margin-bottom: 18px;
            text-shadow: 0 4px 15px rgba(0,0,0,0.4);
        }

        .hero p {
            font-size: clamp(15px, 2vw, 19px);
            line-height: 1.7;
            color: #f1f5f9;
        }

        .hero-line {
            width: 85px;
            height: 5px;
            background: #facc15;
            margin: 25px auto 0;
            border-radius: 5px;
        }

        /* Main services */

        .main-content {
            max-width: 1250px;
            margin: auto;
            padding: 55px 25px 70px;
        }

        .welcome {
            text-align: center;
            margin-bottom: 38px;
        }

        .welcome h2 {
            font-size: 30px;
            color: #102b25;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #64748b;
            line-height: 1.6;
        }

        .services {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 25px;
        }

        .service-card {
            background: white;
            border-radius: 15px;
            padding: 30px 25px;
            box-shadow: 0 5px 22px rgba(0,0,0,0.06);
            border-top: 5px solid #d1d5db;
            display: flex;
            flex-direction: column;
            transition: transform 0.25s, box-shadow 0.25s;
        }

        .service-card:hover {
            transform: translateY(-7px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
        }

        .mentorship {
            border-top-color: #2563eb;
        }

        .lost {
            border-top-color: #d97706;
        }

        .notices {
            border-top-color: #059669;
        }

        .service-icon {
            font-size: 42px;
            margin-bottom: 20px;
        }

        .service-card h3 {
            color: #102b25;
            font-size: 21px;
            margin-bottom: 13px;
        }

        .service-card p {
            color: #64748b;
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 25px;
            flex-grow: 1;
        }

        .service-btn {
            display: inline-block;
            text-decoration: none;
            text-align: center;
            padding: 13px 15px;
            border-radius: 8px;
            color: white;
            font-size: 14px;
            font-weight: bold;
            transition: opacity 0.2s;
        }

        .service-btn:hover {
            opacity: 0.85;
        }

        .mentorship-btn {
            background: #2563eb;
        }

        .lost-btn {
            background: #d97706;
        }

        .notices-btn {
            background: #059669;
        }

        /* Footer */

        footer {
            background: #102b25;
            color: #d1d5db;
            text-align: center;
            padding: 25px 15px;
            font-size: 13px;
            line-height: 1.8;
        }

        footer strong {
            color: #facc15;
        }

        /* Responsive layout */

        @media (max-width: 850px) {
            .services {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .navbar {
                padding: 15px 5%;
                align-items: flex-start;
                flex-direction: column;
            }

            .account {
                width: 100%;
                justify-content: space-between;
                flex-wrap: wrap;
            }

            .hero {
                min-height: 390px;
                height: 55vh;
            }

            .hero h1 {
                letter-spacing: 1px;
            }

            .main-content {
                padding: 40px 18px;
            }

            .services {
                grid-template-columns: 1fr;
            }

            .welcome h2 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

    <header class="navbar">

        <div class="brand">
            UEAB CAMPUS VIBE
        </div>

        <div class="account">

            <span>
                Welcome, <?= $escape($name) ?>
                (<?= $escape($role) ?>)
            </span>

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


    <!-- Upper half: Campus image and title -->
    <section class="hero">

        <div class="hero-content">

            <div class="hero-label">
                UNIVERSITY OF EASTERN AFRICA, BARATON
            </div>

            <h1>UEAB CAMPUS VIBE</h1>

            <p>
                Discover campus life, connect with people,
                find what matters and stay informed.
                Everything you need for your campus experience
                in one place.
            </p>

            <div class="hero-line"></div>

        </div>

    </section>


    <!-- Lower half: Three main services -->
    <main class="main-content">

        <section class="welcome">

            <h2>Hello, <?= $escape($name) ?>!</h2>

            <p>
                Welcome to your campus community portal.
                Choose a service below to get started.
            </p>

        </section>


        <section class="services">

            <?php foreach ($services as $service): ?>

                <article class="service-card <?= $escape($service['class']) ?>">

                    <div class="service-icon">
                        <?= $service['icon'] ?>
                    </div>

                    <h3>
                        <?= $escape($service['title']) ?>
                    </h3>

                    <p>
                        <?= $escape($service['description']) ?>
                    </p>

                    <a
                        class="service-btn <?= $escape($service['class']) ?>-btn"
                        href="<?= $escape($service['link']) ?>"
                    >
                        <?= $escape($service['button']) ?>
                    </a>

                </article>

            <?php endforeach; ?>

        </section>

    </main>


    <!-- Footer -->
    <footer>

        <p>
            <strong>UEAB CAMPUS VIBE</strong>
            | University of Eastern Africa, Baraton
        </p>

        <p>
            Connecting our campus community, one service at a time.
        </p>

        <p>
            &copy; <?= date('Y') ?> CampusFind. All rights reserved.
        </p>

    </footer>

</body>
</html>
