<?php
// api/export.php
// Gera e faz download do plugin WordPress em ZIP

function exportFlow(string $flowId): void
{
    $db = getDB();

    // 1. Busca flow
    $stmt = $db->prepare("SELECT * FROM flows WHERE id = :id");
    $stmt->execute([':id' => $flowId]);
    $flow = $stmt->fetch();

    if (!$flow) {
        http_response_code(404);
        echo json_encode(['error' => 'Flow not found']);
        return;
    }

    // 2. Busca nós + opções
    $stmt = $db->prepare("SELECT * FROM nodes WHERE flow_id = :fid ORDER BY id");
    $stmt->execute([':fid' => $flowId]);
    $rawNodes = $stmt->fetchAll();

    if (empty($rawNodes)) {
        http_response_code(422);
        echo json_encode(['error' => 'Flow has no nodes']);
        return;
    }

    foreach ($rawNodes as &$node) {
        $s = $db->prepare("SELECT label, next_key as next FROM options WHERE node_id = :nid ORDER BY id");
        $s->execute([':nid' => $node['id']]);
        $node['options'] = $s->fetchAll();
    }

    // 3. Serializa para o formato que o chat.js consome
    $flowJson = serializeToFlowJson($rawNodes);

    // 4. Slug da pasta / arquivo (pode ter hífen — OK para WordPress)
    $slugDir = 'chatbot-' . $flowId . '-' . time();
    // Prefixo PHP: SEM hífens — senão `function chatbot-1-xxx()` dá erro fatal na ativação
    $phpPrefix = 'chatbot_flow_' . (int)$flowId . '_' . time();

    // 5. Monta os arquivos do plugin
    $files = buildPluginFiles($slugDir, $phpPrefix, $flow['name'], $flowJson);

    // 6. Gera ZIP em memória
    $tmpZip = sys_get_temp_dir() . '/' . $slugDir . '.zip';
    $zip = new ZipArchive();

    if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not create ZIP']);
        return;
    }

    foreach ($files as $path => $content) {
        $zip->addFromString($slugDir . '/' . $path, $content);
    }
    $zip->close();

    // 7. Stream do ZIP para download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $slugDir . '.zip"');
    header('Content-Length: ' . filesize($tmpZip));
    header('Cache-Control: no-store');
    readfile($tmpZip);
    unlink($tmpZip);
    exit;
}

// ─── Serializa nós do banco para o formato flow.json ─────────────────────
function serializeToFlowJson(array $nodes): string
{
    $startKey = $nodes[0]['node_key'];
    $nodesObj = [];

    foreach ($nodes as $node) {
        $n = [
            'type' => $node['type'],
            'delay' => (int)$node['delay_ms'],
        ];

        switch ($node['type']) {
            case 'text':
            case 'question':
                $n['content'] = $node['content'];
                break;
            case 'image':
                $n['content'] = $node['content'];
                $n['caption'] = $node['caption'] ?? '';
                break;
            case 'audio':
                $n['content'] = $node['content'];
                $n['caption'] = $node['caption'] ?? '';
                break;
            case 'ad':
                // content = JSON: {"title":"...","sub":"...","image":"..."}
                $ad = json_decode($node['content'], true) ?? [];
                $n['adTitle'] = $ad['title'] ?? $node['caption'] ?? '';
                $n['adSub'] = $ad['sub'] ?? '';
                $n['adImage'] = $ad['image'] ?? '';
                break;
        }

        if (!empty($node['options'])) {
            $n['options'] = array_map(fn($o) => [
            'label' => $o['label'],
            'next' => $o['next'],
            ], $node['options']);
        }
        else {
            // Sem opções → pega próximo nó pela ordem
            $keys = array_column($nodes, 'node_key');
            $idx = array_search($node['node_key'], $keys);
            if ($idx !== false && isset($keys[$idx + 1])) {
                $n['next'] = $keys[$idx + 1];
            }
        }

        $nodesObj[$node['node_key']] = $n;
    }

    return json_encode(['start' => $startKey, 'nodes' => $nodesObj], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// ─── Monta todos os arquivos do plugin ───────────────────────────────────
function buildPluginFiles(string $slugDir, string $phpPrefix, string $flowName, string $flowJson): array
{
    $files = [
        $slugDir . '.php' => buildPluginPhp($slugDir, $phpPrefix, $flowName),
        'assets/chat.js' => buildChatJs(),
        'assets/chat.css' => buildChatCss(),
        'assets/flow.json' => $flowJson,
        'readme.txt' => buildReadme($flowName),
    ];

    // Assets visuais padrão do widget (se existirem no backend)
    $bg = readLocalBinaryAsset(__DIR__ . '/../img/fundowhatsap.jpg');
    if ($bg !== null) {
        $files['assets/fundowhatsap.jpg'] = $bg;
    }
    $avatar = readLocalBinaryAsset(__DIR__ . '/../img/mulherescritorio.jpg');
    if ($avatar !== null) {
        $files['assets/avatar.jpg'] = $avatar;
    }

    return $files;
}

function readLocalBinaryAsset(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $data = @file_get_contents($path);
    return $data === false ? null : $data;
}

/**
 * @param string $slugDir   Nome da pasta do plugin (ex.: chatbot-1-1732...)
 * @param string $phpPrefix Identificador válido em PHP (ex.: chatbot_flow_1_1732...)
 */
function buildPluginPhp(string $slugDir, string $phpPrefix, string $flowName): string
{
    $safeName = addslashes($flowName);
    // Constantes em maiúsculas, só [A-Z0-9_]
    $constBase = strtoupper($phpPrefix);
    return <<<PHP



<?php
/**
 * Plugin Name: Chatbot – {$safeName}
 * Description: Widget de chatbot interativo estilo WhatsApp
 * Version:     1.0.0
 * Author:      BTZ.IO
 */

if (!defined('ABSPATH')) exit;

define('{$constBase}_URL', plugin_dir_url(__FILE__));
define('{$constBase}_PATH', plugin_dir_path(__FILE__));

function {$phpPrefix}_enqueue(): void {
    wp_enqueue_style(
        '{$phpPrefix}-style',
        {$constBase}_URL . 'assets/chat.css',
        [], '1.0.3'
    );
    wp_enqueue_script(
        '{$phpPrefix}-script',
        {$constBase}_URL . 'assets/chat.js',
        [], '1.0.3', true
    );
    wp_localize_script('{$phpPrefix}-script', 'CHATBOT_CONFIG', [
        'flowUrl' => {$constBase}_URL . 'assets/flow.json',
        'name'    => '{$safeName}',
        'avatarUrl' => {$constBase}_URL . 'assets/avatar.jpg',
        'bgUrl' => {$constBase}_URL . 'assets/fundowhatsap.jpg',
        'verified' => true,
    ]);
}
add_action('wp_enqueue_scripts', '{$phpPrefix}_enqueue');

function {$phpPrefix}_shortcode(): string {
    ob_start(); ?>
    <div id="chatbot-widget-wrap" class="chatbot-centered">
        <div id="chat-wrap"></div>
    </div>
    <?php return ob_get_clean();
}
add_shortcode('chatbot', '{$phpPrefix}_shortcode');
PHP;
}

function buildReadme(string $flowName): string
{
    return "=== Chatbot: {$flowName} ===\nGerado por BTZ.IO\n\nUso: adicione o shortcode [chatbot] em qualquer página ou post.\n";
}

// ─── chat.js — Engine completo embutido ──────────────────────────────────
function buildChatJs(): string
{
    return <<<'JS'



(function(){
"use strict";

let FLOW = null;
let chatbotAdServicesEnabled = false;

function ensureGptLoaded() {
    return new Promise((resolve) => {
        if (window.googletag && window.googletag.apiReady) {
            resolve({ ok: true, reason: 'api_ready' });
            return;
        }
        window.googletag = window.googletag || { cmd: [] };
        const existing = document.querySelector('script[src*="gpt.js"]');
        if (existing) {
            if (window.googletag && window.googletag.apiReady) {
                resolve({ ok: true, reason: 'api_ready_existing' });
                return;
            }
            existing.addEventListener('load', () => resolve({ ok: true, reason: 'loaded_existing' }), { once: true });
            existing.addEventListener('error', () => resolve({ ok: false, reason: 'gpt_script_error_existing' }), { once: true });
            return;
        }
        const s = document.createElement('script');
        s.async = true;
        s.src = 'https://securepubads.g.doubleclick.net/tag/js/gpt.js';
        s.setAttribute('data-gpt', '1');
        s.onload = () => resolve({ ok: true, reason: 'loaded_new' });
        s.onerror = () => resolve({ ok: false, reason: 'gpt_script_error_new' });
        document.head.appendChild(s);
    });
}

function setAdError(containerId, reason, detail) {
    const el = document.getElementById(containerId);
    const detailText = detail ? ` (${detail})` : '';
    if (el) el.innerHTML = `<span class="ad-fallback">Erro anuncio: ${reason}${detailText}</span>`;
    console.error('[Chatbot][Ads]', reason, detail || '');
}

function showContentAd(containerId) {
    ensureGptLoaded().then((gpt) => {
        if (!gpt.ok || !window.googletag) {
            setAdError(containerId, 'gpt_load_failed', gpt.reason);
            return;
        }
        window.googletag = window.googletag || { cmd: [] };
        window.googletag.cmd.push(function() {
            var slot = window.googletag.defineSlot(
                '/23086665414/apps.solutidigital.com/apps.solutidigital.com_Interstitial',
                [[300, 250], [320, 100], [320, 50]],
                containerId
            );
            if (slot) {
                slot.addService(window.googletag.pubads());
                if (!chatbotAdServicesEnabled) {
                    window.googletag.enableServices();
                    chatbotAdServicesEnabled = true;
                }
                window.googletag.display(containerId);
            } else {
                setAdError(containerId, 'slot_not_created');
            }
        });
    }).catch((e) => {
        setAdError(containerId, 'unexpected_exception', e && e.message ? e.message : '');
    });
}

function appendInlineAd() {
    const containerId = `bloco_content_${Date.now()}`;
    const wrap = document.createElement('div');
    wrap.className = 'bubble-wrap bot ad-inline-wrap';
    wrap.innerHTML = wrapBotRow(`
      <div class="ad-inline-box">
        <div class="ad-inline-label">PUBLICIDADE</div>
        <div id="${containerId}" class="ad-slot-container">
          <span class="ad-loading">Carregando anuncio...</span>
        </div>
      </div>`);
    $('messages').appendChild(wrap);
    scroll();
    showContentAd(containerId);
}

async function init() {
    const url = (window.CHATBOT_CONFIG && CHATBOT_CONFIG.flowUrl)
        ? CHATBOT_CONFIG.flowUrl
        : 'assets/flow.json';
    try {
        const r = await fetch(url);
        FLOW = await r.json();
    } catch(e) {
        console.error('[Chatbot] Não foi possível carregar flow.json', e);
        return;
    }
    renderShell();
    setTimeout(() => renderNode(FLOW.start), 600);
}

function renderShell() {
    const wrap = document.getElementById('chat-wrap');
    if (!wrap) return;
    const cfg = window.CHATBOT_CONFIG || {};
    const showVerified = !!cfg.verified;
    wrap.innerHTML = `
    <div id="chat-header">
      <div class="chat-avatar">
        <img class="chat-avatar-img" src="" alt=""/>
        <span class="chat-avatar-letter"></span>
      </div>
      <div class="chat-info">
        <div class="chat-name-row">
          <div class="chat-name">${cfg.name || 'Atendente'}</div>
          ${showVerified ? '<span class="chat-verified" title="Conta verificada">✓</span>' : ''}
        </div>
        <div class="chat-status">on-line</div>
      </div>
      <div class="chat-header-actions">
        <button type="button" class="chat-header-icon-btn" aria-label="Ligar" title="Ligar">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><path d="M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z"/></svg>
        </button>
        <button type="button" class="chat-header-icon-btn" aria-label="Menu" title="Menu">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>
        </button>
      </div>
    </div>
    <div id="messages"><div class="date-divider"><span>Hoje</span></div></div>
    <div id="options-wrap" style="display:none"></div>
    <div id="chat-footer">
      <input id="footer-msg" type="text" placeholder="Aguarde as mensagens..." disabled/>
      <div class="footer-icon">
        <svg viewBox="0 0 24 24" fill="white" width="18" height="18"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
      </div>
    </div>`;

    const name = String(cfg.name || 'Atendente').trim();
    const letter = name.charAt(0).toUpperCase() || '?';
    const img = wrap.querySelector('.chat-avatar-img');
    const letterEl = wrap.querySelector('.chat-avatar-letter');
    const url = cfg.avatarUrl || '';
    if (url && img && letterEl) {
        img.src = url;
        img.alt = name;
        img.style.display = 'block';
        letterEl.style.display = 'none';
    } else if (img && letterEl) {
        img.removeAttribute('src');
        img.style.display = 'none';
        letterEl.textContent = letter;
        letterEl.style.display = 'flex';
    }

    const bg = cfg.bgUrl || '';
    if (bg) {
        const msg = $('messages');
        msg.style.backgroundImage = `url('${bg}')`;
        msg.style.backgroundSize = 'cover';
        msg.style.backgroundPosition = 'center';
    }
}

function escapeChatAttr(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

function botAvatarMarkup() {
    const cfg = window.CHATBOT_CONFIG || {};
    const url = cfg.avatarUrl || '';
    const name = String(cfg.name || 'B').trim();
    const initial = name.charAt(0).toUpperCase() || '?';
    if (url) return `<img class="msg-avatar-img" src="${escapeChatAttr(url)}" alt=""/>`;
    return `<span class="msg-avatar-fallback">${initial}</span>`;
}

function wrapBotRow(innerHtml) {
    return `<div class="bot-msg-row"><div class="bot-msg-avatar">${botAvatarMarkup()}</div><div class="bot-msg-content">${innerHtml}</div></div>`;
}

const $ = id => document.getElementById(id);

function t() {
    return new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit'});
}
function scroll() {
    setTimeout(() => { const m=$('messages'); if(m) m.scrollTop=m.scrollHeight; }, 50);
}

function typing() {
    const w=document.createElement('div');
    w.className='bubble-wrap bot'; w.id='typing';
    w.innerHTML=wrapBotRow('<div class="typing-bubble"><div class="dot"></div><div class="dot"></div><div class="dot"></div></div>');
    $('messages').appendChild(w); scroll();
}
function removeTyping() { const t=$('typing'); if(t) t.remove(); }

function botBubble(html) {
    removeTyping();
    const w=document.createElement('div');
    w.className='bubble-wrap bot'; w.innerHTML=wrapBotRow(html);
    $('messages').appendChild(w); scroll();
}

function userBubble(text) {
    const w=document.createElement('div');
    w.className='bubble-wrap user';
    w.innerHTML=`<div class="bubble user">${text}<span class="time">${t()} ✓✓</span></div>`;
    $('messages').appendChild(w); scroll();
}

function clearOpts() {
    const o=$('options-wrap');
    o.innerHTML=''; o.style.display='none';
}

function showOpts(opts) {
    const o=$('options-wrap');
    o.style.display='flex'; o.innerHTML='';
    opts.forEach(opt => {
        const b=document.createElement('button');
        b.className='opt-btn'; b.textContent=opt.label;
        b.onclick=()=>pick(opt);
        o.appendChild(b);
    });
    scroll();
}

function pick(opt) {
    clearOpts(); userBubble(opt.label);
    setTimeout(()=>renderNode(opt.next),500);
}

function renderNode(key) {
    if(key==='__restart'){ restart(); return; }
    const node=FLOW.nodes[key]; if(!node) return;
    typing();
    setTimeout(()=>{
        removeTyping();
        if(node.type==='text'||node.type==='question') {
            botBubble(`<div class="bubble bot">${node.content}<span class="time">${t()}</span></div>`);
        } else if(node.type==='image') {
            botBubble(`<div class="bubble bot" style="padding:6px"><img src="${node.content}" alt="${node.caption}" style="max-width:100%;border-radius:6px;display:block;margin-bottom:4px"/><span style="font-size:13px">${node.caption}</span><span class="time">${t()}</span></div>`);
        } else if(node.type==='audio') {
            const bars=Array.from({length:18},(_,i)=>`<span style="height:${4+Math.round(Math.abs(Math.sin(i*.7))*18)}px;animation-delay:${i*.08}s"></span>`).join('');
            botBubble(`<div class="bubble bot"><div class="audio-player"><button class="play-btn"><svg viewBox="0 0 24 24" fill="white" width="14" height="14"><path d="M8 5v14l11-7z"/></svg></button><div class="waveform">${bars}</div><span style="font-size:11px;color:#999">0:07</span></div><span style="font-size:12px;color:#999;display:block;margin-top:4px">${node.caption||''}</span><span class="time">${t()}</span></div>`);
        } else if(node.type==='ad') {
            appendInlineAd();
        }
        if(node.options&&node.options.length) setTimeout(()=>showOpts(node.options),300);
        else if(node.next) setTimeout(()=>renderNode(node.next),node.delay||800);
    }, node.delay||800);
}

function restart() {
    $('messages').innerHTML='<div class="date-divider"><span>Hoje</span></div>';
    clearOpts();
    setTimeout(()=>renderNode(FLOW.start),400);
}

document.addEventListener('DOMContentLoaded', init);
})();
JS;
}

function buildChatCss(): string
{
    return <<<'CSS'



/* chat.css — BTZ.IO Chatbot Plugin */
.chatbot-centered {
    position: fixed; top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    z-index: 9999;
    width: clamp(300px, min(92vw, 92dvw), 520px);
    max-width: min(520px, 96vw);
    height: min(90vh, 90dvh, 740px);
    max-height: min(90vh, 90dvh, 740px);
}
#chat-wrap {
    width: 100%; height: 100%;
    display: flex; flex-direction: column;
    background: #e5ddd5; border-radius: 12px;
    overflow: hidden; box-shadow: 0 20px 60px rgba(0,0,0,.25);
    font-family: 'Segoe UI', Arial, sans-serif;
}
#chat-header {
    background: #075e54; padding: 10px 12px 10px 14px;
    display: flex; align-items: center; gap: 10px; flex-shrink: 0;
}
.chat-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    background: #128c7e; overflow: hidden; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.chat-avatar-img {
    width: 100%; height: 100%; object-fit: cover; display: none;
}
.chat-avatar-letter {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 100%;
    color: #fff; font-weight: 600; font-size: 16px;
}
.chat-info { flex: 1; min-width: 0; }
.chat-name-row { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.chat-name  { color: white; font-weight: 600; font-size: 15px; line-height: 1.2; }
.chat-verified {
    width: 16px; height: 16px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    background: #25d366; color: #fff; font-size: 10px; font-weight: 700; line-height: 1;
}
.chat-status{ color: #b2dfdb; font-size: 12px; }
.chat-header-actions {
    display: flex; align-items: center; gap: 4px; flex-shrink: 0;
    margin-left: auto;
}
.chat-header-icon-btn {
    width: 40px; height: 40px; border: none; border-radius: 50%;
    background: transparent; color: rgba(255,255,255,0.92);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background 0.15s;
    padding: 0;
}
.chat-header-icon-btn:hover { background: rgba(255,255,255,0.12); }
#messages   {
    flex: 1; overflow-y: auto; padding: 12px 10px;
    display: flex; flex-direction: column; gap: 4px; scroll-behavior: smooth;
    background-color: #efe7dd;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23c8bdb0' fill-opacity='0.22'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.bubble-wrap       { display: flex; flex-direction: column; width: 100%; }
.bubble-wrap.user  { align-items: flex-end; }
.bubble-wrap.bot   { align-items: flex-start; }
.bot-msg-row {
    display: flex; flex-direction: row; align-items: flex-end; gap: 8px;
    width: 100%; max-width: 100%;
}
.bot-msg-avatar {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    background: #128c7e; overflow: hidden;
    display: flex; align-items: center; justify-content: center;
}
.msg-avatar-img {
    width: 100%; height: 100%; object-fit: cover; display: block;
}
.msg-avatar-fallback {
    color: #fff; font-size: 13px; font-weight: 600;
}
.bot-msg-content {
    flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start;
    max-width: calc(100% - 40px);
}
.bubble {
    max-width: min(85%, 340px); padding: 8px 12px 6px; border-radius: 8px;
    font-size: 14px; line-height: 1.45; position: relative;
    animation: popIn .2s cubic-bezier(.175,.885,.32,1.275) both; word-break: break-word;
}
.bubble.bot  { background: white; border-radius: 0 8px 8px 8px; color: #111; }
.bubble.user { background: #dcf8c6; border-radius: 8px 0 8px 8px; color: #111; }
.bubble .time{ font-size: 11px; color: #999; float: right; margin-left: 8px; margin-top: 2px; }
.bubble.user .time { color: #7bb56e; }
.typing-bubble {
    background: white; border-radius: 0 8px 8px 8px;
    padding: 12px 16px; display: flex; align-items: center; gap: 4px;
    animation: popIn .2s both;
}
.dot { width: 7px; height: 7px; border-radius: 50%; background: #999; animation: bounce 1.2s infinite ease-in-out; }
.dot:nth-child(2){ animation-delay: .2s; }
.dot:nth-child(3){ animation-delay: .4s; }
.audio-player { display: flex; align-items: center; gap: 10px; min-width: 180px; }
.play-btn { width: 36px; height: 36px; border-radius: 50%; background: #128c7e; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: white; }
.waveform { flex: 1; height: 28px; display: flex; align-items: center; gap: 2px; }
.waveform span { display: block; width: 3px; border-radius: 2px; background: #128c7e; opacity: .5; animation: wave 1s infinite ease-in-out; }
.ad-inline-wrap { align-items: center; margin: 6px 0 8px; }
.ad-inline-box {
    width: 100%;
    max-width: min(340px, 100%);
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    border: 1px solid #e5e7eb;
}
.ad-inline-label {
    font-size: 11px;
    color: #9ca3af;
    text-align: center;
    border-bottom: 1px solid #eeeeee;
    padding: 4px 8px;
    text-transform: uppercase;
    letter-spacing: .2px;
}
.ad-slot-container {
    min-height: 250px;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #f8fafc;
}
.ad-loading, .ad-fallback {
    font-size: 12px;
    color: #94a3b8;
}
#options-wrap { padding: 8px 10px 8px calc(10px + 32px + 8px); display: flex; flex-direction: column; gap: 6px; animation: slideUp .25s ease both; }
.opt-btn { background: #128c7e; color: white; border: none; border-radius: 24px; padding: 10px 18px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background .15s, transform .1s; }
.opt-btn:hover  { background: #0d7066; }
.opt-btn:active { transform: scale(.97); }
#chat-footer { background: #f0f0f0; padding: 8px 10px; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
#footer-msg { flex: 1; background: white; border-radius: 24px; padding: 9px 16px; font-size: 14px; color: #999; border: none; outline: none; }
.footer-icon { width: 40px; height: 40px; border-radius: 50%; background: #075e54; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.date-divider { text-align: center; margin: 8px 0; }
.date-divider span { background: #d9d2ca; padding: 3px 10px; border-radius: 8px; font-size: 11.5px; color: #666; }
@keyframes popIn   { from{opacity:0;transform:scale(.85) translateY(6px)} to{opacity:1;transform:scale(1) translateY(0)} }
@keyframes slideUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
@keyframes bounce  { 0%,80%,100%{transform:translateY(0)} 40%{transform:translateY(-6px)} }
@keyframes wave    { 0%,100%{height:4px} 50%{height:20px} }
CSS;
}
