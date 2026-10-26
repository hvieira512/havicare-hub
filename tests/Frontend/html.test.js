import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import { html, raw } from "../../src/Dashboard/dashboard/core/html.js";
import { field } from "../../src/Dashboard/dashboard/components/form-field.js";
import { deviceLicenseBlock } from "../../src/Dashboard/dashboard/devices/device-license.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";
import { compactDetails, joinMarkup } from "../../src/Dashboard/dashboard/components/cards/shared.js";
import { apiUserForm } from "../../src/Dashboard/dashboard/settings/api-users.js";
import { state } from "../../src/Dashboard/dashboard/core/state.js";

/** O que se afirma é a marcação, e o `html` devolve um fragmento: comparar texto com texto. */
const markup = (value) => String(value);

test("every interpolation is escaped without anyone calling esc()", () => {
    assert.equal(
        markup(html`<p>${"<script>alert(1)</script>"}</p>`),
        "<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>",
    );
    assert.equal(
        markup(html`<i title="${"\" onerror=\"alert(1)"}"></i>`),
        "<i title=\"&quot; onerror=&quot;alert(1)\"></i>",
    );
});

test("raw() lets an already built fragment through", () => {
    assert.equal(markup(html`<p>${raw("<b>a</b>")}</p>`), "<p><b>a</b></p>");
});

test("a nested fragment is not escaped twice", () => {
    const inner = html`<b>${"a & b"}</b>`;

    assert.equal(markup(inner), "<b>a &amp; b</b>");
    assert.equal(markup(html`<p>${inner}</p>`), "<p><b>a &amp; b</b></p>");
});

/** A regra que o resto do ficheiro protege: um produtor que devolva texto sai escapado sozinho. */
test("composing two builders needs no raw(), and text is still escaped", () => {
    assert.equal(markup(html`<p>${html`<b>x</b>`}</p>`), "<p><b>x</b></p>");
    assert.equal(markup(html`<p>${"<b>x</b>"}</p>`), "<p>&lt;b&gt;x&lt;/b&gt;</p>");
});

test("a list of builders joins without separator and without raw()", () => {
    const cells = ["a", "b & c"].map((value) => html`<td>${value}</td>`);

    assert.equal(markup(html`<tr>${cells}</tr>`), "<tr><td>a</td><td>b &amp; c</td></tr>");
    // E uma lista de texto continua a ser escapada, item a item.
    assert.equal(markup(html`<p>${["<a>", "<b>"]}</p>`), "<p>&lt;a&gt;&lt;b&gt;</p>");
});

test("null and undefined give empty text, as in esc()", () => {
    assert.equal(markup(html`<p>${null}${undefined}</p>`), "<p></p>");
    assert.equal(String(raw(null)), "");
    // O zero é um valor e não uma ausência: as contagens dos mosaicos dependem disso.
    assert.equal(markup(html`<p>${0}</p>`), "<p>0</p>");
});

/** É uma `String`, por isso `+`, `.join()` e o `innerHTML` continuam a funcionar. */
test("the fragment behaves as text in everything but escaping", () => {
    const fragment = html`<p>${1}</p>`;

    assert.ok(fragment instanceof String);
    assert.equal(`${fragment}`, "<p>1</p>");
    assert.equal([fragment, fragment].join(""), "<p>1</p><p>1</p>");
    assert.equal(fragment.length, "<p>1</p>".length);
});

test("a company name with markup comes out inert in the licence card", () => {
    const root = parseFragment(
        deviceLicenseBlock({
            company: "<img src=x onerror=alert(1)>",
            licenseId: 1001,
        }),
    );

    assert.equal(root.querySelector("img"), null);
    assert.match(root.textContent, /<img src=x onerror=alert\(1\)>/);
});

/**
 * O `personIndex` chega do radar pelo MQTT sem passar por ninguém, o nome da área vem de quem a
 * desenhou na planta, e os `details` são injectados sem escapar.
 */
test("a detected person cannot write markup into the card", () => {
    const content = uplinkCardContent("zone_entry", {
        zone: "area",
        areaName: "\"><img src=x onerror=alert(1)>",
        personIndex: "\"><img src=x onerror=alert(1)>",
    });

    assert.doesNotMatch(String(content.details), /<img/i);

    const root = parseFragment(
        telemetryCard({
            icon: content.icon,
            title: "Queda",
            value: content.value,
            details: content.details,
        }),
    );

    assert.equal(root.querySelector("img"), null);
});

test("a card's value and title are escaped", () => {
    const root = parseFragment(
        telemetryCard({
            icon: "fa-bell",
            title: "<script>alert(1)</script>",
            value: "<script>alert(2)</script>",
        }),
    );

    assert.equal(root.querySelector("script"), null);
    assert.match(root.textContent, /<script>alert\(1\)<\/script>/);
    assert.match(root.textContent, /<script>alert\(2\)<\/script>/);
});

/** Quem entrega marcação tem de o dizer: senão sai escapada duas vezes, que se vê no ecrã. */
test("a card's details are escaped once, not twice", () => {
    const card = String(telemetryCard({
        icon: "fa-x",
        title: "T",
        details: compactDetails({ batteryType: "A & B" }, ["batteryType"]),
    }));

    assert.match(card, /A &amp; B/);
    assert.doesNotMatch(card, /&amp;amp;/);
});

test("a detail that arrives as raw text is escaped, not rendered as markup", () => {
    const card = String(telemetryCard({
        icon: "fa-x",
        title: "T",
        details: "<img src=x onerror=alert(1)>",
    }));

    assert.doesNotMatch(card, /<img/i);
    assert.match(card, /&lt;img/);
});

/** O `current` e o `total` chegam do aparelho pelo MQTT e vão parar a uma linha de detalhes. */
test("the cell a dispenser reports writes no markup", () => {
    state.selectedDetail = { model: { deviceType: "pill_dispenser" } };
    const content = uplinkCardContent("cells_remaining", {
        remaining: 5,
        current: "<img src=x onerror=alert(1)>",
        total: 28,
    });

    assert.doesNotMatch(String(content.details), /<img/i);

    const root = parseFragment(telemetryCard({
        icon: "fa-x",
        title: "Doses",
        details: content.details,
    }));

    assert.equal(root.querySelector("img"), null);
});

/** Dois destinos e duas regras: os detalhes são marcação, o título é texto para um atributo. */
test("the level a dispenser reports writes no markup", () => {
    state.selectedDetail = { model: { deviceType: "pill_dispenser" } };
    const content = uplinkCardContent("cells_remaining", {
        remaining: 5,
        level: "<img src=x onerror=alert(1)>",
    });

    assert.doesNotMatch(String(content.details), /<img/i);
});

test("a dispenser's title is not escaped twice", () => {
    state.selectedDetail = { model: { deviceType: "pill_dispenser" } };
    const content = uplinkCardContent("cells_remaining", {
        remaining: 5,
        current: "A & B",
        total: 28,
    });
    const root = parseFragment(telemetryCard({
        icon: "fa-x",
        title: "Doses",
        details: content.details,
        detailsTitle: content.detailsTitle,
    }));

    assert.match(
        root.querySelector(".telemetry-row-details")?.getAttribute("title") ?? "",
        /A & B/,
    );
});

test("a control built with html passes through intact", () => {
    const rendered = markup(field("Nome", html`<input class="form-control">`));

    assert.match(rendered, /<input class="form-control">/);
});

test("but a control in raw text is escaped", () => {
    const rendered = markup(field("Nome", "<img src=x onerror=alert(1)>"));

    assert.doesNotMatch(rendered, /<img/i);
});

test("joining fragments keeps them as markup, and a text part between them is escaped", () => {
    assert.equal(markup(joinMarkup([html`<b>a</b>`, "<i>b</i>", ""])), "<b>a</b> · &lt;i&gt;b&lt;/i&gt;");
});

test("a licence name with markup comes out inert in the API user form", () => {
    const root = parseFragment(apiUserForm(null, [
        { id: 7, companyName: "hitcare", licenseId: "1001", name: "<img src=x onerror=alert(1)>" },
    ]));

    assert.equal(root.querySelector("img"), null);
    assert.match(root.querySelector("[data-field=\"licenseRefId\"]").textContent, /<img src=x onerror=alert\(1\)>/);
});
