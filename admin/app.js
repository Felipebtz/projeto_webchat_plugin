(function () {
  "use strict";

  const state = {
    apiBase: "http://localhost:8000",
    flows: [],
    currentFlow: null,
  };

  const $ = (id) => document.getElementById(id);

  const els = {};

  function slug(prefix = "msg") {
    return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
  }

  function emptyNode() {
    return {
      key: slug(),
      type: "text",
      content: "Nova mensagem",
      caption: "",
      delay: 800,
      pos_x: 0,
      pos_y: 0,
      options: [],
    };
  }

  function currentFlowPayload() {
    if (!state.currentFlow) return null;
    return {
      name: els.flowName.value.trim(),
      description: els.flowDescription.value.trim(),
      nodes: state.currentFlow.nodes,
      edges: state.currentFlow.edges || [],
    };
  }

  async function request(path, options = {}) {
    const res = await fetch(`${state.apiBase}${path}`, {
      headers: { "Content-Type": "application/json" },
      ...options,
    });
    const text = await res.text();
    let data = null;
    try { data = text ? JSON.parse(text) : null; } catch { data = text; }
    if (!res.ok) {
      throw new Error((data && data.error) || `HTTP ${res.status}`);
    }
    return data;
  }

  function renderFlows() {
    els.flowsList.innerHTML = "";
    if (!state.flows.length) {
      els.flowsList.innerHTML = `<div class="text-sm text-slate-500">Nenhum flow criado ainda.</div>`;
      return;
    }
    state.flows.forEach((flow) => {
      const button = document.createElement("button");
      button.className = `w-full text-left rounded-xl border px-3 py-3 hover:bg-slate-50 ${state.currentFlow && state.currentFlow.id === flow.id ? "border-indigo-500 bg-indigo-50" : "border-slate-200"}`;
      button.innerHTML = `<div class="font-medium">${flow.name}</div><div class="text-xs text-slate-500">${flow.description || "Sem descrição"}</div><div class="text-xs text-slate-400 mt-1">${flow.node_count || 0} mensagens</div>`;
      button.onclick = () => loadFlow(flow.id);
      els.flowsList.appendChild(button);
    });
  }

  function renderNodes() {
    els.nodesList.innerHTML = "";
    if (!state.currentFlow) {
      els.nodesList.innerHTML = `<div class="text-sm text-slate-500">Selecione ou crie um flow para começar.</div>`;
      els.currentFlowInfo.textContent = "Nenhum flow selecionado";
      els.jsonPreview.textContent = "";
      return;
    }

    els.currentFlowInfo.textContent = `Flow #${state.currentFlow.id}`;
    els.jsonPreview.textContent = JSON.stringify(currentFlowPayload(), null, 2);

    if (!state.currentFlow.nodes.length) {
      els.nodesList.innerHTML = `<div class="text-sm text-slate-500">Sem mensagens ainda. Clique em “+ Mensagem”.</div>`;
      return;
    }

    state.currentFlow.nodes.forEach((node, index) => {
      const card = document.createElement("div");
      card.className = "rounded-xl border border-slate-200 p-3";
      card.innerHTML = `
        <div class="flex items-center justify-between gap-2 mb-3">
          <div>
            <div class="font-medium">Mensagem ${index + 1}</div>
            <div class="text-xs text-slate-500">${node.key}</div>
          </div>
          <div class="flex gap-2">
            <button class="text-xs rounded-md border px-2 py-1" data-action="up">↑</button>
            <button class="text-xs rounded-md border px-2 py-1" data-action="down">↓</button>
            <button class="text-xs rounded-md border px-2 py-1 text-red-600" data-action="delete">Remover</button>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2">
          <label class="text-sm">
            <span class="block text-xs font-medium mb-1">Tipo</span>
            <select class="node-type w-full rounded-lg border px-2 py-2">
              <option value="text">Texto</option>
              <option value="question">Pergunta</option>
              <option value="image">Imagem</option>
              <option value="audio">Áudio</option>
              <option value="ad">Anúncio</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="block text-xs font-medium mb-1">Delay (ms)</span>
            <input class="node-delay w-full rounded-lg border px-2 py-2" type="number" min="0" />
          </label>
        </div>
        <label class="text-sm mt-2 block">
          <span class="block text-xs font-medium mb-1">Conteúdo</span>
          <textarea class="node-content w-full rounded-lg border px-3 py-2" rows="3"></textarea>
        </label>
        <label class="text-sm mt-2 block">
          <span class="block text-xs font-medium mb-1">Legenda</span>
          <input class="node-caption w-full rounded-lg border px-3 py-2" />
        </label>
        <div class="mt-3">
          <div class="flex items-center justify-between mb-2">
            <h3 class="text-sm font-semibold">Opções</h3>
            <button class="text-xs rounded-md bg-slate-900 text-white px-2 py-1 add-option">+ Opção</button>
          </div>
          <div class="options-list space-y-2"></div>
        </div>
      `;

      const typeSelect = card.querySelector(".node-type");
      const delayInput = card.querySelector(".node-delay");
      const contentInput = card.querySelector(".node-content");
      const captionInput = card.querySelector(".node-caption");
      const optionsList = card.querySelector(".options-list");

      typeSelect.value = node.type;
      delayInput.value = node.delay ?? 800;
      contentInput.value = node.content ?? "";
      captionInput.value = node.caption ?? "";

      typeSelect.onchange = () => {
        node.type = typeSelect.value;
        updatePreview();
      };
      delayInput.oninput = () => {
        node.delay = Number(delayInput.value || 0);
        updatePreview();
      };
      contentInput.oninput = () => {
        node.content = contentInput.value;
        updatePreview();
      };
      captionInput.oninput = () => {
        node.caption = captionInput.value;
        updatePreview();
      };

      function renderOptions() {
        optionsList.innerHTML = "";
        if (!node.options.length) {
          optionsList.innerHTML = `<div class="text-xs text-slate-500">Sem opções.</div>`;
          return;
        }
        node.options.forEach((opt, optIndex) => {
          const row = document.createElement("div");
          row.className = "grid grid-cols-12 gap-2";
          row.innerHTML = `
            <input class="col-span-6 rounded-lg border px-2 py-2 opt-label" placeholder="Texto do botão" />
            <input class="col-span-5 rounded-lg border px-2 py-2 opt-next" placeholder="Próxima mensagem" />
            <button class="col-span-1 rounded-lg border text-red-600 remove-opt">×</button>
          `;
          row.querySelector(".opt-label").value = opt.label || "";
          row.querySelector(".opt-next").value = opt.next || "";
          row.querySelector(".opt-label").oninput = (e) => {
            opt.label = e.target.value;
            updatePreview();
          };
          row.querySelector(".opt-next").oninput = (e) => {
            opt.next = e.target.value;
            updatePreview();
          };
          row.querySelector(".remove-opt").onclick = () => {
            node.options.splice(optIndex, 1);
            renderNodes();
          };
          optionsList.appendChild(row);
        });
      }

      card.querySelector(".add-option").onclick = () => {
        node.options.push({ label: "Nova opção", next: "" });
        renderNodes();
      };

      card.querySelector('[data-action="delete"]').onclick = () => {
        state.currentFlow.nodes.splice(index, 1);
        renderNodes();
      };
      card.querySelector('[data-action="up"]').onclick = () => {
        if (index === 0) return;
        const tmp = state.currentFlow.nodes[index - 1];
        state.currentFlow.nodes[index - 1] = state.currentFlow.nodes[index];
        state.currentFlow.nodes[index] = tmp;
        renderNodes();
      };
      card.querySelector('[data-action="down"]').onclick = () => {
        if (index === state.currentFlow.nodes.length - 1) return;
        const tmp = state.currentFlow.nodes[index + 1];
        state.currentFlow.nodes[index + 1] = state.currentFlow.nodes[index];
        state.currentFlow.nodes[index] = tmp;
        renderNodes();
      };

      renderOptions();
      els.nodesList.appendChild(card);
    });
  }

  function updatePreview() {
    if (state.currentFlow) {
      els.jsonPreview.textContent = JSON.stringify(currentFlowPayload(), null, 2);
    }
  }

  async function loadFlows() {
    state.apiBase = els.apiBase.value.trim().replace(/\/$/, "");
    const data = await request("/api/flows");
    state.flows = data.data || [];
    renderFlows();
  }

  async function loadFlow(id) {
    state.apiBase = els.apiBase.value.trim().replace(/\/$/, "");
    const data = await request(`/api/flows/${id}`);
    state.currentFlow = {
      id: data.data.id,
      name: data.data.name || "",
      description: data.data.description || "",
      nodes: (data.data.nodes || []).map((node) => ({
        key: node.node_key || node.key || slug(),
        type: node.type || "text",
        content: node.content || "",
        caption: node.caption || "",
        delay: Number(node.delay_ms || node.delay || 800),
        pos_x: Number(node.pos_x || 0),
        pos_y: Number(node.pos_y || 0),
        options: (node.options || []).map((opt) => ({
          label: opt.label || "",
          next: opt.next_key || opt.next || "",
        })),
      })),
      edges: data.data.edges || [],
    };
    els.flowName.value = state.currentFlow.name;
    els.flowDescription.value = state.currentFlow.description;
    renderFlows();
    renderNodes();
  }

  async function createFlow() {
    state.apiBase = els.apiBase.value.trim().replace(/\/$/, "");
    const name = prompt("Nome do novo flow:");
    if (!name) return;
    const description = prompt("Descrição do flow:") || "";
    const data = await request("/api/flows", {
      method: "POST",
      body: JSON.stringify({ name, description }),
    });
    await loadFlows();
    await loadFlow(data.data.id);
  }

  async function saveFlow() {
    if (!state.currentFlow) return;
    const payload = currentFlowPayload();
    await request(`/api/flows/${state.currentFlow.id}`, {
      method: "PUT",
      body: JSON.stringify({
        name: payload.name,
        description: payload.description,
      }),
    });
    await request(`/api/flows/${state.currentFlow.id}/nodes`, {
      method: "POST",
      body: JSON.stringify({
        nodes: payload.nodes,
        edges: payload.edges,
      }),
    });
    await loadFlows();
    alert("Flow salvo com sucesso.");
  }

  function exportJson() {
    if (!state.currentFlow) return;
    const payload = currentFlowPayload();
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: "application/json" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `${payload.name || "flow"}.json`;
    a.click();
    URL.revokeObjectURL(url);
  }

  function bind() {
    els.btnLoadFlows.onclick = () => loadFlows().catch((err) => alert(err.message));
    els.btnNewFlow.onclick = () => createFlow().catch((err) => alert(err.message));
    els.btnAddNode.onclick = () => {
      if (!state.currentFlow) {
        alert("Selecione ou crie um flow primeiro.");
        return;
      }
      state.currentFlow.nodes.push(emptyNode());
      renderNodes();
    };
    els.btnSaveFlow.onclick = () => saveFlow().catch((err) => alert(err.message));
    els.btnExportJson.onclick = exportJson;
  }

  function init() {
    els.apiBase = $("apiBase");
    els.btnLoadFlows = $("btnLoadFlows");
    els.btnNewFlow = $("btnNewFlow");
    els.btnAddNode = $("btnAddNode");
    els.btnSaveFlow = $("btnSaveFlow");
    els.btnExportJson = $("btnExportJson");
    els.flowsList = $("flowsList");
    els.nodesList = $("nodesList");
    els.currentFlowInfo = $("currentFlowInfo");
    els.flowName = $("flowName");
    els.flowDescription = $("flowDescription");
    els.jsonPreview = $("jsonPreview");

    els.flowName.oninput = () => {
      if (state.currentFlow) state.currentFlow.name = els.flowName.value;
      updatePreview();
    };
    els.flowDescription.oninput = () => {
      if (state.currentFlow) state.currentFlow.description = els.flowDescription.value;
      updatePreview();
    };

    bind();
    loadFlows().catch(() => renderFlows());
    renderNodes();
  }

  document.addEventListener("DOMContentLoaded", init);
})();
