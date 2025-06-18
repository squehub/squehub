<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>500 - Server Error</title>
  <link rel="shortcut icon" href="/assets/default/favicon/favicon.ico" type="image/x-icon">
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
      color: #fff;
      flex-direction: column;
      text-align: center;
    }

    .error-code {
      font-size: 8rem;
      font-weight: bold;
      margin: 0;
    }

    .error-message {
      font-size: 2rem;
      margin-bottom: 20px;
    }

    .error-description {
      font-size: 1.2rem;
      margin-bottom: 30px;
      max-width: 500px;
    }

    a {
      padding: 10px 20px;
      background-color: #fff;
      color: #3782ab;
      border: none;
      border-radius: 4px;
      font-weight: bold;
      text-decoration: none;
      transition: background-color 0.3s ease;
    }

    a:hover {
      background-color: #ddd;
    }
  </style>
</head>
<body>

  <h1 class="error-code">500</h1>
  <div class="error-message">Internal Server Error</div>
  <div class="error-description">
    Sorry, something went wrong on our end.<br>
    Please try again later or return to the homepage.
  </div>
  <a href="/">Back to Homepage</a>

</body>
</html>
