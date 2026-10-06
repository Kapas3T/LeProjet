<?php
$paragraphs = [
    "Lorem ipsum dolor sit amet, consectetur adipiscing elit, sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat.",
    "Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat non proident, sunt in culpa qui officia deserunt mollit anim id est laborum.",
    "Sed ut perspiciatis unde omnis iste natus error sit voluptatem accusantium doloremque laudantium, totam rem aperiam, eaque ipsa quae ab illo inventore veritatis et quasi architecto beatae vitae dicta sunt explicabo.",
    "Nemo enim ipsam voluptatem quia voluptas sit aspernatur aut odit aut fugit, sed quia consequuntur magni dolores eos qui ratione voluptatem sequi nesciunt.",
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lorem Ipsum</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            max-width: 720px;
            margin: 40px auto;
            padding: 0 16px;
            line-height: 1.6;
            color: #222;
        }
        h1 { margin-bottom: 0.2em; }
        small { color: #888; }
    </style>
</head>
<body>
    <h1>Lorem Ipsum</h1>
    <small>PHP <?= PHP_VERSION ?> &middot; <?= date('Y-m-d H:i:s') ?></small>

    <?php foreach ($paragraphs as $p): ?>
        <p><?= htmlspecialchars($p) ?></p>
    <?php endforeach; ?>
    <h2>Les Apprenties</h2>
    <li><a href="/pages/about/anton.php" title="About Anton">Anton</a></li>
    <li><a href="/pages/about/victor.php" title="About Victor">Victor</a></li>
    <li><a href="/pages/about/bryan.php" title="About Bryan">Bryan</a></li>
    <li><a href="/pages/about/thomas.php" title="About Thomas">Thomas</a></li>
    <li><a href="/pages/about/davide.php" title="About Davide">Davide</a></li>
</body>
</html>