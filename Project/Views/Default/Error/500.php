<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>500 - Server Error</title>
  <link rel="icon" href="<?= htmlspecialchars($squehubErrorFaviconUrl ?? '/assets/default/favicon/squehub-icon.png', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" type="image/png">
  <style>
    body {
      margin: 0;
      padding: 0;
      background-color: #3782ab;
      font-family: Arial, sans-serif;
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      box-sizing: border-box;
      padding: 24px;
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

    .error-debug {
      box-sizing: border-box;
      width: min(100%, 800px);
      margin-top: 24px;
      padding: 16px;
      border: 1px solid rgba(255, 255, 255, 0.7);
      border-radius: 4px;
      text-align: left;
      overflow-wrap: anywhere;
    }

    .error-debug summary { cursor: pointer; }
    .error-debug pre { white-space: pre-wrap; overflow-wrap: anywhere; }
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

  <?php if (isset($squehubErrorDebug) && is_array($squehubErrorDebug)): ?>
  <details class="error-debug">
    <summary>Development diagnostics</summary>
    <?php foreach (['exception' => 'Exception', 'message' => 'Message', 'file' => 'File', 'line' => 'Line'] as $key => $label): ?>
      <?php if (array_key_exists($key, $squehubErrorDebug)): ?>
      <p><strong><?= $label ?>:</strong> <?= htmlspecialchars((string) $squehubErrorDebug[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if (isset($squehubErrorDebug['trace'])): ?>
    <pre><?= htmlspecialchars((string) $squehubErrorDebug['trace'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></pre>
    <?php endif; ?>
  </details>
  <?php endif; ?>

</body>
</html>
