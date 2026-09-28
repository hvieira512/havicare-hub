import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { handleSettingsPaginationClick } from "../../src/Dashboard/dashboard/settings/shell.js";
import { renderPagination } from "../../src/Dashboard/dashboard/pagination.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

/** O contentor e os controlos aninhados, como o `pagination_component()` os desenha. */
function mountPager(prefix) {
    const root = document.createElement("div");
    root.innerHTML = `<div><ul id="${prefix}Controls"></ul></div>`;
    document.body.appendChild(root);
    return { root, controls: root.querySelector("ul") };
}

/** O ouvinte está no contentor e o clique num número borbulha até ele: resolve-se uma vez. */
test("um clique no paginador das definições pede uma página só", () => {
    const { root, controls } = mountPager("settingsApiUsersPagination");
    const pagination = { page: 1, total_pages: 4, total: 40, limit: 10 };
    state.settingsModal.apiUsersPagination = pagination;

    renderPagination({
        pagination,
        rootEl: root,
        summaryEl: null,
        controlsEl: controls,
        actionPrefix: "settingsApiUsersPage",
    });

    const loaded = [];
    root.addEventListener("click", (event) =>
        handleSettingsPaginationClick(event, "apiUsersPagination", (page) => loaded.push(page)),
    );

    controls
        .querySelector("[data-action=\"settingsApiUsersPageGo\"][data-page=\"3\"]")
        .dispatchEvent(new window.Event("click", { bubbles: true }));

    assert.deepEqual(loaded, [3]);
});
