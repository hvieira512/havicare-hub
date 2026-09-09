import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { renderConfigSection } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configActionPayload } from "../../src/Dashboard/dashboard/devices/config/panel.js";
import { parseFragment } from "./support/dom.js";

/**
 * Uma acção com dois sentidos mostra os dois verbos.
 *
 * O «Encontrar dispositivo» estava desenhado como um interruptor que se guarda, e não é: a
 * pulseira vibra no instante e desiste sozinha ao fim de um minuto. Para a parar era preciso
 * adivinhar que se desligava o interruptor e se carregava em Enviar outra vez -- ninguém
 * descobre isso, e foi a primeira pergunta que apareceu ao usá-lo.
 */
const ACTION = {
    key: "find_device",
    capabilityKey: "find_device",
    command: "config:find_device",
    label: "Encontrar dispositivo",
    input: "toggle",
    fields: ["enabled"],
    transient: true,
    help: "Faz a pulseira vibrar. Pára sozinha ao fim de cerca de um minuto.",
    actions: { on: "Fazer vibrar", off: "Parar" },
};

const root = (entry) => parseFragment(renderConfigSection("veepoo-ble", entry, null)).firstElementChild;
const buttons = (el) => [...el.querySelectorAll("[data-action=\"saveConfig\"]")]
    .map((b) => ({ texto: b.textContent.trim(), valor: b.dataset.actionValue }));

test("os dois verbos aparecem como botões", () => {
    const acoes = buttons(root(ACTION));

    assert.deepEqual(acoes, [
        { texto: "Fazer vibrar", valor: "on" },
        { texto: "Parar", valor: "off" },
    ]);
});

test("não há interruptor a fingir que a acção tem estado guardado", () => {
    assert.equal(root(ACTION).querySelector("input[type=\"checkbox\"]"), null);
});

/** Uma acção sem verbos declarados continua a ser o que era. */
test("sem verbos declarados, o cartão não muda", () => {
    const semVerbos = { ...ACTION };
    delete semVerbos.actions;
    const el = root(semVerbos);

    assert.ok(el.querySelector("input[type=\"checkbox\"]"), "devia continuar a ter o interruptor");
    assert.deepEqual(buttons(el).map((b) => b.valor), [undefined]);
});

/**
 * O verbo traz o valor consigo.
 *
 * Um botão que diz «Parar» não tem formulário para ler -- o que vai enviar está no próprio
 * botão. Sem isto, os dois verbos enviavam o mesmo e a ordem de parar era indistinguível da
 * de começar, que foi exactamente o que aconteceu quando o valor não viajava.
 */
test("cada verbo envia o seu valor", () => {
    const seccao = parseFragment(
        "<section data-config-section data-config-action-field=\"enabled\"></section>",
    ).firstElementChild;

    assert.deepEqual(configActionPayload(seccao, "on"), { enabled: true });
    assert.deepEqual(configActionPayload(seccao, "off"), { enabled: false });
});

/** O «Enviar» normal continua a ler os campos do cartão. */
test("sem verbo, o valor vem do formulário como sempre", () => {
    const seccao = parseFragment("<section data-config-section></section>").firstElementChild;

    assert.equal(configActionPayload(seccao, ""), null);
});
