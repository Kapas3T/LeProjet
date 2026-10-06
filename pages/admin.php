<?php
// Admin page for the Raspberry Pi API. The API token stays in the PHP session, never in the browser.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
session_cache_limiter(''); // we set caching headers ourselves: pictures are cached, pages are not
session_start();
if (!isset($_GET['img'])) {
    header('Cache-Control: no-store');
}

$API = getenv('PI_API') ?: 'https://pi-lab-01.tailfc7dca.ts.net'; // public HTTPS address of the Pi (Tailscale Funnel)

function api($method, $path, $body = null, $file = null) {
    global $API;
    $headers = '';
    if (!empty($_SESSION['token'])) {
        $headers .= "Authorization: Bearer {$_SESSION['token']}\r\n";
    }
    $content = null;
    if ($file !== null) {
        $b = bin2hex(random_bytes(8));
        $headers .= "Content-Type: multipart/form-data; boundary=$b\r\n";
        $content = "--$b\r\nContent-Disposition: form-data; name=\"image\"; filename=\"upload\"\r\n"
                 . "Content-Type: application/octet-stream\r\n\r\n" . file_get_contents($file) . "\r\n--$b--\r\n";
    } elseif ($body !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $content = json_encode($body);
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $content,
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $raw = @file_get_contents($API . $path, false, $ctx);
    if ($raw === false) {
        $GLOBALS['api_error'] = error_get_last()['message'] ?? '';
        return [0, null, ''];
    }
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m);
    return [(int)$m[1], json_decode($raw, true), $raw];
}

function unreachable_message() {
    global $API;
    if (str_starts_with($API, 'https') && !extension_loaded('openssl')) {
        return 'PHP cannot make HTTPS requests: enable extension=openssl in php.ini';
    }
    $reason = $GLOBALS['api_error'] ?? '';
    $hint = '';
    if (stripos($reason, 'certificate') !== false || stripos($reason, 'SSL') !== false) {
        $hint = ' Your PHP cannot verify HTTPS certificates: set openssl.cafile in php.ini to a cacert.pem file.';
    } elseif (stripos($reason, 'getaddrinfo') !== false || stripos($reason, 'resolve') !== false) {
        $hint = ' Your computer cannot look up the address: check your internet connection or DNS.';
    }
    return 'The Pi is unreachable.' . $hint . ($reason ? " (PHP says: $reason)" : '') . ' If everything on your side is fine, the Pi may be switched off or its vault locked.';
}

$_SESSION['csrf'] = $_SESSION['csrf'] ?? bin2hex(random_bytes(16));
$msg = '';
$ok = '';

// Image proxy: admin.php?img=ID shows the picture, &t=1 gives the small thumbnail, &download=1 saves the original.
// The &v= value changes whenever the picture is replaced, so the browser may keep each version for a day.
if (isset($_GET['img'])) {
    if (empty($_SESSION['token'])) {
        http_response_code(403);
        exit;
    }
    $imgId = (int)$_GET['img'];
    [$code, , $raw] = api('GET', "/items/$imgId/" . (isset($_GET['t']) ? 'thumb' : 'image'));
    if ($code !== 200) {
        http_response_code(404);
        exit;
    }
    if (str_starts_with($raw, "\x89PNG")) {
        [$type, $ext] = ['image/png', 'png'];
    } elseif (str_starts_with($raw, "\xff\xd8")) {
        [$type, $ext] = ['image/jpeg', 'jpg'];
    } else {
        [$type, $ext] = ['image/webp', 'webp'];
    }
    $disposition = isset($_GET['download']) ? 'attachment' : 'inline';
    header("Content-Type: $type");
    header('Cache-Control: ' . (isset($_GET['download']) ? 'no-store' : 'private, max-age=86400'));
    header("Content-Disposition: $disposition; filename=\"item-$imgId.$ext\"");
    header('X-Content-Type-Options: nosniff');
    echo $raw;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        http_response_code(403);
        exit('Bad CSRF token');
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'login') {
        [$code, $data] = api('POST', '/login', ['username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '']);
        if ($code === 200 && isset($data['token'])) {
            session_regenerate_id(true);
            $_SESSION['token'] = $data['token'];
            $_SESSION['user'] = $data['username'];
        } else {
            $msg = $code === 0 ? unreachable_message() : ($code === 429 ? ($data['error'] ?? 'Too many attempts') : 'Wrong username or password');
        }
    } elseif ($action === 'logout') {
        session_destroy();
        header('Location: admin.php');
        exit;
    } elseif (!empty($_SESSION['token'])) {
        $fields = ['title' => $_POST['title'] ?? '', 'note' => $_POST['note'] ?? '', 'shared' => !empty($_POST['shared'])];
        $data = null;
        if ($action === 'add') {
            [$code, $data] = api('POST', '/items', $fields);
        } elseif ($action === 'save') {
            [$code, $data] = api('PUT', "/items/$id", $fields);
        } elseif ($action === 'password') {
            if (($_POST['new'] ?? '') !== ($_POST['repeat'] ?? '')) {
                $code = 400;
                $msg = 'The two new passwords do not match';
            } else {
                [$code, $data] = api('POST', '/password', ['old' => $_POST['old'] ?? '', 'new' => $_POST['new'] ?? '']);
                if ($code === 200) {
                    $_SESSION['token'] = $data['token'];
                    $ok = 'Password changed. Other devices were logged out.';
                } else {
                    $msg = $data['error'] ?? 'Could not change password';
                }
            }
        } elseif ($action === 'delete') {
            [$code, $data] = api('DELETE', "/items/$id");
        } elseif ($action === 'upload') {
            $f = $_FILES['image'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                $code = 400;
                $msg = 'Upload failed (PHP limit is ' . ini_get('upload_max_filesize') . ')';
            } else {
                [$code, $data] = api('POST', "/items/$id/image", null, $f['tmp_name']);
            }
        }
        if (($code ?? 200) === 401) {
            unset($_SESSION['token']);
            $msg = 'Session expired, log in again';
        } elseif (!$msg && ($code ?? 200) >= 400) {
            $msg = $data['error'] ?? 'Error ' . $code; // e.g. "storage limit of 300 MB reached"
        }
    }
}

$items = [];
if (!empty($_SESSION['token'])) {
    [$code, $data] = api('GET', '/items');
    if ($code === 200) {
        $items = $data;
    } else {
        unset($_SESSION['token']);
        $msg = $msg ?: ($code === 0 ? unreachable_message() : 'Session expired, log in again');
    }
}
$logged = !empty($_SESSION['token']);
$mine = array_filter($items, fn($i) => $i['mine']);
$others = array_filter($items, fn($i) => !$i['mine']);
$csrf = htmlspecialchars($_SESSION['csrf']);
function e($s) { return htmlspecialchars((string)$s); }
function image_block($it) {
    $iid = (int)$it['id'];
    if ($it['has_image']) {
        $v = urlencode($it['v'] ?? '');
        echo '<a href="admin.php?img=' . $iid . '&amp;v=' . $v . '" target="_blank">'
           . '<img class="photo" loading="lazy" src="admin.php?img=' . $iid . '&amp;t=1&amp;v=' . $v . '" alt="' . e($it['title']) . '"></a>';
    } else {
        echo '<div class="photo empty">No image yet</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Photo vault</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
    <header class="top">
        <h1>Photo vault</h1>
        <nav>
            <a href="how-it-works.php">How does it work?</a>
            <a href="/index.php">Home</a>
            <?php if ($logged): ?>
            <span class="id">Signed in as <b><?= e($_SESSION['user'] ?? '') ?></b></span>
            <form method="post" style="margin:0">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="logout">
                <button class="ghost">Log out</button>
            </form>
            <?php endif; ?>
        </nav>
    </header>

    <?php if ($msg): ?><div class="msg"><?= e($msg) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= e($ok) ?></div><?php endif; ?>

    <?php if (!$logged): ?>
        <form method="post" class="card login">
            <h2 style="margin-top:0">Log in</h2>
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="login">
            <input name="username" placeholder="Username" required>
            <input name="password" type="password" placeholder="Password" required>
            <button>Log in</button>
        </form>
    <?php else: ?>
        <form method="post" class="card">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="add">
            <div class="row" style="margin-top:0">
                <input name="title" placeholder="Title" required style="flex:1 1 180px;margin:0">
                <input name="note" placeholder="Note (optional)" style="flex:2 1 260px;margin:0">
                <button>Add item</button>
            </div>
            <label class="check" style="margin:10px 0 0"><input type="checkbox" name="shared" value="1"> Share with everyone (others can see it, only you can change it)</label>
        </form>

        <h2>My items (<?= count($mine) ?>)</h2>
        <?php if (!$mine): ?><p class="id">Nothing here yet. Add your first item above.</p><?php endif; ?>
        <div class="grid">
            <?php foreach ($mine as $it): $iid = (int)$it['id']; ?>
            <div class="card">
                <?php image_block($it); ?>
                <form method="post">
                    <input type="hidden" name="csrf" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?= $iid ?>">
                    <input name="title" value="<?= e($it['title']) ?>" required>
                    <input name="note" value="<?= e($it['note']) ?>" placeholder="Note">
                    <label class="check"><input type="checkbox" name="shared" value="1" <?= $it['shared'] ? 'checked' : '' ?>> Shared with everyone</label><br>
                    <button>Save</button> <span class="badge"><?= $it['shared'] ? 'Shared' : 'Private' ?></span>
                </form>

                <div class="row">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="upload">
                        <input type="hidden" name="id" value="<?= $iid ?>">
                        <label class="btn ghost">
                            <?= $it['has_image'] ? 'Replace' : 'Upload' ?>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" hidden onchange="this.form.submit()">
                        </label>
                    </form>
                    <?php if ($it['has_image']): ?>
                        <a class="btn ghost" href="admin.php?img=<?= $iid ?>&amp;download=1">Download</a>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Delete this item and its image?')">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $iid ?>">
                        <button class="danger">Delete</button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h2>Shared by others (<?= count($others) ?>)</h2>
        <?php if (!$others): ?><p class="id">Nobody has shared anything yet.</p><?php endif; ?>
        <div class="grid">
            <?php foreach ($others as $it): $iid = (int)$it['id']; ?>
            <div class="card">
                <?php image_block($it); ?>
                <b><?= e($it['title']) ?></b>
                <p class="id" style="margin:4px 0"><?= e($it['note']) ?></p>
                <span class="badge">by <?= e($it['owner']) ?></span>
                <?php if ($it['has_image']): ?>
                    <div class="row"><a class="btn ghost" href="admin.php?img=<?= $iid ?>&amp;download=1">Download</a></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <h2>Account</h2>
        <details class="card">
            <summary>Change my password</summary>
            <form method="post" style="margin-top:12px;max-width:340px">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="password">
                <input name="old" type="password" placeholder="Current password" required>
                <input name="new" type="password" placeholder="New password (12+ characters)" minlength="12" required>
                <input name="repeat" type="password" placeholder="Repeat new password" minlength="12" required>
                <button>Change password</button>
            </form>
        </details>
    <?php endif; ?>
</div>
</body>
</html>
