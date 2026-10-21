import test from "node:test";
import assert from "node:assert/strict";

import {
    DEVICE_CARD_ACTION,
    deviceCard as buildCard,
} from "../../src/Dashboard/dashboard/devices/device-card.js";

// Os construtores devolvem um fragmento de marcação; as assertivas de texto querem texto.
const deviceCard = (...args) => String(buildCard(...args));

const device = {
    imei: "861265061009822",
    deviceType: "watch",
    supplier: "Vivistar",
    model: "L08 Pro",
    simNumber: "+351912345678",
    company: "hitcare",
    licenseId: 2103,
    online: true,
};

test("o cartão emite a acção que o ouvinte delegado procura", () => {
    const markup = deviceCard(device, false);

    assert.match(markup, new RegExp(`data-action="${DEVICE_CARD_ACTION}"`));
    assert.match(markup, /data-imei="861265061009822"/);
});

test("o cartão escolhido marca-se para o leitor de ecrã e para o olho", () => {
    assert.match(deviceCard(device, true), /aria-current="true"/);

    assert.ok(!deviceCard(device, false).includes("aria-current"));
});

test("um campo do dispositivo com marcação sai inerte", () => {
    const hostile = {
        ...device,
        model: "<img src=x onerror=alert(1)>",
        simNumber: "\"><script>alert(1)</script>",
    };
    const container = document.createElement("div");
    container.innerHTML = deviceCard(hostile, false);

    assert.equal(container.querySelectorAll("script, img[onerror]").length, 0);
    // O texto sobrevive; o que não sobrevive é ser marcação.
    assert.match(container.textContent, /onerror=alert\(1\)/);
});

/** A pastilha diz se está a falar agora; a linha por baixo diz desde quando. */
test("o cartão diz quando o aparelho falou pela última vez", () => {
    const markup = deviceCard(
        { ...device, online: false, lastSeenAt: new Date(Date.now() - 3 * 3600 * 1000).toISOString() },
        false,
    );

    assert.match(markup, /device-card-when/);
    assert.match(markup, /há 3h/);
});

test("um aparelho que nunca falou di-lo, em vez de uma data que não existe", () => {
    const markup = deviceCard({ ...device, online: false, lastSeenAt: null }, false);

    assert.match(markup, /sem registo/i);
    // O `ago()` devolveria «nunca» para um valor vazio, que se lê como um facto sobre o
    // aparelho e não sobre o que o hub sabe dele.
    assert.doesNotMatch(markup, />nunca</);
});
