import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { deviceTypeFields } from "../../src/Dashboard/dashboard/domain.js";

/**
 * A moldura do assistente de adicionar um dispositivo. O motor está no `wizard.test.js` e o
 * desenho da classificação no `classification-ui.test.js`; aqui prende-se o markup, que é a
 * única parte que nenhum dos dois vê.
 */

const WIZARD = readFileSync(
    new URL("../../src/Dashboard/components/modals/device-wizard.php", import.meta.url),
    "utf8",
);

test("o corpo do assistente é vazio, porque é desenhado a partir da pergunta", () => {
    // Se isto crescer, é sinal de que voltaram campos estáticos para o markup e que a
    // revelação progressiva passou a ser esconder e mostrar.
    assert.deepEqual(WIZARD.match(/<input|<select/g), null);

    // Sem separador de configurações: um dispositivo por criar não pode ter configuração
    // guardada, porque a tabela tem chave estrangeira para a whitelist.
    assert.doesNotMatch(WIZARD, /nav-link|tab-pane/);
});

test("o passo 2 de cada tipo vem da tabela e não do assistente", () => {
    assert.equal(deviceTypeFields("watch").sim, true);
    assert.equal(deviceTypeFields("diaper_sensor").gatewayLinks, true);
    assert.equal(deviceTypeFields("radar").sim, false);
});

test("os cards do assistente respeitam quem pediu menos movimento", () => {
    const css = readFileSync(
        new URL("../../src/Dashboard/main.css", import.meta.url),
        "utf8",
    );

    assert.match(css, /prefers-reduced-motion[\s\S]*?\.wizard-card \{ transition: none/);
});
