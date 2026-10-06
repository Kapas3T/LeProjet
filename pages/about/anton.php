<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>About Anton</title>
    <link rel="stylesheet" href="../style.css">
    <style>
        .hero { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .avatar {
            width: 84px; height: 84px; border-radius: 50%; flex: none;
            display: flex; align-items: center; justify-content: center;
            background: var(--accent); color: var(--accent-text);
            font-size: 1.9rem; font-weight: 700; letter-spacing: 1px;
        }
        .hero h2 { margin: 0; font-size: 1.7rem; }
        .hero p { margin: 2px 0 0; color: var(--muted); }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: 6px 18px; margin: 18px 0 0; }
        dt { color: var(--muted); }
        dd { margin: 0; }
        .weather { display: flex; align-items: center; gap: 16px; }
        .weather .icon { font-size: 3rem; line-height: 1; }
        .weather .temp { font-size: 2rem; font-weight: 700; line-height: 1.1; }
        .links { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .links a.card { text-decoration: none; color: inherit; display: block; }
        .links a.card:hover { border-color: var(--accent); }
        .links b { color: var(--accent); }
        .links p { margin: 4px 0 0; color: var(--muted); font-size: 0.92rem; }
    </style>
</head>
<body>
<div class="wrap">
    <header class="top">
        <h1>About Anton</h1>
        <nav><a href="/index.php">&larr; Back to Les Apprenties</a></nav>
    </header>

    <section class="card">
        <div class="hero">
            <div class="avatar" aria-hidden="true">AT</div>
            <div>
                <h2>Anton Tkachuk</h2>
                <p>One of Les Apprenties</p>
            </div>
        </div>
        <dl>
            <dt>Born</dt><dd>06.08.2008</dd>
            <dt>Email</dt><dd><a href="mailto:anton.tkachuk@epfl.ch">anton.tkachuk@epfl.ch</a></dd>
        </dl>
    </section>

    <h2>Right now in Lausanne</h2>
    <section class="card weather">
        <div class="icon" id="w-icon">&#9728;&#65039;</div>
        <div>
            <div class="temp" id="w-temp">&hellip;</div>
            <div class="id" id="w-desc">Loading the weather</div>
        </div>
    </section>

    <h2>My project</h2>
    <div class="links">
        <a class="card" href="/pages/admin.php">
            <b>Photo vault &rarr;</b>
            <p>A private place for notes and pictures, stored on my Raspberry Pi. Log in with your own account.</p>
        </a>
        <a class="card" href="/pages/how-it-works.php">
            <b>How it works &rarr;</b>
            <p>The vault explained in plain words: where the data lives and why it is safe.</p>
        </a>
    </div>
</div>

<script>
    // Open-Meteo weather codes -> [icon, text]
    const CODES = {
        0: ["☀️", "Clear sky"], 1: ["🌤️", "Mostly clear"], 2: ["⛅", "Partly cloudy"], 3: ["☁️", "Overcast"],
        45: ["🌫️", "Fog"], 48: ["🌫️", "Freezing fog"],
        51: ["🌦️", "Light drizzle"], 53: ["🌦️", "Drizzle"], 55: ["🌦️", "Heavy drizzle"],
        61: ["🌧️", "Light rain"], 63: ["🌧️", "Rain"], 65: ["🌧️", "Heavy rain"],
        71: ["🌨️", "Light snow"], 73: ["🌨️", "Snow"], 75: ["🌨️", "Heavy snow"],
        80: ["🌦️", "Rain showers"], 81: ["🌦️", "Rain showers"], 82: ["⛈️", "Violent showers"],
        95: ["⛈️", "Thunderstorm"], 96: ["⛈️", "Thunderstorm with hail"], 99: ["⛈️", "Thunderstorm with hail"],
    };
    const url = "https://api.open-meteo.com/v1/forecast?latitude=46.52&longitude=6.63"
              + "&current=temperature_2m,weather_code,wind_speed_10m";
    fetch(url)
        .then(r => r.json())
        .then(d => {
            const [icon, text] = CODES[d.current.weather_code] || ["🌡️", "Weather"];
            document.getElementById("w-icon").textContent = icon;
            document.getElementById("w-temp").textContent = Math.round(d.current.temperature_2m) + " °C";
            document.getElementById("w-desc").textContent = text + " · wind " + Math.round(d.current.wind_speed_10m) + " km/h";
        })
        .catch(() => {
            document.getElementById("w-temp").textContent = "–";
            document.getElementById("w-desc").textContent = "Weather unavailable";
        });
</script>
</body>
</html>
