import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: eles tocam em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";

const battery = (data) => uplinkCardContent("battery", data);

/** O ícone era uma constante: a mesma bateria a três quartos aos 100% e aos 4%. */
test("o ícone segue a carga", () => {
    assert.equal(battery({ percent: 100 }).icon, "fa-battery-full");
    assert.equal(battery({ percent: 80 }).icon, "fa-battery-three-quarters");
    assert.equal(battery({ percent: 55 }).icon, "fa-battery-half");
    assert.equal(battery({ percent: 30 }).icon, "fa-battery-quarter");
    assert.equal(battery({ percent: 4 }).icon, "fa-battery-empty");
});

test("sem percentagem o ícone não inventa um nível", () => {
    assert.equal(battery({ voltageMv: 3700 }).icon, "fa-battery-three-quarters");
});

test("a carga baixa tinge o azulejo, e a restante não", () => {
    assert.equal(battery({ percent: 80 }).tone, "success");
    assert.equal(battery({ percent: 20 }).tone, "warning");
    assert.equal(battery({ percent: 9 }).tone, "danger");
});

/** Os relógios mandam um bit e o dispensador manda uma enumeração; a pergunta é a mesma. */
test("a carregar põe o relâmpago no canto, nas duas formas", () => {
    assert.equal(battery({ percent: 40, chargingState: 1 }).iconBadge, "fa-bolt");
    assert.equal(battery({ percent: 40, chargingState: "charging" }).iconBadge, "fa-bolt");
});

test("quem não está a carregar não leva relâmpago", () => {
    assert.equal(battery({ percent: 40, chargingState: 0 }).iconBadge, "");
    assert.equal(battery({ percent: 100, chargingState: "full" }).iconBadge, "");
    assert.equal(battery({ percent: 40 }).iconBadge, "");
});

/** O ícone passou a dizê-lo, e repeti-lo por baixo era ocupar a linha com o mesmo. */
test("o estado de carga sai da linha de detalhes", () => {
    assert.equal(String(battery({ percent: 100, chargingState: "full" }).details), "");
    assert.equal(String(battery({ percent: 40, chargingState: "charging" }).details), "");
    assert.equal(String(battery({ percent: 40, chargingState: 1 }).details), "");
});

/** O que o ícone não sabe dizer fica: não há bateria rasurada no Font Awesome free. */
test("mas «sem bateria» fica, que nenhum ícone o diz", () => {
    assert.match(String(battery({ chargingState: "absent" }).details), /Sem bateria/);
});

/** A corrente passou para o canto do ícone: a linha de baixo não a repete em estado nenhum. */
test("a corrente não se escreve por baixo", () => {
    for (const data of [
        { percent: 99, chargingState: "charging", mainsPowered: true },
        { percent: 100, chargingState: "full", mainsPowered: true },
        { percent: 80, mainsPowered: true },
        { percent: 92, chargingState: "full", mainsPowered: false },
    ]) {
        assert.doesNotMatch(String(battery(data).details), /corrente/i);
    }
});

/** Cheio na ficha não carrega, e sem marca ficava igual a um aparelho fora da ficha. */
test("na ficha sem carregar, a tomada ocupa o canto", () => {
    assert.equal(battery({ percent: 100, chargingState: "full", mainsPowered: true }).iconBadge, "fa-plug");
    assert.equal(battery({ percent: 80, mainsPowered: true }).iconBadge, "fa-plug");
});

/** A carregar manda sobre a ficha: são a mesma corrente, e o relâmpago diz mais. */
test("a carregar, o relâmpago ganha à tomada", () => {
    assert.equal(battery({ percent: 99, chargingState: "charging", mainsPowered: true }).iconBadge, "fa-bolt");
});

test("fora da ficha o canto fica vazio", () => {
    assert.equal(battery({ percent: 92, chargingState: "full", mainsPowered: false }).iconBadge, "");
    assert.equal(battery({ percent: 92, chargingState: "full" }).iconBadge, "");
});

/** Um fragmento vazio é um objecto, e um objecto é verdadeiro: a linha ficava lá, vazia. */
test("sem detalhes o cartão não abre linha nenhuma", () => {
    const content = battery({ percent: 100, chargingState: "full" });
    const card = String(telemetryCard({
        icon: content.icon,
        title: "Bateria",
        value: "100%",
        details: content.details,
    }));

    assert.doesNotMatch(card, /telemetry-row-details/);
});

/** O `compactDetails` devolve um fragmento mesmo quando está vazio, e o filtro deixava-o passar. */
test("uma peça vazia não deixa o separador pendurado", () => {
    assert.equal(String(battery({ percent: 80, mainsPowered: true }).details), "");
});

test("o relâmpago chega ao cartão desenhado", () => {
    const content = battery({ percent: 40, chargingState: "charging" });
    const root = parseFragment(telemetryCard({
        icon: content.icon,
        badge: content.iconBadge,
        title: "Bateria",
        value: "40%",
    }));

    assert.ok(root.querySelector(".telemetry-card-badge .fa-bolt"));
});

test("e sem ele o cartão não ganha marca nenhuma", () => {
    const root = parseFragment(telemetryCard({ icon: "fa-battery-full", title: "Bateria" }));

    assert.equal(root.querySelector(".telemetry-card-badge"), null);
});
