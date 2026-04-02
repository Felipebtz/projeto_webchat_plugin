<?php
// api/nodes.php — MySQL

function saveNodes(string $flowId): void {
    $body = json_decode(file_get_contents('php://input'), true);

    if (!isset($body['nodes'])) {
        http_response_code(422);
        echo json_encode(['error' => 'nodes array is required']);
        return;
    }

    $db = getDB();

    try {
        $db->beginTransaction();

        $db->prepare('DELETE FROM edges WHERE flow_id = :fid')->execute([':fid' => $flowId]);

        $db->prepare('
            DELETE o FROM options o
            INNER JOIN nodes n ON n.id = o.node_id
            WHERE n.flow_id = :fid
        ')->execute([':fid' => $flowId]);

        $db->prepare('DELETE FROM nodes WHERE flow_id = :fid')->execute([':fid' => $flowId]);

        $db->prepare('UPDATE flows SET updated_at = CURRENT_TIMESTAMP WHERE id = :id')
           ->execute([':id' => $flowId]);

        $keyToId = [];
        $stmtNode = $db->prepare("
            INSERT INTO nodes (flow_id, node_key, type, content, caption, delay_ms, pos_x, pos_y)
            VALUES (:fid, :key, :type, :content, :caption, :delay, :px, :py)
        ");

        foreach ($body['nodes'] as $node) {
            $stmtNode->execute([
                ':fid'     => $flowId,
                ':key'     => $node['key'],
                ':type'    => $node['type']    ?? 'text',
                ':content' => $node['content'] ?? null,
                ':caption' => $node['caption'] ?? null,
                ':delay'   => $node['delay']   ?? 800,
                ':px'      => $node['pos_x']   ?? 0,
                ':py'      => $node['pos_y']   ?? 0,
            ]);
            $keyToId[$node['key']] = (int) $db->lastInsertId();
        }

        $stmtOpt = $db->prepare('
            INSERT INTO options (node_id, label, next_key)
            VALUES (:nid, :label, :next)
        ');

        foreach ($body['nodes'] as $node) {
            if (!empty($node['options'])) {
                foreach ($node['options'] as $opt) {
                    $stmtOpt->execute([
                        ':nid'   => $keyToId[$node['key']],
                        ':label' => $opt['label'],
                        ':next'  => $opt['next'],
                    ]);
                }
            }
        }

        if (!empty($body['edges'])) {
            $stmtEdge = $db->prepare('
                INSERT INTO edges (flow_id, from_key, to_key)
                VALUES (:fid, :from, :to)
            ');
            foreach ($body['edges'] as $edge) {
                $stmtEdge->execute([
                    ':fid'  => $flowId,
                    ':from' => $edge['from'],
                    ':to'   => $edge['to'],
                ]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'saved' => count($body['nodes'])]);

    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
}

function getNodes(string $flowId): void {
    $db = getDB();

    $stmt = $db->prepare('SELECT * FROM nodes WHERE flow_id = :fid ORDER BY id');
    $stmt->execute([':fid' => $flowId]);
    $nodes = $stmt->fetchAll();

    foreach ($nodes as &$node) {
        $s = $db->prepare('SELECT label, next_key AS next FROM options WHERE node_id = :nid ORDER BY id');
        $s->execute([':nid' => $node['id']]);
        $node['options'] = $s->fetchAll();
    }

    $stmt = $db->prepare('SELECT from_key AS `from`, to_key AS `to` FROM edges WHERE flow_id = :fid');
    $stmt->execute([':fid' => $flowId]);
    $edges = $stmt->fetchAll();

    echo json_encode(['nodes' => $nodes, 'edges' => $edges]);
}
