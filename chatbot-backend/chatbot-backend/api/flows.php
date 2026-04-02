<?php
// api/flows.php — MySQL

function listFlows(): void {
    $db   = getDB();
    $stmt = $db->query("
        SELECT f.*,
               (SELECT COUNT(*) FROM nodes n WHERE n.flow_id = f.id) AS node_count
        FROM flows f
        ORDER BY f.updated_at DESC
    ");
    echo json_encode(['data' => $stmt->fetchAll()]);
}

function createFlow(): void {
    $body = json_decode(file_get_contents('php://input'), true);

    if (empty($body['name'])) {
        http_response_code(422);
        echo json_encode(['error' => 'name is required']);
        return;
    }

    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO flows (name, description) VALUES (:name, :desc)
    ");
    $stmt->execute([
        ':name' => trim($body['name']),
        ':desc' => $body['description'] ?? null,
    ]);

    $id = (int) $db->lastInsertId();
    $stmt = $db->prepare('SELECT * FROM flows WHERE id = :id');
    $stmt->execute([':id' => $id]);

    http_response_code(201);
    echo json_encode(['data' => $stmt->fetch()]);
}

function getFlow(string $id): void {
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM flows WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $flow = $stmt->fetch();

    if (!$flow) {
        http_response_code(404);
        echo json_encode(['error' => 'Flow not found']);
        return;
    }

    $stmt = $db->prepare('SELECT * FROM nodes WHERE flow_id = :fid ORDER BY id');
    $stmt->execute([':fid' => $id]);
    $nodes = $stmt->fetchAll();

    foreach ($nodes as &$node) {
        $s = $db->prepare('SELECT * FROM options WHERE node_id = :nid ORDER BY id');
        $s->execute([':nid' => $node['id']]);
        $node['options'] = $s->fetchAll();
    }

    $stmt = $db->prepare('SELECT * FROM edges WHERE flow_id = :fid');
    $stmt->execute([':fid' => $id]);
    $edges = $stmt->fetchAll();

    echo json_encode(['data' => array_merge($flow, [
        'nodes' => $nodes,
        'edges' => $edges,
    ])]);
}

function updateFlow(string $id): void {
    $body = json_decode(file_get_contents('php://input'), true);
    $db   = getDB();

    $stmt = $db->prepare("
        UPDATE flows SET name = :name, description = :desc, updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([
        ':name' => $body['name'],
        ':desc' => $body['description'] ?? null,
        ':id'   => $id,
    ]);

    $stmt = $db->prepare('SELECT * FROM flows WHERE id = :id');
    $stmt->execute([':id' => $id]);

    echo json_encode(['data' => $stmt->fetch()]);
}

function deleteFlow(string $id): void {
    $db = getDB();
    $db->prepare('DELETE FROM flows WHERE id = :id')->execute([':id' => $id]);
    echo json_encode(['success' => true]);
}
