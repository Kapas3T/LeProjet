import getpass
import hashlib
import io
import os
import secrets
import sqlite3
import sys
import threading
import time
import uuid
from functools import wraps

from argon2 import PasswordHasher
from flask import Flask, abort, g, jsonify, request, send_file
from PIL import Image, ImageOps

Image.MAX_IMAGE_PIXELS = 40_000_000  # refuse "decompression bomb" images

# Data (DB + images) lives in DATA_DIR, which must be the mounted encrypted volume.
DATA_DIR = os.environ.get("DATA_DIR")
DEV = os.environ.get("DEV") == "1"
if not DATA_DIR or not (DEV or os.path.ismount(DATA_DIR)):
    sys.exit("DATA_DIR must be set and be a mounted (unlocked) volume. Use DEV=1 for local testing.")

IMG_DIR = os.path.join(DATA_DIR, "images")
DB_PATH = os.path.join(DATA_DIR, "app.db")
os.makedirs(IMG_DIR, exist_ok=True)

SESSION_TTL = 8 * 3600
MIN_PASSWORD = 12
THUMB_SIZE = (400, 400)
MAX_ITEMS_PER_USER = 200
MAX_BYTES_PER_USER = 300 * 1024 * 1024
ph = PasswordHasher()  # Argon2id
DUMMY_HASH = ph.hash("dummy")  # makes login timing equal for unknown users

app = Flask(__name__)
app.config["MAX_CONTENT_LENGTH"] = 10 * 1024 * 1024  # 10 MB per upload


def db():
    if "db" not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
    return g.db


@app.teardown_appcontext
def close_db(_):
    conn = g.pop("db", None)
    if conn:
        conn.close()


with sqlite3.connect(DB_PATH) as c:
    c.executescript(
        """
        CREATE TABLE IF NOT EXISTS users (username TEXT PRIMARY KEY, pw_hash TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            image TEXT,
            owner TEXT NOT NULL DEFAULT '',
            shared INTEGER NOT NULL DEFAULT 0
        );
        """
    )
    # Upgrade an older database: add ownership, give old items to the first user.
    if "owner" not in [r[1] for r in c.execute("PRAGMA table_info(items)")]:
        c.execute("ALTER TABLE items ADD COLUMN owner TEXT NOT NULL DEFAULT ''")
        c.execute("ALTER TABLE items ADD COLUMN shared INTEGER NOT NULL DEFAULT 0")
        c.execute("UPDATE items SET owner=COALESCE((SELECT username FROM users ORDER BY rowid LIMIT 1), '')")
    for column, ddl in (("thumb", "TEXT"), ("bytes", "INTEGER NOT NULL DEFAULT 0")):
        if column not in [r[1] for r in c.execute("PRAGMA table_info(items)")]:
            c.execute(f"ALTER TABLE items ADD COLUMN {column} {ddl}")
    for item_id, image in c.execute("SELECT id, image FROM items WHERE image IS NOT NULL AND bytes=0").fetchall():
        try:
            c.execute("UPDATE items SET bytes=? WHERE id=?", (os.path.getsize(os.path.join(IMG_DIR, image)), item_id))
        except OSError:
            pass
    # Sessions are short-lived, so an old table without usernames is simply recreated.
    if "username" not in [r[1] for r in c.execute("PRAGMA table_info(sessions)")]:
        c.execute("DROP TABLE IF EXISTS sessions")
    c.execute("CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, username TEXT NOT NULL, expires REAL NOT NULL)")


# Brute-force protection: too many wrong passwords from one address or for one account -> HTTP 429.
FAILS = {}
FAIL_LOCK = threading.Lock()
FAIL_WINDOW = 15 * 60
FAIL_LIMITS = {"ip": 10, "user": 20}


def client_ip():
    # Behind the Funnel proxy the last X-Forwarded-For entry is the address the proxy saw.
    forwarded = request.headers.get("X-Forwarded-For", "")
    return forwarded.split(",")[-1].strip() if forwarded else request.remote_addr


def attempt_keys(username):
    return [("ip", client_ip()), ("user", username)]


def locked_out(keys):
    now = time.time()
    with FAIL_LOCK:
        for key in keys:
            recent = [t for t in FAILS.get(key, []) if now - t < FAIL_WINDOW]
            if recent:
                FAILS[key] = recent
            else:
                FAILS.pop(key, None)
            if len(recent) >= FAIL_LIMITS[key[0]]:
                return True
    return False


def record_failure(keys):
    with FAIL_LOCK:
        for key in keys:
            FAILS.setdefault(key, []).append(time.time())


def sha256(s):
    return hashlib.sha256(s.encode()).hexdigest()


def new_session(username):
    token = secrets.token_urlsafe(32)
    db().execute("DELETE FROM sessions WHERE expires<?", (time.time(),))
    db().execute("INSERT INTO sessions VALUES (?, ?, ?)", (sha256(token), username, time.time() + SESSION_TTL))
    db().commit()
    return token  # only the SHA-256 of the token is stored


def require_auth(f):
    @wraps(f)
    def wrapper(*args, **kwargs):
        auth = request.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            abort(401)
        row = db().execute(
            "SELECT username FROM sessions WHERE token_hash=? AND expires>?",
            (sha256(auth[7:]), time.time()),
        ).fetchone()
        if not row:
            abort(401)
        g.user = row["username"]
        return f(*args, **kwargs)

    return wrapper


@app.after_request
def secure_headers(resp):
    resp.headers["X-Content-Type-Options"] = "nosniff"
    resp.headers["Cache-Control"] = "no-store"
    return resp


@app.errorhandler(401)
def unauthorized(_):
    return jsonify(error="unauthorized"), 401


@app.errorhandler(404)
def not_found(_):
    return jsonify(error="not found"), 404


@app.errorhandler(413)
def too_large(_):
    return jsonify(error="file too large"), 413


@app.post("/login")
def login():
    data = request.get_json(silent=True) or {}
    username = str(data.get("username", ""))
    keys = attempt_keys(username)
    if locked_out(keys):
        return jsonify(error="too many attempts, try again in 15 minutes"), 429
    row = db().execute("SELECT pw_hash FROM users WHERE username=?", (username,)).fetchone()
    try:
        ph.verify(row["pw_hash"] if row else DUMMY_HASH, str(data.get("password", "")))
        ok = row is not None
    except Exception:
        ok = False
    if not ok:
        record_failure(keys)
        time.sleep(1)  # slows down password guessing
        abort(401)
    return jsonify(token=new_session(username), username=username)


@app.post("/password")
@require_auth
def change_password():
    data = request.get_json(silent=True) or {}
    new = str(data.get("new", ""))
    if len(new) < MIN_PASSWORD:
        return jsonify(error=f"password must be at least {MIN_PASSWORD} characters"), 400
    keys = attempt_keys(g.user)
    if locked_out(keys):
        return jsonify(error="too many attempts, try again in 15 minutes"), 429
    row = db().execute("SELECT pw_hash FROM users WHERE username=?", (g.user,)).fetchone()
    try:
        ph.verify(row["pw_hash"], str(data.get("old", "")))
    except Exception:
        record_failure(keys)
        time.sleep(1)
        return jsonify(error="wrong current password"), 403
    db().execute("UPDATE users SET pw_hash=? WHERE username=?", (ph.hash(new), g.user))
    db().execute("DELETE FROM sessions WHERE username=?", (g.user,))  # log out every other device
    db().commit()
    return jsonify(token=new_session(g.user))


def visible_item(item_id):
    """An item I own or one that is shared with everyone."""
    row = db().execute("SELECT * FROM items WHERE id=? AND (owner=? OR shared=1)", (item_id, g.user)).fetchone()
    if not row:
        abort(404)
    return row


def own_item(item_id):
    """Only my own item; others get 404 so private items do not even reveal they exist."""
    row = db().execute("SELECT * FROM items WHERE id=? AND owner=?", (item_id, g.user)).fetchone()
    if not row:
        abort(404)
    return row


@app.get("/items")
@require_auth
def list_items():
    rows = db().execute(
        "SELECT id, title, note, owner, shared, image IS NOT NULL AS has_image, substr(image, 1, 10) AS v FROM items "
        "WHERE owner=? OR shared=1 ORDER BY id",
        (g.user,),
    ).fetchall()
    out = []
    for r in rows:
        item = dict(r)
        item["shared"] = bool(item["shared"])
        item["mine"] = item["owner"] == g.user
        out.append(item)
    return jsonify(out)


def read_item_fields():
    data = request.get_json(silent=True) or {}
    title = str(data.get("title", "")).strip()
    if not title:
        abort(400, description="title required")
    return title, str(data.get("note", "")), 1 if data.get("shared") else 0


@app.errorhandler(400)
def bad_request(e):
    return jsonify(error=e.description), 400


@app.post("/items")
@require_auth
def create_item():
    title, note, shared = read_item_fields()
    count = db().execute("SELECT COUNT(*) FROM items WHERE owner=?", (g.user,)).fetchone()[0]
    if count >= MAX_ITEMS_PER_USER:
        return jsonify(error=f"limit of {MAX_ITEMS_PER_USER} items reached"), 400
    cur = db().execute(
        "INSERT INTO items(title, note, owner, shared) VALUES (?, ?, ?, ?)", (title, note, g.user, shared)
    )
    db().commit()
    return jsonify(id=cur.lastrowid), 201


@app.put("/items/<int:item_id>")
@require_auth
def update_item(item_id):
    own_item(item_id)
    title, note, shared = read_item_fields()
    db().execute("UPDATE items SET title=?, note=?, shared=? WHERE id=?", (title, note, shared, item_id))
    db().commit()
    return jsonify(ok=True)


@app.delete("/items/<int:item_id>")
@require_auth
def delete_item(item_id):
    row = own_item(item_id)
    remove_files(row["image"], row["thumb"])
    db().execute("DELETE FROM items WHERE id=?", (item_id,))
    db().commit()
    return jsonify(ok=True)


def sniff(head):
    """Detect image type by file content, never by the client-supplied name."""
    if head.startswith(b"\xff\xd8\xff"):
        return "jpg"
    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return "png"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return "webp"
    return None


def remove_files(*names):
    for name in names:
        if name:
            try:
                os.remove(os.path.join(IMG_DIR, name))
            except FileNotFoundError:
                pass


def make_thumb(source):
    """Small JPEG copy (also strips EXIF data such as GPS position). `source` is a path or file object."""
    with Image.open(source) as im:
        im = ImageOps.exif_transpose(im)
        im.thumbnail(THUMB_SIZE)
        im = im.convert("RGBA")
        flat = Image.new("RGBA", im.size, (255, 255, 255, 255))  # transparent areas become white
        im = Image.alpha_composite(flat, im).convert("RGB")
        name = uuid.uuid4().hex + "_t.jpg"
        im.save(os.path.join(IMG_DIR, name), "JPEG", quality=80, optimize=True)
        return name


@app.post("/items/<int:item_id>/image")
@require_auth
def upload_image(item_id):
    row = own_item(item_id)
    f = request.files.get("image")
    if not f:
        return jsonify(error="image field required"), 400
    data = f.read()
    ext = sniff(data[:12])
    if not ext:
        return jsonify(error="only jpg, png, webp allowed"), 400
    used = db().execute(
        "SELECT COALESCE(SUM(bytes), 0) FROM items WHERE owner=? AND id!=?", (g.user, item_id)
    ).fetchone()[0]
    if used + len(data) > MAX_BYTES_PER_USER:
        return jsonify(error=f"storage limit of {MAX_BYTES_PER_USER // 1024 // 1024} MB reached"), 400
    try:
        thumb = make_thumb(io.BytesIO(data))  # also proves the file really is a readable image
    except Exception:
        return jsonify(error="could not read this image"), 400

    name = uuid.uuid4().hex + "." + ext  # random name, no user input in the path
    with open(os.path.join(IMG_DIR, name), "wb") as out:
        out.write(data)
    remove_files(row["image"], row["thumb"])
    db().execute("UPDATE items SET image=?, thumb=?, bytes=? WHERE id=?", (name, thumb, len(data), item_id))
    db().commit()
    return jsonify(ok=True)


@app.get("/items/<int:item_id>/thumb")
@require_auth
def get_thumb(item_id):
    row = visible_item(item_id)
    if not row["image"]:
        abort(404)
    name = row["thumb"]
    if not name or not os.path.exists(os.path.join(IMG_DIR, name)):
        try:  # images uploaded before thumbnails existed get theirs on first request
            name = make_thumb(os.path.join(IMG_DIR, row["image"]))
        except Exception:
            abort(404)
        db().execute("UPDATE items SET thumb=? WHERE id=?", (name, item_id))
        db().commit()
    return send_file(os.path.join(IMG_DIR, name), mimetype="image/jpeg")


@app.get("/items/<int:item_id>/image")
@require_auth
def get_image(item_id):
    row = visible_item(item_id)
    if not row["image"]:
        abort(404)
    return send_file(os.path.join(IMG_DIR, row["image"]))


def add_user(username):
    pw = getpass.getpass("Password: ")
    if len(pw) < MIN_PASSWORD:
        sys.exit(f"Password must be at least {MIN_PASSWORD} characters.")
    if pw != getpass.getpass("Repeat: "):
        sys.exit("Passwords do not match.")
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("INSERT OR REPLACE INTO users VALUES (?, ?)", (username, ph.hash(pw)))
    print("User saved.")


if __name__ == "__main__":
    if len(sys.argv) == 3 and sys.argv[1] == "adduser":
        add_user(sys.argv[2])
    else:
        from waitress import serve

        # Listens on localhost only; the Tailscale Funnel proxy forwards public HTTPS traffic to it.
        serve(
            app,
            host=os.environ.get("HOST", "127.0.0.1"),
            port=int(os.environ.get("PORT", "8000")),
            threads=8,
            max_request_body_size=11 * 1024 * 1024,
        )
