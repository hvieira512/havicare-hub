import assert from "node:assert/strict";
import test from "node:test";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { renderDeviceConfigurationRoot } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { changedConfigGroupEntries } from "../../src/Dashboard/dashboard/devices/config/panel.js";

/**
 * As dez medições da Wonlex são a mesma decisão dita dez vezes: com que frequência é que o
 * relógio reporta cada grandeza. Em cartões, cada uma repetia a mesma frase de ajuda e
 * levava o seu próprio botão de envio -- dez frases iguais e dez envios para uma decisão.
 *
 * É a única corrida em que unir compensa, e o que a identifica é a forma da definição: o
 * mesmo comando nativo e a mesma legenda. Duas definições de número com comandos diferentes
 * continuam a ser dois cartões, e é isso que a segunda metade deste ficheiro prende.
 */
const AJUDA = "Periodicidade de envio desta medição, em minutos. Use 0 para desativar.";

const interval = (key, label) => ({
    key,
    capabilityKey: key,
    command: "deviceMeasuringFrequency",
    label,
    input: "number",
    fields: ["interval"],
    category: "health",
    help: AJUDA,
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

const INTERVALOS = [
    interval("heart_rate_measurement_interval", "Intervalo de frequência cardíaca"),
    interval("blood_pressure_measurement_interval", "Intervalo de tensão arterial"),
    interval("spo2_measurement_interval", "Intervalo de oxigénio no sangue"),
];

test("as medições repetidas ficam num cartão só", () => {
    const root = render(INTERVALOS);

    assert.equal(root.querySelectorAll("[data-config-group]").length, 1);
    assert.equal(root.querySelectorAll("[data-config-row]").length, 3);
    assert.equal(root.querySelectorAll("[data-config-section]").length, 0);
});

test("a frase de ajuda é dita uma vez, e não uma por medição", () => {
    const ocorrencias = render(INTERVALOS).textContent.split(AJUDA).length - 1;

    assert.equal(ocorrencias, 1);
});

test("um envio para as três, e não três", () => {
    const root = render(INTERVALOS);

    assert.equal(root.querySelectorAll("[data-action=\"saveConfigGroup\"]").length, 1);
    assert.equal(root.querySelectorAll("[data-action=\"saveConfig\"]").length, 0);
});

test("cada linha continua a ter o seu campo e a sua pastilha de entrega", () => {
    const linhas = [...render(INTERVALOS).querySelectorAll("[data-config-row]")];

    for (const linha of linhas) {
        assert.ok(linha.querySelector("input[type=\"number\"]"), "a linha devia ter o campo");
        assert.ok(linha.querySelector(".state-badge"), "a linha devia ter a pastilha");
    }
});

/**
 * A fotografia que cada linha traz tem de bater certo com o que o leitor devolve. Um `"0"`
 * onde o leitor devolve `0` fazia o rodapé contar uma alteração a quem não tinha mexido em
 * nada -- e, pior, mandava ao aparelho o que ele já tinha.
 */
test("só viaja a linha que alguém mexeu", () => {
    // Com valor guardado: sem ele, todas contam como por enviar, que é outra regra e já tem
    // teste próprio.
    const guardadas = Object.fromEntries(
        INTERVALOS.map((entry) => [entry.key, { interval: 0 }]),
    );
    const group = renderWith(INTERVALOS, guardadas).querySelector("[data-config-group]");

    assert.deepEqual(changedConfigGroupEntries(group), {});

    const [primeira] = group.querySelectorAll("[data-config-row] input[type=\"number\"]");
    primeira.value = "15";

    assert.deepEqual(changedConfigGroupEntries(group), {
        heart_rate_measurement_interval: { interval: 15 },
    });
});

/**
 * Num relógio que nunca teve as medições configuradas, o rodapé dizia «10 definições no
 * valor padrão, por enviar» e o botão ficava aceso. O valor padrão de um intervalo é `0`, e
 * `0` desactiva -- um clique desligava as dez medições de uma vez, sem ninguém ter escrito
 * um número. Em cartões, o mesmo enganado custava um clique por medição; em grupo, custa as
 * dez.
 */
test("um grupo de campos nunca enviados não envia nada sem alguém escrever um valor", () => {
    const group = render(INTERVALOS).querySelector("[data-config-group]");

    assert.deepEqual(changedConfigGroupEntries(group), {});

    const [primeira] = group.querySelectorAll("[data-config-row] input[type=\"number\"]");
    primeira.value = "30";

    assert.deepEqual(changedConfigGroupEntries(group), {
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

    assert.equal(Object.keys(changedConfigGroupEntries(group)).length, 2);
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
