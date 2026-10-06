<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>About Anton</title>
</head>
<body>
    <h1>Anton Tkachuk</h1>
    <p>Born: 06.08.2008</p>
    <p>Email: <a href="mailto:anton.tkachuk@epfl.ch">anton.tkachuk@epfl.ch</a></p>
    <p>Weather in Lausanne: <span id="weather">...</span></p>
    <p><a href="/pages/admin.php">Photo vault</a> &middot; <a href="/pages/how-it-works.php">How it works</a></p>
    <a href="/index.php">Back</a>
    <script>
        fetch("https://api.open-meteo.com/v1/forecast?latitude=46.52&longitude=6.63&current=temperature_2m")
            .then(r => r.json())
            .then(d => document.getElementById("weather").textContent = d.current.temperature_2m + " °C");
    </script>
</body>
</html>
