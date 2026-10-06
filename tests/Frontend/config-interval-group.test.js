import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { changedConfigEntries } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * As dez medições da Wonlex são a mesma decisão dita dez vezes e juntam-se num grupo; o que as
 * identifica é o mesmo comando nativo e a mesma legenda, e sem isso ficam cartões.
 */
const HELP = "Periodicidade de envio desta medição, em minutos. Use 0 para desativar.";

const interval = (key, label) => ({
    key,
    capabilityKey: key,
    command: "deviceMeasuringFrequency",
    label,
    input: "number",
    fields: ["interval"],
    category: "health",
    help: HELP,
});

const CAPABILITIES = (keys) => keys.map((key) => ({
    key,
    deviceType: "watch",
    section: "health",
    sectionLabel: "Saúde",
    isConfigurable: true,
    isRequestable: false,
}));

const renderWith = (catalog, configurations) => parseFragment(renderDeviceConfigurationRoot({
    protocol: "wonlex-json",
    catalog,
    capabilityCatalog: CAPABILITIES(catalog.map((entry) => entry.capabilityKey)),
    configurations,
    configurationSync: { entries: {} },
    capabilities: {},
    uiByKey: {},
    actionDeliveries: {},
    activeCategory: "health",
    online: true,
}));

const render = (catalog) => renderWith(catalog, {});

const INTERVALS = [
    interval("heart_rate_measurement_interval", "Intervalo de frequência cardíaca"),
    interval("blood_pressure_measurement_interval", "Intervalo de tensão arterial"),
    interval("spo2_measurement_interval", "Intervalo de oxigénio no sangue"),
];

test("as medições repetidas ficam num cartão só", () => {
    const root = render(INTERVALS);

    assert.equal(root.querySelectorAll("[data-config-group]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-row]").length, 3);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 0);
});

test("sem legenda as medições continuam num cartão só", () => {
    const root = render(INTERVALS.map((entry) => ({ ...entry, help: "" })));

    assert.equal(root.querySelectorAll("[data-config-group]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-row]").length, 3);
});

test("a frase de ajuda é dita uma vez, e não uma por medição", () => {
    const occurrences = render(INTERVALS).textContent.split(HELP).length - 1;

    assert.equal(occurrences, 1);
});

test("um envio para as três, e não três", () => {
    const root = render(INTERVALS);

    assert.equal(root.querySelectorAll("[data-action=\"saveConfigPane\"]").length, 1);
    assert.equal(root.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});

test("cada linha continua a ter o seu campo e a sua pastilha de entrega", () => {
    const rows = [...render(INTERVALS).querySelectorAll("[data-config-row]")];

    for (const row of rows) {
        assert.ok(row.querySelector("input[type=\"number\"]"), "a linha devia ter o campo");
        assert.ok(row.querySelector(".state-badge"), "a linha devia ter a pastilha");
    }
});

/**
 * A fotografia de cada linha tem de bater certo com o que o leitor devolve: um `"0"` contra um
 * `0` conta uma alteração que ninguém fez e manda ao aparelho o que ele já tem.
 */
test("só viaja a linha que alguém mexeu", () => {
    // Com valor guardado: sem ele, todas contam como por enviar, que é outra regra e já tem
    // teste próprio.
    const stored = Object.fromEntries(
        INTERVALS.map((entry) => [entry.key, { interval: 0 }]),
    );
    const group = renderWith(INTERVALS, stored).querySelector("[data-config-group]");

    assert.deepEqual(changedConfigEntries(group), {});

    const [firstRow] = group.querySelectorAll("[data-config-row] input[type=\"number\"]");
    firstRow.value = "15";

    assert.deepEqual(changedConfigEntries(group), {
        heart_rate_measurement_interval: { interval: 15 },
    });
});

/**
 * O padrão de um intervalo é `0`, e `0` desactiva: enviar o grupo sem ninguém escrever
 * desligaria as dez medições de uma vez.
 */
test("um grupo de campos nunca enviados não envia nada sem alguém escrever um valor", () => {
    const group = render(INTERVALS).querySelector("[data-config-group]");

    assert.deepEqual(changedConfigEntries(group), {});

    const [firstRow] = group.querySelectorAll("[data-config-row] input[type=\"number\"]");
    firstRow.value = "30";

    assert.deepEqual(changedConfigEntries(group), {
        heart_rate_measurement_interval: { interval: 30 },
    });
});

test("um grupo de interruptores nunca enviados continua a poder ser enviado", () => {
    // O padrão de um interruptor é uma escolha a sério -- ligado --, e o grupo é o único
    // caminho para a primeira gravação. É a diferença que justifica as duas regras.
    const toggles = ["fall_detection", "sos_sms"].map((key) => ({
        key,
        capabilityKey: key,
        command: "deviceConfig",
        label: key,
        input: "toggle",
        fields: ["switchState"],
        category: "health",
    }));

    const group = render(toggles).querySelector("[data-config-group]");

    assert.equal(Object.keys(changedConfigEntries(group)).length, 2);
});

test("números de comandos diferentes continuam a ser cartões separados", () => {
    const root = render([
        interval("heart_rate_measurement_interval", "Intervalo de frequência cardíaca"),
        {
            ...interval("step_goal", "Meta de passos"),
            command: "deviceConfig",
            fields: ["steps"],
            help: "",
        },
    ]);

    // Nem se juntam num grupo, nem o que fica sozinho ganha um grupo só para ele: um campo
    // único já cabe numa linha sem cabeçalho nem rodapé.
    assert.equal(root.querySelectorAll("[data-config-group]").length, 0);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 2);
});
