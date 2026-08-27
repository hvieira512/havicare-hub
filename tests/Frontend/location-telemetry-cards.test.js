import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos modulos do dashboard: o nome de uma capacidade vem do catalogo, e
// esse caminho passa pelo api/http.js, que toca em window ao carregar.
import "./support/browser-env.js";
import {
    renderRequestCardShell,
    uplinkCardContent,
} from "../../src/Dashboard/dashboard/telemetry-cards.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

state.capabilityCatalogByType.watch = [{ key: "location", label: "Localização" }];
state.selectedDetail = { model: { deviceType: "watch" } };

/**
 * O cartão de localização.
 *
 * As três formas em que um evento `location` chega em produção, verificadas no Redis:
 * resolvido com coordenadas, fixo de rádio que não resolveu, e relatório periódico sem
 * prova nenhuma. O cartão dizia "Atualização de localização" nas três.
 */

// A idade é relativa a agora e não a uma data fixa: o `ago` conta a partir do `Date.now()`.
// Dois minutos dao "há 2m" com quase um minuto de margem em qualquer sentido.
const minutesAgo = (minutes) =>
    new Date(Date.now() - minutes * 60_000).toISOString();

const locationCard = (reports) =>
    renderRequestCardShell(
        { feature: "location", requestable: false },
        false,
        reports.map(({ data, minutes = 2 }) => ({
            type: "location",
            occurredAt: minutesAgo(minutes),
            data,
        })),
    );

const GPS_FIX = {
    source: "gps",
    hasCoordinates: true,
    lat: 41.706841,
    lon: -8.793279,
    accuracyMeters: 5,
    gpsValid: true,
};

// O que o mapa de rádio privado devolve quando resolve um fixo de antenas e WiFi: as
// coordenadas com a origem original, porque o meio pelo qual se tentou não muda.
const RADIO_FIX = {
    source: "cell_wifi",
    hasCoordinates: true,
    lat: 41.706841,
    lon: -8.793279,
    accuracyMeters: 1,
    baseStations: [{ cellId: "194809015" }, { cellId: "194809005" }],
    wifiAccessPoints: [{ mac: "dc:fe:23:b8:31:73" }],
};

const UNRESOLVED = {
    source: "cell_wifi",
    hasCoordinates: false,
    gpsValid: false,
    speedKmh: 0.1,
    baseStations: [{ cellId: "677900", gsmSignal: 60 }],
    wifiAccessPoints: [{ mac: "dc-fe-23-b7-ed-ff" }],
};

// Um `upLocation` periódico com `baseStation: []`: o relógio acordou, tentou e não viu nada.
const NOTHING_SEEN = { hasCoordinates: false, reportKind: "periodic" };

test("com posição, o valor são as coordenadas e os detalhes dizem como e com que precisão", () => {
    const html = locationCard([{ data: GPS_FIX }]);

    // Cinco decimais: ~1 m, e o sexto são dez centímetros num mosaico de 206px.
    assert.match(html, /41\.70684, -8\.79328/);
    assert.match(html, /GPS · ±5 m · há 2m/);
});

test("uma posição resolvida a partir do rádio diz Rádio, e não a origem crua", () => {
    // `cell`, `wifi` e `cell_wifi` são todos triangulação; o que distingue da de GPS é a
    // proveniência, não qual das antenas entrou na conta.
    const html = locationCard([{ data: RADIO_FIX }]);

    assert.match(html, /Rádio · ±1 m · há 2m/);
    assert.doesNotMatch(html, /Cell Wifi/);
});

test("sem posição, o valor é um travessão e os detalhes são a prova de rádio", () => {
    const html = locationCard([{ data: UNRESOLVED }]);

    assert.match(html, /—/);
    assert.match(html, /1 antena · 1 rede WiFi · há 2m/);
    // O que lá estava: jargão sem tradução, um booleano em inglês e a velocidade de um
    // relógio parado sem unidade.
    assert.doesNotMatch(html, /Atualização de localização/);
    assert.doesNotMatch(html, /GPS válido/);
    assert.doesNotMatch(html, /Velocidade/);
});

test("as contagens vão ao plural, e a que for zero não aparece", () => {
    const html = locationCard([
        {
            data: {
                source: "cell",
                baseStations: [{ cellId: "1" }, { cellId: "2" }, { cellId: "3" }],
            },
        },
    ]);

    assert.match(html, /3 antenas · há 2m/);
    assert.doesNotMatch(html, /rede/);
});

test("reportar e não ver nada é outra falha, e diz-se por palavras", () => {
    // Aponta para o aparelho e não para a cobertura: não é "tentou e não resolveu".
    const html = locationCard([{ data: NOTHING_SEEN }]);

    assert.match(html, /Sem dados de rádio · há 2m/);
});

test("um relatório sem posição não apaga a última posição conhecida", () => {
    // Num HW20PRO são 8 de 29 relatórios que não resolvem. Cada um deles substituía uma
    // posição de ±1 m obtida dois minutos antes.
    const html = locationCard([
        { data: UNRESOLVED, minutes: 1 },
        { data: RADIO_FIX, minutes: 14 },
    ]);

    assert.match(html, /41\.70684, -8\.79328/);
    // A idade é a do fixo que se mostra, e não a da tentativa mais recente.
    assert.match(html, /Rádio · ±1 m · há 14m/);
});

test("um dispositivo que nunca resolveu mostra a última tentativa mesmo assim", () => {
    // O VL17: 85 de 85 relatórios sem coordenadas. A hora e a prova de rádio são o que há.
    const html = locationCard([
        { data: UNRESOLVED, minutes: 1 },
        { data: UNRESOLVED, minutes: 2 },
    ]);

    assert.match(html, /1 antena · 1 rede WiFi · há 1m/);
});

test("o par 0,0 não é uma posição", () => {
    // É a forma que os protocolos usam para dizer "sem fixo".
    const content = uplinkCardContent("location", {
        source: "cell",
        lat: 0,
        lon: 0,
        baseStations: [{ cellId: "1" }],
    });

    assert.equal(content.value, "—");
});

test("o lat/lon manda, e não o hasCoordinates", () => {
    // O histórico do Redis guarda cem eventos por dispositivo e os mais antigos são
    // anteriores a esse campo: confiar nele fazia uma posição boa desaparecer.
    const content = uplinkCardContent("location", {
        source: "gps",
        lat: 41.706841,
        lon: -8.793279,
    });

    assert.equal(content.value, "41.70684, -8.79328");
});

test("na lista cronológica não aparece a idade: a hora já tem coluna própria", () => {
    const content = uplinkCardContent("location", UNRESOLVED);

    assert.equal(content.details, "1 antena · 1 rede WiFi");
});

test("um booleano num cartão sai em português", () => {
    // Saía titleizado do inglês: "Queda: False", "Bateria fraca: False".
    const content = uplinkCardContent("alarm", {
        code: "sos",
        fall: false,
        lowBattery: true,
    });

    assert.match(content.details, /Queda: Não/);
    assert.match(content.details, /Bateria fraca: Sim/);
});
