import test from "node:test";
import assert from "node:assert/strict";

const {
    initSettingsDenylist,
    loadSettingsDenylistSection,
    handleDenylistListClick,
} = await import("../../src/Dashboard/dashboard/settings/denylist.js");

const jsonResponse = (obj, status = 200) => Response.json(obj, { status });

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

function setupDom() {
    document.body.innerHTML =
        "<span id=\"title\"></span><span id=\"summary\"></span><div id=\"body\"></div>";
    const els = {
        denylistTabTitle: document.getElementById("title"),
        denylistTabSummary: document.getElementById("summary"),
        denylistListBody: document.getElementById("body"),
    };
    initSettingsDenylist({ els });
    return els;
}

test("the tab lists blocked devices with an unblock button per row", async () => {
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

/** A listagem pagina: contar as linhas recebidas diria vinte a quem tem trinta. */
test("the count comes from the API total, not the page rows", async () => {
    const els = setupDom();
    globalThis.fetch = async () => jsonResponse({
        data: [
            { identity: "357000000000123", created_at: "2026-09-07T10:00:00Z" },
            { identity: "357000000000124", created_at: "2026-09-07T10:00:00Z" },
        ],
        pagination: { limit: 2, page: 1, total_pages: 15, total: 30 },
    });

    await loadSettingsDenylistSection();

    assert.match(els.denylistTabSummary.textContent, /30 aparelhos bloqueados/);
});

test("unblocking calls the API DELETE and removes the row", async () => {
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
    assert.match(els.denylistListBody.innerHTML, /notificação/i);
});
