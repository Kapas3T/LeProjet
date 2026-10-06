<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>About Victor</title>
    </head>

    <header>
        <h1>About Victor</h1>
        <nav>
            <a href="https://portfolio.kapas3t.com" target="_blank">LePortfolio</a> |
            <a href="/LeProjet/pages/about/">Go back</a>
                
        </nav>
    </header>
    <body>
        <section>
            <article>
                <div id="interactiveDiv"> 
                    <p>Born in 2008</p>
                </div>
                <div id="interactiveDiv">
                    <p>Lives in Switzerland</p>
                </div>
                <div id="interactiveDiv">
                    <p>Victor is a passionate individual with a keen interest in technology and innovation.</p>
                </div>
                <div id="interactiveDiv">
                    <p>He enjoys exploring new ideas and is always eager to learn and grow.</p>
                </div>
                <div id="interactiveDiv"> 
                    <p>Weather in Lausanne at <span id="elevation">...</span>: <span id="weather">...</span></p>
                    <script>
                        fetch("https://api.open-meteo.com/v1/forecast?latitude=46.52&longitude=6.63&current=temperature_2m")
                            .then(r => r.json())
                            .then((data) => {
                                document.getElementById("elevation").textContent = data.elevation + " m";
                                document.getElementById("weather").textContent = data.current.temperature_2m + " °C";
                            });
                    </script>
                </div>

            </article>
        </section>
        <div id="media">
            <iframe src="https://www.youtube.com/embed/Mdq6sQPKYqQ" id="video"></iframe>
            <audio controls id="audio">
                <source src="../../src/vicaudio1.wav" type="audio/wav">
                Your browser does not support the audio element.
            </audio>
            <image src="../../src/victorgif.gif" id="giphy"></image>
        </div>
        <footer>
            <div>
                <p> A foot in the footer</p>
                <img src="../../src/foot.jpg" alt="Italian Trulli" height="100" width="100">
            </div>
        </footer>
    </body>

    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
        }

        header {
            background-color: #f2f2f2;
            padding: 20px;
            text-align: center;
        }

        section {
            padding: 20px;
            text-align: center;
        }

        #interactiveDiv {
            transition: background-color 0.3s ease, font-size 0.3s ease;
        }

        #interactiveDiv:hover {
            background-color: #faf8f8;
            font-size: 1.1em;
        }

        footer {
            background-color: #f2f2f2;
            padding: 10px;
            text-align: center;
        }

        iframe {
            display: block;
            border-style: none;
            margin: 0 auto;
            width: 100%;
            max-width: 640px;
            aspect-ratio: 16 / 9;
        }
        #media {
            text-align: center;
            margin: auto;
            border:1px solid #ccc;
        }

        #audio {
            display: block;
            margin: 20px auto;
        }
        
        #giphy {
            display: block;
            margin: 20px auto;
        }
        
        #video {
            display: block;
            margin: 20px auto;
        }
        p {
            margin: 0px 0;
            padding: 10px 0;
        }
    </style>

</html> 