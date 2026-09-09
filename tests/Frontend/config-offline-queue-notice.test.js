import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o `api/http.js` toca em `window` ao carregar.
import "./support/browser-env.js";
import { offlineQueueNotice } from "../../src/Dashboard/dashboard/devices/config/index.js";

/**
 * O relógio auditado estava desligado e sem registo de ligação, e o painel apresentou os 28
 * blocos e os «Enviar» exactamente como para um aparelho ligado. A entrega a aparelhos
 * intermitentes é uma fila com prazo -- o `DOWNLINK_QUEUE_TTL_SECONDS` --, e nada disso
 * estava no ecrã.
 */
test("um aparelho ligado não leva aviso nenhum", () => {
    assert.equal(offlineQueueNotice(true, 300), "");
});

test("desligado, o aviso diz a fila e o prazo", () => {
    const notice = offlineQueueNotice(false, 300);

    assert.match(notice, /fila/);
    assert.match(notice, /5 minutos/);
});

test("o prazo lê-se na unidade em que é redondo", () => {
    assert.match(offlineQueueNotice(false, 60), /1 minuto\b/);
    assert.match(offlineQueueNotice(false, 90), /90 segundos/);
    assert.match(offlineQueueNotice(false, 3600), /1 hora\b/);
    assert.match(offlineQueueNotice(false, 7200), /2 horas/);
});

test("sem prazo conhecido, fala da fila e não inventa um número", () => {
    const notice = offlineQueueNotice(false, 0);

    assert.match(notice, /fila/);
    assert.doesNotMatch(notice, /\d/);
});
