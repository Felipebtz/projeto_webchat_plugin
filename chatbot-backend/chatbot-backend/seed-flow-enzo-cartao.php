<?php
/**
 * Cria o fluxo "Cartão de crédito — Enzo" no MySQL (mesmo formato do admin / export).
 * Inclui nós type `ad` (anúncio automático GPT) entre etapas principais.
 *
 * Uso (na pasta chatbot-backend):
 *   php seed-flow-enzo-cartao.php
 *
 * Pré-requisitos: banco `chatbot_builder` existente; tabelas criadas
 * (subir a API uma vez com php -S ou rodar o CREATE do projeto).
 */
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

/** Ordem dos nós = ordem no banco (serializeToFlowJson usa “próximo” linear quando não há opções). */
$nodesPayload = [
    [
        'key' => 'n_start',
        'type' => 'text',
        'content' => "Olá, tudo bem? Sou o Enzo e vou fazer uma análise para aprovar o seu cartão de crédito.\n\nVocê já possui um cartão de crédito?",
        'delay' => 800,
        'options' => [
            ['label' => 'Sim', 'next' => 'n_ads1'],
            ['label' => 'Não', 'next' => 'n_ads1'],
        ],
    ],
    [
        'key' => 'n_ads1',
        'type' => 'ad',
        'content' => '',
        'delay' => 800,
        'options' => [],
    ],
    [
        'key' => 'n_idade',
        'type' => 'text',
        'content' => 'Qual é a sua faixa de idade?',
        'delay' => 800,
        'options' => [
            ['label' => 'Menos de 18 anos', 'next' => 'n_ads2'],
            ['label' => '18 a 24 anos', 'next' => 'n_ads2'],
            ['label' => '25 a 59 anos', 'next' => 'n_ads2'],
            ['label' => '60 anos ou mais', 'next' => 'n_ads2'],
        ],
    ],
    [
        'key' => 'n_ads2',
        'type' => 'ad',
        'content' => '',
        'delay' => 800,
        'options' => [],
    ],
    [
        'key' => 'n_profissao',
        'type' => 'text',
        'content' => 'Qual é a sua situação profissional?',
        'delay' => 800,
        'options' => [
            ['label' => 'Trabalho com carteira assinada', 'next' => 'n_finalidade'],
            ['label' => 'Trabalhador autônomo', 'next' => 'n_finalidade'],
            ['label' => 'Empresário', 'next' => 'n_finalidade'],
            ['label' => 'Desempregado', 'next' => 'n_finalidade'],
            ['label' => 'Outro', 'next' => 'n_finalidade'],
        ],
    ],
    [
        'key' => 'n_finalidade',
        'type' => 'text',
        'content' => 'Qual será a finalidade de uso do cartão?',
        'delay' => 800,
        'options' => [
            ['label' => 'Fazer compras', 'next' => 'n_ads3'],
            ['label' => 'Pagar contas', 'next' => 'n_ads3'],
            ['label' => 'Uso diário', 'next' => 'n_ads3'],
            ['label' => 'Fazer uma viagem', 'next' => 'n_ads3'],
            ['label' => 'Outro', 'next' => 'n_ads3'],
        ],
    ],
    [
        'key' => 'n_ads3',
        'type' => 'ad',
        'content' => '',
        'delay' => 800,
        'options' => [],
    ],
    [
        'key' => 'n_preaprovacao',
        'type' => 'text',
        'content' => "Perfeito. Concluí a análise e consegui uma pré-aprovação para você.\n\nEscolha o limite do seu cartão:",
        'delay' => 800,
        'options' => [
            ['label' => 'R$ 1.000', 'next' => 'n_parabens'],
            ['label' => 'R$ 3.000', 'next' => 'n_parabens'],
            ['label' => 'R$ 5.000', 'next' => 'n_parabens'],
            ['label' => 'R$ 10.000', 'next' => 'n_parabens'],
        ],
    ],
    [
        'key' => 'n_parabens',
        'type' => 'text',
        'content' => 'Parabéns pelo seu novo cartão! Para continuar, clique no botão abaixo e acesse nosso site para finalizar a solicitação.',
        'delay' => 800,
        'options' => [
            ['label' => 'Solicitar meu cartão', 'next' => 'n_fim'],
        ],
    ],
    [
        'key' => 'n_fim',
        'type' => 'text',
        'content' => 'Obrigado! Você pode finalizar a solicitação pelo site ou falar com um consultor. Deseja recomeçar o atendimento?',
        'delay' => 800,
        'options' => [
            ['label' => 'Recomeçar conversa', 'next' => '__restart'],
        ],
    ],
];

$flowName = 'Cartão de crédito — Enzo';
$flowDesc = 'Análise e pré-aprovação de cartão (roteiro Enzo + anúncios)';

$db = getDB();

$db->beginTransaction();

try {
    $stmt = $db->prepare('INSERT INTO flows (name, description) VALUES (:name, :desc)');
    $stmt->execute([':name' => $flowName, ':desc' => $flowDesc]);
    $flowId = (int) $db->lastInsertId();

    $stmtNode = $db->prepare('
        INSERT INTO nodes (flow_id, node_key, type, content, caption, delay_ms, pos_x, pos_y)
        VALUES (:fid, :key, :type, :content, :caption, :delay, 0, 0)
    ');
    $keyToId = [];

    foreach ($nodesPayload as $node) {
        $stmtNode->execute([
            ':fid' => $flowId,
            ':key' => $node['key'],
            ':type' => $node['type'],
            ':content' => $node['content'] !== '' ? $node['content'] : null,
            ':caption' => null,
            ':delay' => $node['delay'],
        ]);
        $keyToId[$node['key']] = (int) $db->lastInsertId();
    }

    $stmtOpt = $db->prepare('
        INSERT INTO options (node_id, label, next_key) VALUES (:nid, :label, :next)
    ');

    foreach ($nodesPayload as $node) {
        foreach ($node['options'] as $opt) {
            $stmtOpt->execute([
                ':nid' => $keyToId[$node['key']],
                ':label' => $opt['label'],
                ':next' => $opt['next'],
            ]);
        }
    }

    $stmtEdge = $db->prepare('
        INSERT INTO edges (flow_id, from_key, to_key) VALUES (:fid, :from, :to)
    ');
    foreach ($nodesPayload as $node) {
        foreach ($node['options'] as $opt) {
            $stmtEdge->execute([
                ':fid' => $flowId,
                ':from' => $node['key'],
                ':to' => $opt['next'],
            ]);
        }
    }

    $db->commit();

    fwrite(STDOUT, "OK: fluxo criado com id={$flowId} — {$flowName}\n");
    fwrite(STDOUT, "Admin: abra o fluxo e exporte o ZIP, ou use GET /api/flows/{$flowId}\n");
} catch (Throwable $e) {
    $db->rollBack();
    fwrite(STDERR, 'Erro: ' . $e->getMessage() . "\n");
    exit(1);
}
