<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to Squehub</title>
    <style>
        body {
            margin: 0;
            padding: 0;
            background-color: #3782ab;
            font-family: Arial, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            color: #000;
        }

        .welcome-container {
            text-align: center;
        }

        .welcome-logo img {
            max-width: 100%;
            width: 400px;
            height: auto;
            animation: bounce 2s infinite;
        }

        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-25px);
            }
        }

        h1 {
            margin-top: 20px;
            font-size: 2.5em;
        }

        h4, p {
            margin: 10px 0;
        }

        a {
            color: #ffd700;
            text-decoration: none;
            font-weight: bold;
        }

        a:hover {
            text-decoration: underline;
            color: #000;
        }

        hr {
            margin: 25px 0;
            border: 1px solid #ffffff50;
        }
    </style>
</head>
<body>

<main class="welcome-container">
    <div class="welcome-logo">
        <img src="/assets/img/logo-icon.png" alt="Squehub Framework Logo">
    </div>
    <h1>Welcome to Squehub Framework</h1>
    <h4>Do you know that the month of @month is a great month for you?</h4>
    <p>@year is that breakthrough year for you!</p>
    <hr>
    <h4>For more info about Squehub, <a href="https://www.squehub.com/" target="_blank">visit to learn more</a>.</h4>
</main>

</body>
</html>
