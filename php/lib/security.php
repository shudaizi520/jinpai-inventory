<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class HttpException extends RuntimeException
{
    public function __construct(string $message, private readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    $secure = app_config_bool('SESSION_COOKIE_SECURE', false)
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

    session_name((string) app_config('SESSION_NAME', 'inventory_session'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function require_csrf(): void
{
    $expected = $_SESSION['_csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf_token'] ?? '');

    if (!is_string($expected) || !is_string($provided) || $expected === '' || !hash_equals($expected, $provided)) {
        throw new HttpException('请求验证失败，请刷新页面后重试。', 403);
    }
}

function client_ip(array $server, array $trustedProxies): string
{
    $remote = filter_var($server['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP);
    $remote = is_string($remote) ? $remote : '0.0.0.0';

    if (!in_array($remote, $trustedProxies, true)) {
        return $remote;
    }

    $forwarded = explode(',', (string) ($server['HTTP_X_FORWARDED_FOR'] ?? ''));
    $forwarded[] = $remote;
    for ($index = count($forwarded) - 1; $index >= 0; $index--) {
        $candidate = trim($forwarded[$index]);
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        if (!in_array($candidate, $trustedProxies, true)) {
            return $candidate;
        }
    }

    return $remote;
}

function validate_password(string $password): array
{
    $errors = [];
    $length = strlen($password);

    if ($length < 12) {
        $errors[] = '密码至少需要 12 个字符。';
    }
    if ($length > 128) {
        $errors[] = '密码不能超过 128 个字符。';
    }

    $categories = 0;
    $categories += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
    $categories += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
    $categories += preg_match('/[0-9]/', $password) === 1 ? 1 : 0;
    $categories += preg_match('/[^a-zA-Z0-9]/', $password) === 1 ? 1 : 0;
    if ($length >= 12 && $categories < 3) {
        $errors[] = '密码需包含字母、数字或符号中的至少三类。';
    }

    return ['valid' => $errors === [], 'errors' => $errors];
}

function safe_log(Throwable $error): string
{
    $requestId = bin2hex(random_bytes(8));
    $message = preg_replace(
        [
            '/(?i)(password|passwd|pwd|secret|token)\s*[=:]\s*[^\s;,]+/',
            '/mysql:[^\s]+/',
        ],
        ['$1=[redacted]', 'mysql:[redacted]'],
        $error->getMessage()
    );
    error_log(sprintf('[%s] %s: %s in %s:%d', $requestId, $error::class, $message, $error->getFile(), $error->getLine()));
    return $requestId;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function first_character(string $value, string $fallback = '用'): string
{
    return preg_match('/^./us', $value, $match) === 1 ? $match[0] : $fallback;
}
