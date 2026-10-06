import getpass
import hashlib
import os
import secrets
import sqlite3
import sys
import time
import uuid
from functools import wraps

from argon2 import PasswordHasher
from flask import Flask, abort, g, jsonify, request, send_file

# Data (DB + images) lives in DATA_DIR, which must be the mounted encrypted volume.
DATA_DIR = os.environ.get("DATA_DIR")
DEV = os.environ.get("DEV") == "1"
if not DATA_DIR or not (DEV or os.path.ismount(DATA_DIR)):
    sys.exit("DATA_DIR must be set and be a mounted (unlocked) volume. Use DEV=1 for local testing.")

IMG_DIR = os.path.join(DATA_DIR, "images")
DB_PATH = os.path.join(DATA_DIR, "app.db")
os.makedirs(IMG_DIR, exist_ok=True)

SESSION_TTL = 8 * 3600
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
        CREATE TABLE IF NOT EXISTS sessions (token_hash TEXT PRIMARY KEY, expires REAL NOT NULL);
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            note TEXT NOT NULL DEFAULT '',
            image TEXT
        );
        """
    )


def sha256(s):
    return hashlib.sha256(s.encode()).hexdigest()


def require_auth(f):
    @wraps(f)
    def wrapper(*args, **kwargs):
        auth = request.headers.get("Authorization", "")
        if not auth.startswith("Bearer "):
            abort(401)
        row = db().execute(
            "SELECT 1 FROM sessions WHERE token_hash=? AND expires>?",
            (sha256(auth[7:]), time.time()),
        ).fetchone()
        if not row:
            abort(401)
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
    row = db().execute("SELECT pw_hash FROM users WHERE username=?", (data.get("username", ""),)).fetchone()
    try:
        ph.verify(row["pw_hash"] if row else DUMMY_HASH, data.get("password", ""))
        ok = row is not None
    except Exception:
        ok = False
    if not ok:
        time.sleep(1)  # slows down password guessing
        abort(401)

    token = secrets.token_urlsafe(32)
    db().execute("DELETE FROM sessions WHERE expires<?", (time.time(),))
    db().execute("INSERT INTO sessions VALUES (?, ?)", (sha256(token), time.time() + SESSION_TTL))
    db().commit()
    return jsonify(token=token)  # only the SHA-256 of the token is stored


@app.get("/items")
@require_auth
def list_items():
    rows = db().execute("SELECT id, title, note, image IS NOT NULL AS has_image FROM items ORDER BY id").fetchall()
    return jsonify([dict(r) for r in rows])


@app.post("/items")
@require_auth
def create_item():
    data = request.get_json(silent=True) or {}
    title = str(data.get("title", "")).strip()
    if not title:
        return jsonify(error="title required"), 400
    cur = db().execute("INSERT INTO items(title, note) VALUES (?, ?)", (title, str(data.get("note", ""))))
    db().commit()
    return jsonify(id=cur.lastrowid), 201


@app.put("/items/<int:item_id>")
@require_auth
def update_item(item_id):
    data = request.get_json(silent=True) or {}
    title = str(data.get("title", "")).strip()
    if not title:
        return jsonify(error="title required"), 400
    cur = db().execute("UPDATE items SET title=?, note=? WHERE id=?", (title, str(data.get("note", "")), item_id))
    db().commit()
    if not cur.rowcount:
        abort(404)
    return jsonify(ok=True)


@app.delete("/items/<int:item_id>")
@require_auth
def delete_item(item_id):
    row = db().execute("SELECT image FROM items WHERE id=?", (item_id,)).fetchone()
    if not row:
        abort(404)
    remove_image(row["image"])
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


def remove_image(name):
    if name:
        try:
            os.remove(os.path.join(IMG_DIR, name))
        except FileNotFoundError:
            pass


@app.post("/items/<int:item_id>/image")
@require_auth
def upload_image(item_id):
    row = db().execute("SELECT image FROM items WHERE id=?", (item_id,)).fetchone()
    if not row:
        abort(404)
    f = request.files.get("image")
    if not f:
        return jsonify(error="image field required"), 400
    data = f.read()
    ext = sniff(data[:12])
    if not ext:
        return jsonify(error="only jpg, png, webp allowed"), 400

    name = uuid.uuid4().hex + "." + ext  # random name, no user input in the path
    with open(os.path.join(IMG_DIR, name), "wb") as out:
        out.write(data)
    remove_image(row["image"])
    db().execute("UPDATE items SET image=? WHERE id=?", (name, item_id))
    db().commit()
    return jsonify(ok=True)


@app.get("/items/<int:item_id>/image")
@require_auth
def get_image(item_id):
    row = db().execute("SELECT image FROM items WHERE id=?", (item_id,)).fetchone()
    if not row or not row["image"]:
        abort(404)
    return send_file(os.path.join(IMG_DIR, row["image"]))


def add_user(username):
    pw = getpass.getpass("Password: ")
    if len(pw) < 12:
        sys.exit("Password must be at least 12 characters.")
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

        # HOST should be the Pi's Tailscale IP so the API is not reachable from the normal LAN.
        serve(app, host=os.environ.get("HOST", "127.0.0.1"), port=int(os.environ.get("PORT", "8000")))
