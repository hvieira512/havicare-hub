import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { notificationRow } from "../../src/Dashboard/dashboard/notifications.js";

/**
 * O vermelho repetia-se em cada linha das listagens.
 *
 * Oito lixos vermelhos ao mesmo tempo no separador «Licenças» faziam do que se faz raramente
 * o elemento mais chamativo do painel, ao lado dos lápis neutros do que se faz muitas vezes.
 * A repetição também gasta o significado da cor: onde tudo é vermelho, nada é.
 *
 * A regra é o `btn-quiet-danger`: neutro em repouso, vermelho ao ser apontado ou focado. Vale
 * para o destrutivo que se repete por linha, e não para o que aparece uma vez -- o «Eliminar»
 * do diálogo do dispositivo e o «Apagar modelo» continuam vermelhos, porque não competem com
 * cópias de si próprios.
 */
const css = readFileSync(
    fileURLToPath(new URL("../../src/Dashboard/assets/css/base.css", import.meta.url)),
    "utf8",
);

test("a regra existe e só muda o repouso", () => {
    const rule = css.slice(css.indexOf(".btn-quiet-danger"));
    const block = rule.slice(rule.indexOf("{"), rule.indexOf("}"));

    assert.match(block, /--bs-btn-color/);
    assert.match(block, /--bs-btn-border-color/);
    // O passar o rato, o foco e o activo continuam a ser os do `btn-outline-danger`.
    assert.doesNotMatch(block, /--bs-btn-hover|--bs-btn-active|--bs-btn-focus/);
});

test("o bloquear de uma notificação é destrutivo repetido, e fica quieto", () => {
    const markup = notificationRow({
        id: 7,
        type: "device_not_authorized",
        imei: "351266770073676",
        occurrenceCount: 1,
    });
    const block = markup.split("<button").find((chunk) => chunk.includes("data-notification-block"));

    assert.match(block, /btn-outline-danger/);
    assert.match(block, /btn-quiet-danger/);
});
