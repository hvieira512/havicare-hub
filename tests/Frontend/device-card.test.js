import test from "node:test";
import assert from "node:assert/strict";

import {
    DEVICE_CARD_ACTION,
    deviceCard as buildCard,
} from "../../src/Dashboard/dashboard/devices/device-card.js";
import { asText } from "./support/dom.js";

const deviceCard = asText(buildCard);

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

test("the card emits the action the delegated listener looks for", () => {
    const markup = deviceCard(device, false);

    assert.match(markup, new RegExp(`data-action="${DEVICE_CARD_ACTION}"`));
    assert.match(markup, /data-imei="861265061009822"/);
});

test("the chosen card is marked for screen readers and for the eye", () => {
    assert.match(deviceCard(device, true), /aria-current="true"/);

    assert.ok(!deviceCard(device, false).includes("aria-current"));
});

test("a device field with markup comes out inert", () => {
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
test("the card says when the device last spoke", () => {
    const markup = deviceCard(
        { ...device, online: false, lastSeenAt: new Date(Date.now() - 3 * 3600 * 1000).toISOString() },
        false,
    );

    assert.match(markup, /device-card-when/);
    assert.match(markup, /há 3 h/);
});

test("a device that never spoke says so instead of a date that does not exist", () => {
    const markup = deviceCard({ ...device, online: false, lastSeenAt: null }, false);

    assert.match(markup, /sem registo/i);
    // O `ago()` devolveria «nunca» para um valor vazio, que se lê como um facto sobre o
    // aparelho e não sobre o que o hub sabe dele.
    assert.doesNotMatch(markup, />nunca</);
});
