<?php
// config/database.php — Chatbot Builder (MySQL local via PDO)
// Opcional: require_once __DIR__ . '/../../config/config.php';

// Credenciais MySQL — ajuste ou defina antes de incluir este arquivo
if (!defined('MYSQL_DB_HOST')) {
    define('MYSQL_DB_HOST', getenv('MYSQL_DB_HOST') ?: '127.0.0.1');
    define('MYSQL_DB_PORT', (int) (getenv('MYSQL_DB_PORT') ?: 3306));
    define('MYSQL_DB_NAME', getenv('MYSQL_DB_NAME') ?: 'chatbot_builder');
    define('MYSQL_DB_USER', getenv('MYSQL_DB_USER') ?: 'root');
    define('MYSQL_DB_PASS', getenv('MYSQL_DB_PASS') !== false ? (string) getenv('MYSQL_DB_PASS') : '');
}

// CORS
if (!defined('ALLOWED_ORIGINS')) {
    define('ALLOWED_ORIGINS', [
        'http://localhost',
        'http://localhost:8000',
        'http://localhost:8080',
        'http://127.0.0.1',
        'http://127.0.0.1:8000',
        'http://127.0.0.1:8080',
    ]);
}

if (!defined('ALLOWED_ORIGIN')) {
    define('ALLOWED_ORIGIN', ALLOWED_ORIGINS[0]);
}

define('DB_PREFIX', 'chatbot_');

// ─── Conexão PDO (singleton) ───────────────────────────────────────────
function getDB(): PDO {
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }

    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        http_response_code(500);
        die(json_encode([
            'error' => 'PDO MySQL driver not available. Enable pdo_mysql in php.ini.',
        ]));
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        MYSQL_DB_HOST,
        MYSQL_DB_PORT,
        MYSQL_DB_NAME
    );

    try {
        $pdo = new PDO($dsn, MYSQL_DB_USER, MYSQL_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['error' => 'DB connection failed: ' . $e->getMessage()]));
    }

    return $pdo;
}

// ─── CORS helper ──────────────────────────────────────────────────────
function setCorsHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    $allowed = ALLOWED_ORIGINS;

    if (in_array($origin, $allowed, true) || in_array('*', $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: ' . $allowed[0]);
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Content-Type: application/json; charset=utf-8');
}
