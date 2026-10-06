<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>How the photo vault works</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
    <header class="top">
        <h1>How the photo vault works</h1>
        <nav>
            <a href="admin.php">Open the vault</a>
            <a href="/index.php">Home</a>
        </nav>
    </header>

    <p class="lead">
        A small private database for notes and pictures. It lives on a Raspberry Pi
        instead of somebody else's cloud, and only logged-in people can see what is inside.
    </p>

    <h2>The big picture</h2>
    <div class="flow">
        <div class="card box"><b>Your browser</b><small>You click, type and upload</small></div>
        <div class="arrow"><span>&rarr;</span>normal web</div>
        <div class="card box"><b>This website</b><small>PHP on the laptop. It asks the Pi on your behalf</small></div>
        <div class="arrow"><span>&rarr;</span>encrypted HTTPS</div>
        <div class="card box"><b>Raspberry Pi</b><small>The API, the database and the encrypted vault with the pictures</small></div>
    </div>
    <p>
        Nothing on the Pi's home network is opened to the internet. Only the API is published, behind an
        HTTPS address provided by Tailscale Funnel. You do not need to install anything to use it, but you
        do need a login: without one the API answers "unauthorized" to everything.
    </p>

    <h2>What happens when you upload a photo</h2>
    <ol>
        <li>You pick a file. The browser sends it to this website.</li>
        <li>The website checks that you are logged in, then forwards the file to the Pi over an encrypted HTTPS connection.</li>
        <li>The Pi looks <em>inside</em> the file to make sure it is really a JPG, PNG or WebP (the file name is not trusted).</li>
        <li>The Pi gives the file a random name and stores it in the encrypted vault. The database remembers which item it belongs to.</li>
        <li>To show or download the photo, the website asks the Pi again. The browser never gets a direct address of the Pi.</li>
    </ol>

    <h2>Why it is safe</h2>
    <div class="grid">
        <div class="card">
            <b>Encrypted connection</b>
            <p>The API is published through <span class="tag">Tailscale Funnel</span> with a real HTTPS certificate, so nobody on the way (Wi-Fi, school network) can read or change what is sent. The router at the Pi's home has no open ports.</p>
        </div>
        <div class="card">
            <b>Guessing is blocked</b>
            <p>Because the API is public, too many wrong passwords from one address, or against one account, lock the login for 15 minutes. Passwords must be at least 12 characters.</p>
        </div>
        <div class="card">
            <b>Passwords are never stored</b>
            <p>The Pi keeps only a scrambled version of the password, made with <span class="tag">Argon2id</span>. It cannot be turned back into the password, and it is deliberately slow to compute, so guessing is very expensive.</p>
        </div>
        <div class="card">
            <b>Login keys instead of passwords</b>
            <p>After login you get a long random key valid for 8 hours. The Pi stores only its <span class="tag">SHA-256</span> fingerprint, so a stolen database does not contain usable keys. The key stays on the server and never reaches your browser.</p>
        </div>
        <div class="card">
            <b>Encrypted storage</b>
            <p>The database and pictures sit in a <span class="tag">LUKS</span> encrypted volume (AES-256). If somebody steals the disk, they see only random noise without the passphrase.</p>
        </div>
        <div class="card">
            <b>Careful with uploads</b>
            <p>Only JPG, PNG and WebP, at most 10 MB, stored under random names. A fake "picture" with code inside is rejected.</p>
        </div>
        <div class="card">
            <b>Forms cannot be forged</b>
            <p>Every button carries a hidden one-time code, so another website cannot trick your browser into deleting things (this attack is called CSRF).</p>
        </div>
    </div>

    <h2>Everyone has their own account</h2>
    <p>
        Each person logs in with their own name and password. Every item belongs to the person who created it, and its owner decides who sees it:
    </p>
    <ul>
        <li><b>Private</b>: only you can see it. For anyone else it does not exist, even if they guess its address.</li>
        <li><b>Shared with everyone</b>: all logged-in people can see and download it, but only you can edit or delete it.</li>
    </ul>
    <p>
        To get in, a person needs an account on the Pi, created by hand by its owner. You can change your own password
        in the vault page; doing so logs out your other devices.
    </p>

    <h2>Why Argon2 and not just SHA-256?</h2>
    <p>
        SHA-256 is a great fingerprint, but it is very fast, and speed is exactly what an attacker wants when
        guessing passwords. Argon2id is built to be slow and memory-hungry, so we use it for passwords and keep
        SHA-256 for the places where speed does not matter, like fingerprints of random keys.
    </p>

    <h2>Honest limits</h2>
    <ul>
        <li>After the Pi restarts, the vault must be unlocked once by hand with the passphrase. That is the price of the encryption.</li>
        <li>The API is reachable from the internet, so its safety rests on strong passwords. A weak password is the weakest link, which is why the minimum is 12 characters.</li>
        <li>If the Pi is off, offline, or its vault is locked after a restart, the page says the Pi is unreachable.</li>
        <li>Only the vault is encrypted, not the whole operating system of the Pi. No secrets are stored outside the vault.</li>
        <li>New accounts are created by hand on the Pi (there is no public sign-up, on purpose). There is no "forgot password" button either: if you forget it, ask the owner of the Pi to reset it.</li>
        <li>Shared items can be read by everyone but changed only by their author. There is no admin who can edit other people's items.</li>
    </ul>

    <h2>Small dictionary</h2>
    <details><summary>API</summary>A set of web addresses that a program (here: our website) can call to read or change data. It is the Pi's front door for programs.</details>
    <details><summary>Hash / fingerprint</summary>A one-way scramble. The same input always gives the same result, but you cannot go back from the result to the input.</details>
    <details><summary>Encryption</summary>A two-way scramble. With the right key you get the original back, without it you get noise. A hash is not encryption.</details>
    <details><summary>Token (login key)</summary>A random string that proves you already logged in, so you do not send your password with every click.</details>
    <details><summary>HTTPS</summary>The normal "padlock" connection of the web: everything sent is encrypted between you and the server.</details>
    <details><summary>Tailscale Funnel</summary>A service that gives the Pi a public HTTPS address without opening any port on the home router: the Pi connects outwards to Tailscale, and visitors are passed through to the API.</details>
</div>
</body>
</html>
