<?php
// app/core/helper.php

/**
 * 🌍 Global Helper Functions - Squehub Framework
 * ---------------------------------------------
 * Provides reusable, lightweight utility functions for:
 * - Session & CSRF
 * - Flash messages
 * - Routing & Redirects
 * - URL generation
 * - Data masking
 * - Price formatting
 * - String slugification
 */

// ✅ Start session only if not already started
function startSessionIfNotStarted()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

// ✅ Generate a route URL with optional parameters
function route($name, $params = [])
{
    global $router;
    return $router->route($name, $params);
}

// ✅ Generate or get CSRF token
function csrf_token()
{
    startSessionIfNotStarted();

    if (!isset($_SESSION['_token'])) {
        $_SESSION['_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_token'];
}

// ✅ Validate CSRF token from POST request (dies on failure)
function validateCsrfToken()
{
    startSessionIfNotStarted();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['_token']) || $_POST['_token'] !== $_SESSION['_token']) {
            die('❌ Invalid CSRF token.');
        }
    }
}

// ✅ CSRF validation (returns boolean)
function CsrfTokenValidator(): bool
{
    startSessionIfNotStarted();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        return isset($_POST['_token']) && $_POST['_token'] === $_SESSION['_token'];
    }

    return true;
}

// ✅ Check if request method is POST
function is_post()
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

// ✅ Mask email - show only first 2 letters
function maskEmail(string $email): string
{
    [$name, $domain] = explode('@', $email);
    $masked = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 0));
    return $masked . '@' . ($domain ?? '');
}

// ✅ Mask phone number (leave last 2 digits visible)
function maskPhoneNumber(string $phone): string
{
    $len = strlen($phone);
    return $len <= 2 ? str_repeat('*', $len) : str_repeat('*', $len - 2) . substr($phone, -2);
}

// ✅ Convert string to slug (URL-friendly)
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = preg_replace('~-+~', '-', trim($text, '-'));
    return strtolower($text ?: 'n-a');
}

// ✅ Set or get flash messages in session
function flash(string $key, ?string $message = null)
{
    startSessionIfNotStarted();

    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
    } else {
        $value = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}

// ✅ Format number as price (optionally with currency)
function priceFormatter(float $amount, string $currency = '', bool $symbolBefore = true, int $decimals = 2): string
{
    $formatted = number_format($amount, $decimals);
    return $currency !== ''
        ? ($symbolBefore ? $currency . $formatted : $formatted . ' ' . $currency)
        : $formatted;
}

// ✅ Build full URL with optional path segments
function url(...$pathSegments)
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = implode('/', array_map(fn($s) => trim($s, '/'), $pathSegments));
    return rtrim($protocol . '://' . $host . '/' . $path, '/');
}

// ✅ Redirect helper with `to()` and `back()` methods
function redirect()
{
    return new class {
        public function to(string $url)
        {
            header("Location: $url");
            exit;
        }

        public function back()
        {
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header("Location: $referer");
            exit;
        }
    };
}
