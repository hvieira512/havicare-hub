import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const {
    initSettingsDenylist,
    loadSettingsDenylistSection,
    handleDenylistListClick,
} = await import("../../src/Dashboard/dashboard/settings/denylist.js");

const jsonResponse = (obj, status = 200) => ({
    ok: status >= 200 && status < 300,
    status,
    text: async () => JSON.stringify(obj),
});

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function setupDom() {
    document.body.innerHTML = "<span id=\"summary\"></span><div id=\"body\"></div>";
    const els = {
        denylistTabSummary: document.getElementById("summary"),
        denylistListBody: document.getElementById("body"),
    };
    initSettingsDenylist({ els });
    return els;
}

test("a tab lista os bloqueados com um botão de desbloquear por linha", async () => {
    const els = setupDom();
    globalThis.fetch = async () => jsonResponse({
        data: [{
            identity: "357000000000123",
            protocol: "four-p-touch",
            note: "vizinho",
            created_by: "admin",
            created_at: "2026-09-07T10:00:00Z",
        }],
    });

    await loadSettingsDenylistSection();

    assert.match(els.denylistListBody.innerHTML, /357000000000123/);
    assert.match(els.denylistListBody.innerHTML, /data-action="unblock"/);
    assert.match(els.denylistListBody.innerHTML, /data-id="357000000000123"/);
    assert.match(els.denylistTabSummary.textContent, /1 aparelho bloqueado/);
});

test("desbloquear chama o DELETE da API e tira a linha", async () => {
    const els = setupDom();
    const calls = [];
    globalThis.fetch = async (url, options = {}) => {
        const method = options.method || "GET";
        calls.push({ url, method });
        return method === "GET"
            ? jsonResponse({ data: [{ identity: "357000000000123", protocol: "four-p-touch" }] })
            : jsonResponse({ status: "ok", identity: "357000000000123" });
    };

    await loadSettingsDenylistSection();
    const button = els.denylistListBody.querySelector("[data-action=\"unblock\"]");
    assert.ok(button, "a linha traz um botão de desbloquear");

    handleDenylistListClick({ target: button });
    await tick();

    const deleteCall = calls.find((call) => call.method === "DELETE");
    assert.ok(deleteCall, "chamou o DELETE da denylist");
    assert.match(deleteCall.url, /\/api\/denylist\/357000000000123/);
    assert.doesNotMatch(els.denylistListBody.innerHTML, /357000000000123/);
    assert.match(els.denylistListBody.innerHTML, /Sem aparelhos bloqueados/);
});

test("uma lista vazia diz que não há bloqueados", async () => {
    const els = setupDom();
    globalThis.fetch = async () => jsonResponse({ data: [] });

    await loadSettingsDenylistSection();

    assert.match(els.denylistListBody.innerHTML, /Sem aparelhos bloqueados/);
    assert.match(els.denylistTabSummary.textContent, /0 aparelhos bloqueados/);
});
