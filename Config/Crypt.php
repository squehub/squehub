<?php

declare(strict_types=1);

/** Keys are explicit Base64 strings; ordinary Application boot never decodes them. */
/** @var \App\Foundation\Environment $environment */
$current = $environment->get('CRYPT_CURRENT_KEY_ID', 'primary');
$previous = $environment->get('CRYPT_PREVIOUS_KEY_ID', 'previous');
$previousKey = $environment->get('APP_PREVIOUS_KEY', '');
$keys = [$current => $environment->get('APP_KEY', '')];
if ($previousKey !== '') {
    if ($previous === $current) {
        throw new \App\Cryptography\CryptConfigurationException('Current and previous cryptographic key IDs must differ.');
    }
    $keys[$previous] = $previousKey;
}

return [
    'driver' => $environment->get('CRYPT_DRIVER', 'auto'),
    'current' => $current,
    'keys' => $keys,
    'max_plaintext_bytes' => 1048576,
];
