import test from "node:test";
import assert from "node:assert/strict";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e
// esse caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { html, raw } from "../../src/Dashboard/dashboard/html.js";
import { deviceLicenseHtml } from "../../src/Dashboard/dashboard/widgets.js";
import {
    telemetryCard,
    uplinkCardContent,
} from "../../src/Dashboard/dashboard/telemetry-cards.js";

/* ---------- a template tag ---------- */

test("cada interpolação sai escapada, sem ninguém se lembrar do esc()", () => {
    assert.equal(
        html`<p>${"<script>alert(1)</script>"}</p>`,
        "<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>",
    );
    assert.equal(
        html`<i title="${"\" onerror=\"alert(1)"}"></i>`,
        "<i title=\"&quot; onerror=&quot;alert(1)\"></i>",
    );
});

test("o raw() deixa passar um fragmento já construído", () => {
    assert.equal(html`<p>${raw("<b>a</b>")}</p>`, "<p><b>a</b></p>");
});

test("um fragmento aninhado não é escapado duas vezes", () => {
    const inner = html`<b>${"a & b"}</b>`;

    assert.equal(inner, "<b>a &amp; b</b>");
    assert.equal(html`<p>${raw(inner)}</p>`, "<p><b>a &amp; b</b></p>");
});

// A regra não tem excepção implícita: o `html` devolve texto, e por isso compor dois
// construtores é sempre um `raw()` à vista. Sem isto, um `raw()` esquecido saía escapado no
// ecrã -- feio, mas visível -- em vez de um `esc()` esquecido, que é XSS em silêncio.
test("sem o raw(), até marcação nossa sai escapada", () => {
    assert.equal(html`<p>${html`<b>x</b>`}</p>`, "<p>&lt;b&gt;x&lt;/b&gt;</p>");
});

test("uma lista junta-se sem separador, como o .map(...).join(\"\") do código", () => {
    const cells = ["a", "b & c"].map((value) => html`<td>${value}</td>`);

    assert.equal(html`<tr>${cells.map(raw)}</tr>`, "<tr><td>a</td><td>b &amp; c</td></tr>");
    // E uma lista de texto continua a ser escapada, item a item.
    assert.equal(html`<p>${["<a>", "<b>"]}</p>`, "<p>&lt;a&gt;&lt;b&gt;</p>");
});

test("o null e o undefined dão texto vazio, como no esc()", () => {
    assert.equal(html`<p>${null}${undefined}</p>`, "<p></p>");
    assert.equal(String(raw(null)), "");
    // O zero é um valor e não uma ausência: as contagens dos mosaicos dependem disso.
    assert.equal(html`<p>${0}</p>`, "<p>0</p>");
});

test("o resultado é texto, que é o que os construtores de marcação já devolviam", () => {
    assert.equal(typeof html`<p>${1}</p>`, "string");
});

/* ---------- as regressões, pelos renderizadores migrados ---------- */

test("um nome de empresa com marcação sai inerte do cartão da licença", () => {
    const root = parseFragment(
        deviceLicenseHtml({
            company: "<img src=x onerror=alert(1)>",
            licenseId: 1001,
        }),
    );

    assert.equal(root.querySelector("img"), null);
    assert.match(root.textContent, /<img src=x onerror=alert\(1\)>/);
});

/**
 * O `detectionLevel` vem no payload do radar e chega à base de dados pelo MQTT sem passar por
 * ninguém. Ia para os `details`, que o cartão e a linha da lista injectam sem escapar, e o
 * `titleize` não escapa nada -- era XSS guardado, disparado a abrir a ficha do dispositivo.
 */
test("o grau de uma detecção não consegue escrever marcação no cartão", () => {
    const content = uplinkCardContent("fall", {
        detectionType: "fall_confirmed",
        detectionLevel: "\"><img src=x onerror=alert(1)>",
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
    // Um grau que a tabela não conheça passa intacto, e o que interessa é que fica texto e
    // não uma tag.
    assert.match(root.textContent, /"><img src=x onerror=alert\(1\)>/);
});

test("o valor e o título de um cartão saem escapados", () => {
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
