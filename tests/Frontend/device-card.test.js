import test from "node:test";
import assert from "node:assert/strict";
import "./support/browser-env.js";

import {
    DEVICE_CARD_ACTION,
    deviceCard,
    deviceCardSkeletonList,
} from "../../src/Dashboard/dashboard/devices/device-card.js";

/**
 * O cartão saiu do meio do `list.js` para um módulo seu, e isto é metade do porquê: passou a
 * poder ser exercitado sem montar a lista, o modal e o estado à volta dele.
 */

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
    assert.match(deviceCard(device, true), /class="device-card selected/);
    assert.ok(!deviceCard(device, false).includes("aria-current"));
});

test("um dispositivo desligado leva a sua classe", () => {
    assert.match(deviceCard({ ...device, online: false }, false), /device-card[^"]* offline/);
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

test("o esqueleto não passa do tecto de linhas", () => {
    const rows = (markup) => markup.split("device-card-skeleton\"").length - 1;

    assert.equal(rows(deviceCardSkeletonList(5)), 5);
    // Doze é o tecto: a moldura mais alta não mostra mais do que isso.
    assert.equal(rows(deviceCardSkeletonList(50)), 12);
});

test("o esqueleto usa as classes do cartão a sério, para a lista não saltar", () => {
    const skeleton = deviceCardSkeletonList(1);

    for (const cls of ["device-card", "device-card-thumb", "device-card-identity", "device-card-fields"]) {
        assert.ok(skeleton.includes(cls), `o esqueleto devia usar a classe ${cls}`);
    }
});
