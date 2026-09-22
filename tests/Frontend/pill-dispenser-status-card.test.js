import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * O conteúdo do cartão de estado do dispensador.
 *
 * Sem renderizador próprio, o `device_status` caía no genérico, que mostra os quatro
 * primeiros campos e cala o resto. O dispensador traz oito: o sinal e o bloqueio de criança
 * enchiam a quota, e a tampa, a corrente, o alarme de ambiente e o CCID do SIM — que foi o
 * trabalho todo de os ler — nunca chegavam ao ecrã.
 */
test("a tampa, a corrente, o ambiente e o SIM aparecem nos detalhes", () => {
    const { details } = uplinkCardContent("device_status", {
        gsmSignalDbm: -24,
        signalLevel: 3,
        childLockEngaged: true,
        lidOpen: false,
        mainsPowered: true,
        environmentAlarm: false,
        simCcid: "8935103211501958977",
    });

    assert.match(details, /Tampa aberta/);
    assert.match(details, /Ligado à corrente/);
    assert.match(details, /Ambiente fora da gama/);
    assert.match(details, /CCID do SIM/);
});

/** O que se lê de relance é o sinal. */
test("o valor principal é o sinal em dBm", () => {
    assert.equal(
        uplinkCardContent("device_status", { gsmSignalDbm: -24, signalLevel: 3 }).value,
        "-24 dBm",
    );
});

/** Sem leitura fina, a escala grosseira serve — e diz que escala é. */
test("sem dBm fica a contagem de barras", () => {
    assert.equal(
        uplinkCardContent("device_status", { signalLevel: 2 }).value,
        "2 de 3",
    );
});

/** Um campo que o aparelho não reportou não aparece inventado. */
test("o que não veio não se desenha", () => {
    const { details } = uplinkCardContent("device_status", { signalLevel: 2 });

    assert.doesNotMatch(details, /Tampa/);
    assert.doesNotMatch(details, /CCID/);
});

/** `false` é um valor e não uma ausência: a tampa fechada tem de aparecer. */
test("um valor falso continua a ser um valor", () => {
    const { details } = uplinkCardContent("device_status", { lidOpen: false });

    assert.match(details, /Tampa aberta/);
});
