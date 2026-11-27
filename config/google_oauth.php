<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$secureDirectory = dirname($projectRoot, 2)
    . DIRECTORY_SEPARATOR . 'secure'
    . DIRECTORY_SEPARATOR . 'Scheduling_System';

$envFile = $secureDirectory . DIRECTORY_SEPARATOR . 'mang-lechon.env';
$tokenFile = $secureDirectory . DIRECTORY_SEPARATOR . 'google-token.json';

$autoloadFile = $projectRoot . DIRECTORY_SEPARATOR . 'vendor'
    . DIRECTORY_SEPARATOR . 'autoload.php';

if (!is_file($autoloadFile)) {
    throw new RuntimeException(
        'Composer dependencies are missing. Run composer install in the project folder.'
    );
}

require_once $autoloadFile;

if (!class_exists(\Google\Client::class)) {
    throw new RuntimeException(
        'Google API Client is not installed. Check your Composer dependencies.'
    );
}

if (!class_exists(\Dotenv\Dotenv::class)) {
    throw new RuntimeException(
        'PHP dotenv is not installed. Check your Composer dependencies.'
    );
}

if (!is_file($envFile)) {
    throw new RuntimeException(
        'The secure environment file was not found: ' . $envFile
    );
}

\Dotenv\Dotenv::createImmutable(
    $secureDirectory,
    'mang-lechon.env'
)->safeLoad();

$getEnvironmentValue = static function (string $key): string {
    $value = $_ENV[$key]
        ?? $_SERVER[$key]
        ?? getenv($key);

    return is_string($value) ? trim($value) : '';
};

$clientId = $getEnvironmentValue('GOOGLE_CLIENT_ID');
$clientSecret = $getEnvironmentValue('GOOGLE_CLIENT_SECRET');
$redirectUri = $getEnvironmentValue('GOOGLE_REDIRECT_URI');

if (
    $clientId === ''
    || $clientSecret === ''
    || $redirectUri === ''
) {
    throw new RuntimeException(
        'Missing Google OAuth settings. Check your mang-lechon.env file.'
    );
}

if (
    !filter_var($redirectUri, FILTER_VALIDATE_URL)
    || !str_starts_with($redirectUri, 'http://localhost/')
) {
    throw new RuntimeException(
        'The Google OAuth redirect URI must be a valid localhost URL.'
    );
}

$googleClient = new \Google\Client();

$googleClient->setClientId($clientId);
$googleClient->setClientSecret($clientSecret);
$googleClient->setRedirectUri($redirectUri);

$googleClient->setScopes([
    'https://www.googleapis.com/auth/gmail.send',
]);

$googleClient->setAccessType('offline');
$googleClient->setPrompt('consent');

return [
    'client' => $googleClient,
    'token_file' => __DIR__ . '/../secure/google-token.json',
    'secure_directory' => $secureDirectory,
];