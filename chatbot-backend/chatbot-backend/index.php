<?php
// index.php — Router principal
//
// Com `php -S localhost:8000 index.php`, todas as URLs passam por este arquivo.
// Arquivos estáticos (admin, assets) precisam ser servidos aqui ou com return false.

if (PHP_SAPI === 'cli-server') {
    $rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path    = rawurldecode($rawPath);

    if (str_contains($path, '..')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }

    // Base opcional na URL (ex.: /chatbot-backend/admin)
    if (preg_match('#^/chatbot-backend(/.*)?$#', $path, $m)) {
        $path = $m[1] ?? '/';
        if ($path === '' || $path === '/') {
            $path = '/';
        }
    }

    // /admin e /admin/ → admin/index.html
    if ($path === '/admin' || $path === '/admin/') {
        $adminIndex = __DIR__ . '/admin/index.html';
        if (is_file($adminIndex)) {
            header('Content-Type: text/html; charset=utf-8');
            readfile($adminIndex);
            exit;
        }
    }

    // Arquivo real (HTML, JS, CSS, etc.) — deixa o PHP built-in servir
    $file = __DIR__ . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require_once __DIR__ . '/config/database.php';

// ── CORS ──────────────────────────────────────────────────────────────────
// Reflete a origem em loopback (localhost / 127.0.0.1 / ::1, qualquer porta)
// para o admin funcionar com Live Server, IPv6, etc.
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$corsOrigin    = ALLOWED_ORIGIN;
if ($requestOrigin !== '') {
    if (in_array($requestOrigin, ALLOWED_ORIGINS, true)) {
        $corsOrigin = $requestOrigin;
    } elseif (preg_match(
        '#^https?://(\[::1\]|localhost|127\.0\.0\.1|192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3})(:\d+)?$#i',
        $requestOrigin
    )) {
        $corsOrigin = $requestOrigin;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . $corsOrigin);
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

// Chrome: requisições para localhost a partir de outra origem local (ex.: Live Server)
if (!empty($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_PRIVATE_NETWORK'])) {
    header('Access-Control-Allow-Private-Network: true');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── AUTO SETUP — cria tabelas se não existirem (MySQL / InnoDB) ────────────
function setupTables(): void {
    $db = getDB();

    $db->exec("
        CREATE TABLE IF NOT EXISTS flows (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(255) NOT NULL,
            description TEXT,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS nodes (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            flow_id     INT UNSIGNED NOT NULL,
            node_key    VARCHAR(64) NOT NULL,
            type        VARCHAR(32) NOT NULL,
            content     TEXT,
            caption     VARCHAR(255),
            delay_ms    INT DEFAULT 800,
            pos_x       DOUBLE DEFAULT 0,
            pos_y       DOUBLE DEFAULT 0,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_nodes_flow FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS options (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            node_id     INT UNSIGNED NOT NULL,
            label       VARCHAR(255) NOT NULL,
            next_key    VARCHAR(64) NOT NULL,
            CONSTRAINT fk_options_node FOREIGN KEY (node_id) REFERENCES nodes(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS edges (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            flow_id     INT UNSIGNED NOT NULL,
            from_key    VARCHAR(64) NOT NULL,
            to_key      VARCHAR(64) NOT NULL,
            CONSTRAINT fk_edges_flow FOREIGN KEY (flow_id) REFERENCES flows(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

setupTables();

// ── ROUTER ─────────────────────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = rtrim($uri, '/');
$method = $_SERVER['REQUEST_METHOD'];

$uri = preg_replace('#^/chatbot-backend#', '', $uri);

$routes = [
    ['GET',    '#^/api/flows$#',                'api/flows.php',  'listFlows'],
    ['POST',   '#^/api/flows$#',                'api/flows.php',  'createFlow'],
    ['GET',    '#^/api/flows/(\d+)$#',          'api/flows.php',  'getFlow'],
    ['PUT',    '#^/api/flows/(\d+)$#',          'api/flows.php',  'updateFlow'],
    ['DELETE', '#^/api/flows/(\d+)$#',          'api/flows.php',  'deleteFlow'],

    ['POST',   '#^/api/flows/(\d+)/nodes$#',    'api/nodes.php',  'saveNodes'],
    ['GET',    '#^/api/flows/(\d+)/nodes$#',    'api/nodes.php',  'getNodes'],

    ['GET',    '#^/api/flows/(\d+)/export$#',   'api/export.php', 'exportFlow'],
];

$matched = false;
foreach ($routes as [$rMethod, $pattern, $file, $fn]) {
    if ($method === $rMethod && preg_match($pattern, $uri, $m)) {
        $params = array_slice($m, 1);
        require_once __DIR__ . '/' . $file;
        call_user_func_array($fn, $params);
        $matched = true;
        break;
    }
}

if (!$matched) {
    http_response_code(404);
    echo json_encode(['error' => 'Route not found', 'uri' => $uri]);
}
