import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";

const {
    cardGrid,
    deviceTypeCardsHtml,
    modelCardsHtml,
    ownerFromLicense,
    supplierPillsHtml,
    wizardTrailHtml,
} = await import("../../src/Dashboard/dashboard/devices/classification-ui.js");

/**
 * O desenho da classificacao -- trilha, tipo, fornecedor, modelo -- que o assistente de
 * adicionar e o modal de editar partilham. Sao construtores de HTML puros: testam-se pelo
 * que produzem, e não por como o produzem.
 */

const TRAIL_QUESTIONS = [
    { key: "type", label: "Tipo" },
    { key: "model", label: "Modelo" },
    { key: "owner", label: "Licença" },
];
const STEPS = ["Classificação", "Este aparelho"];

function trail(badges, currentKey = "", step = 1) {
    return parseFragment(
        wizardTrailHtml({ questions: TRAIL_QUESTIONS, badges, currentKey, step, steps: STEPS }),
    );
}

test("uma resposta na trilha é um botão que volta àquela pergunta", () => {
    // Cada badge volta à sua pergunta, o que evita refazer tudo o que
    // vinha depois para se voltar ao tipo.
    const root = trail([{ key: "type", label: "Tipo", value: "Relógio" }], "model");

    const answered = root.querySelector("[data-wizard-reopen]");
    assert.equal(answered.tagName, "BUTTON");
    assert.equal(answered.dataset.wizardReopen, "type");
    assert.equal(answered.querySelector(".wizard-badge-key").textContent, "Tipo");
    assert.match(answered.textContent, /Relógio/);
});

test("as perguntas por responder ficam na trilha, e a activa distingue-se", () => {
    // Mostrar só as respondidas deixava a linha vazia ao abrir e não dizia quanto faltava.
    const root = trail([], "type");

    assert.equal(root.querySelectorAll(".wizard-badge").length, 3);
    assert.equal(root.querySelectorAll(".wizard-badge-now").length, 1);
    assert.equal(
        root.querySelector(".wizard-badge-now").textContent.trim(),
        "Tipo",
    );
    assert.equal(root.querySelectorAll(".wizard-badge-pending").length, 2);
    // Nenhuma delas é clicável: não há resposta para onde voltar.
    assert.equal(root.querySelectorAll("[data-wizard-reopen]").length, 0);
});

test("a trilha diz em que passo se está", () => {
    assert.equal(
        trail([], "", 2).querySelector(".wizard-trail-step").textContent,
        "Passo 2 de 2 · Este aparelho",
    );
});

test("o card escolhido fica marcado, e é o único", () => {
    const root = parseFragment(
        cardGrid("Escolha", [
            { attrs: "data-x=\"a\"", label: "A", selected: false },
            { attrs: "data-x=\"b\"", label: "B", selected: true },
        ]),
    );

    const cards = [...root.querySelectorAll(".wizard-card")];
    assert.deepEqual(cards.map((c) => c.classList.contains("selected")), [false, true]);
    assert.equal(cards[1].getAttribute("aria-pressed"), "true");
});

test("os tipos de dispositivo saem todos, com o número de modelos de cada um", () => {
    const root = parseFragment(
        deviceTypeCardsHtml({
            attrsFor: (value) => `data-type="${value}"`,
            selected: "gateway",
            countFor: (value) => (value === "watch" ? 1 : 4),
        }),
    );

    const cards = [...root.querySelectorAll(".wizard-card")];
    assert.equal(cards.length > 0, true);
    assert.equal(root.querySelector("[data-type=\"gateway\"]").classList.contains("selected"), true);
    // Singular e plural: "1 modelos" seria o descuido que se vê num ecrã real.
    assert.equal(
        root.querySelector("[data-type=\"watch\"] .wizard-card-sub").textContent,
        "1 modelo",
    );
    assert.equal(
        root.querySelector("[data-type=\"gateway\"] .wizard-card-sub").textContent,
        "4 modelos",
    );
});

test("sem contagem não sai subtítulo nenhum: no modal de edição não há nada para contar", () => {
    const root = parseFragment(
        deviceTypeCardsHtml({ attrsFor: (value) => `data-type="${value}"`, selected: "watch" }),
    );

    assert.equal(root.querySelector(".wizard-card-sub"), null);
});

test("o card do modelo leva fotografia, o nome comercial e o modelo interno", () => {
    const models = [
        { supplier: "4P Touch", internalModel: "Y6S", commercialName: "R03", image: "/img/r03.png" },
        // Comercial igual ao interno: escrevê-lo duas vezes não acrescenta nada.
        { supplier: "4P Touch", internalModel: "D41", commercialName: "D41", image: "" },
    ];
    const root = parseFragment(
        modelCardsHtml({ models, attrsFor: (internal) => `data-model="${internal}"`, selected: "D41" }),
    );

    const first = root.querySelector("[data-model=\"Y6S\"]");
    assert.notEqual(first.querySelector(".wizard-card-thumb img"), null);
    assert.equal(first.querySelector(".wizard-card-label").textContent, "R03");
    assert.equal(first.querySelector(".wizard-card-sub").textContent, "Y6S");

    const second = root.querySelector("[data-model=\"D41\"]");
    assert.equal(second.querySelector(".wizard-card-sub"), null);
    assert.equal(second.classList.contains("selected"), true);
});

test("o fornecedor escolhido é a pastilha cheia", () => {
    const root = parseFragment(
        supplierPillsHtml({
            suppliers: ["4P Touch", "Wonlex"],
            selected: "Wonlex",
            attrsFor: (name) => `data-supplier="${name}"`,
        }),
    );

    assert.equal(root.querySelector("[data-supplier=\"Wonlex\"]").classList.contains("btn-primary"), true);
    assert.equal(root.querySelector("[data-supplier=\"Wonlex\"]").getAttribute("aria-pressed"), "true");
    assert.equal(
        root.querySelector("[data-supplier=\"4P Touch\"]").classList.contains("btn-outline-secondary"),
        true,
    );
});

/**
 * A licença que uma notificação traz, resolvida na árvore.
 *
 * O radar publica em `radar/{licenseId}/{uid}`, por isso o hub sabe a licença de um radar
 * que ainda não está registado e a notificação leva-a. O assistente pré-selecciona-a -- mas
 * a escolha é o par empresa+licença, e o número sozinho pode não chegar.
 */
test("a licença da notificação ganha a empresa a que pertence", () => {
    const tree = [
        { company: "havicare", licenses: [{ licenseId: "1", name: "hc.dev" }] },
        { company: "hitcare", licenses: [{ licenseId: "1001", name: "gucc.dev" }, { licenseId: "2103", name: "" }] },
    ];

    assert.deepEqual(ownerFromLicense(2103, tree), { company: "hitcare", licenseId: "2103" });
    assert.deepEqual(ownerFromLicense("1001", tree), { company: "hitcare", licenseId: "1001" });
});

test("uma licença que não existe na árvore não pré-seleciona nada", () => {
    const tree = [{ company: "hitcare", licenses: [{ licenseId: "1001", name: "gucc.dev" }] }];

    // A 2051 existe no broker e não na base de dados: ninguém a criou ainda.
    assert.equal(ownerFromLicense(2051, tree), null);
    assert.equal(ownerFromLicense(0, tree), null);
    assert.equal(ownerFromLicense(null, tree), null);
});

test("o mesmo número em duas empresas fica por escolher", () => {
    const tree = [
        { company: "havicare", licenses: [{ licenseId: "22", name: "hc.simplificado" }] },
        { company: "hitcare", licenses: [{ licenseId: "22", name: "outra" }] },
    ];

    // Escolher mal aqui poe o dispositivo na empresa errada sem ninguem reparar.
    assert.equal(ownerFromLicense(22, tree), null);
});

/**
 * O par é o que desempata, e é por isso que a notificação passou a guardar a empresa: sem
 * ela o número sozinho desiste, e o assistente deixava de pré-seleccionar no dia em que dois
 * clientes tivessem o mesmo número.
 */
test("a empresa da notificação desempata o número repetido", () => {
    const tree = [
        { company: "havicare", licenses: [{ licenseId: "1001", name: "hc" }] },
        { company: "hitcare", licenses: [{ licenseId: "1001", name: "gucc.dev" }] },
    ];

    assert.equal(ownerFromLicense(1001, tree), null);
    assert.deepEqual(
        ownerFromLicense(1001, tree, "hitcare"),
        { company: "hitcare", licenseId: "1001" },
    );
    assert.deepEqual(
        ownerFromLicense(1001, tree, "havicare"),
        { company: "havicare", licenseId: "1001" },
    );
    // A grafia do tópico não tem de ser a da árvore.
    assert.deepEqual(
        ownerFromLicense(1001, tree, "HitCare"),
        { company: "hitcare", licenseId: "1001" },
    );
    // Uma empresa que não está na árvore não inventa um dono.
    assert.equal(ownerFromLicense(1001, tree, "terceira"), null);
});
