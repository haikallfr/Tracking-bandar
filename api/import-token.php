<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

$data = request_post();
$accessToken = trim((string) ($data['access_token'] ?? ''));
$refreshToken = trim((string) ($data['refresh_token'] ?? ''));
$accessTokenExpiry = trim((string) ($data['access_token_expiry'] ?? ''));
$refreshTokenExpiry = trim((string) ($data['refresh_token_expiry'] ?? ''));
$accessUser = trim((string) ($data['access_user'] ?? ''));

if ($accessToken === '') {
    header('Location: /?import=failed');
    exit;
}

save_setting('stockbit_token', $accessToken);
save_setting('stockbit_refresh_token', $refreshToken);
save_setting('stockbit_token_expiry', $accessTokenExpiry);
save_setting('stockbit_refresh_token_expiry', $refreshTokenExpiry);
save_setting('stockbit_user_access', $accessUser);
save_setting('stockbit_imported_at', gmdate(DATE_ATOM));

header('Location: /?import=success');
exit;
