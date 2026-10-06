import test from "node:test";
import assert from "node:assert/strict";
import { globSync, readFileSync } from "node:fs";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e
// esse caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { html, raw, trusted } from "../../src/Dashboard/dashboard/html.js";
import { field } from "../../src/Dashboard/dashboard/components/form-field.js";
import { deviceLicenseBlock } from "../../src/Dashboard/dashboard/components/device-license.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";
import { compactDetails } from "../../src/Dashboard/dashboard/components/cards/shared.js";
import { state } from "../../src/Dashboard/dashboard/state.js";

const ROOT = new URL("../..", import.meta.url).pathname;

/* ---------- a template tag ---------- */

/** O que se afirma é a marcação, e o `html` devolve um fragmento: comparar texto com texto. */
const markup = (value) => String(value);

test("cada interpolação sai escapada, sem ninguém se lembrar do esc()", () => {
    assert.equal(
        markup(html`<p>${"<script>alert(1)</script>"}</p>`),
        "<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>",
    );
    assert.equal(
        markup(html`<i title="${"\" onerror=\"alert(1)"}"></i>`),
        "<i title=\"&quot; onerror=&quot;alert(1)\"></i>",
    );
});

test("o raw() deixa passar um fragmento já construído", () => {
    assert.equal(markup(html`<p>${raw("<b>a</b>")}</p>`), "<p><b>a</b></p>");
});

test("um fragmento aninhado não é escapado duas vezes", () => {
    const inner = html`<b>${"a & b"}</b>`;

    assert.equal(markup(inner), "<b>a &amp; b</b>");
    assert.equal(markup(html`<p>${inner}</p>`), "<p><b>a &amp; b</b></p>");
});

/** A regra que o resto do ficheiro protege: um produtor que devolva texto sai escapado sozinho. */
test("compor dois construtores não precisa de raw(), e texto continua a ser escapado", () => {
    assert.equal(markup(html`<p>${html`<b>x</b>`}</p>`), "<p><b>x</b></p>");
    assert.equal(markup(html`<p>${"<b>x</b>"}</p>`), "<p>&lt;b&gt;x&lt;/b&gt;</p>");
});

test("uma lista de construtores junta-se sem separador e sem raw()", () => {
    const cells = ["a", "b & c"].map((value) => html`<td>${value}</td>`);

    assert.equal(markup(html`<tr>${cells}</tr>`), "<tr><td>a</td><td>b &amp; c</td></tr>");
    // E uma lista de texto continua a ser escapada, item a item.
    assert.equal(markup(html`<p>${["<a>", "<b>"]}</p>`), "<p>&lt;a&gt;&lt;b&gt;</p>");
});

test("o null e o undefined dão texto vazio, como no esc()", () => {
    assert.equal(markup(html`<p>${null}${undefined}</p>`), "<p></p>");
    assert.equal(String(raw(null)), "");
    // O zero é um valor e não uma ausência: as contagens dos mosaicos dependem disso.
    assert.equal(markup(html`<p>${0}</p>`), "<p>0</p>");
});

/** É uma `String`, por isso `+`, `.join()` e o `innerHTML` continuam a funcionar. */
test("o fragmento comporta-se como texto em tudo menos no escapamento", () => {
    const fragment = html`<p>${1}</p>`;

    assert.ok(fragment instanceof String);
    assert.equal(`${fragment}`, "<p>1</p>");
    assert.equal([fragment, fragment].join(""), "<p>1</p><p>1</p>");
    assert.equal(fragment.length, "<p>1</p>".length);
});

/* ---------- as regressões, pelos renderizadores migrados ---------- */

test("um nome de empresa com marcação sai inerte do cartão da licença", () => {
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
 * O `detectionLevel` chega do radar pelo MQTT sem passar por ninguém, e os `details` são
 * injectados sem escapar.
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

/* ---------- a fronteira de confiança ---------- */

/** A separação só vale enquanto o `trusted` for o conjunto pequeno. */
test("o trusted deixa passar marcação construída por quem chama", () => {
    assert.equal(markup(html`<p>${trusted("<b>a</b>")}</p>`), "<p><b>a</b></p>");
});

test("sem o trusted, o que vem de quem chama sai escapado", () => {
    assert.equal(
        markup(html`<p>${"<img src=x onerror=alert(1)>"}</p>`),
        "<p>&lt;img src=x onerror=alert(1)&gt;</p>",
    );
});

test("não sobra nenhuma fronteira de confiança no frontend", () => {
    const files = globSync("src/Dashboard/dashboard/**/*.js", { cwd: ROOT });
    const sites = files.flatMap((rel) => {
        const src = readFileSync(`${ROOT}/${rel}`, "utf8");
        return [...src.matchAll(/trusted\(/g)].map(() => rel);
    }).filter((rel) => !rel.endsWith("html.js"));

    assert.equal(
        sites.length,
        0,
        `o trusted voltou a ${sites.length} sítios. Quem entrega marcação constrói-a com o \`html\`; o trusted é para marcação que vem de fora da função, e isso quer revisão.\n${[...new Set(sites)].join("\n")}`,
    );
});

/** Quem entrega marcação tem de o dizer: senão sai escapada duas vezes, que se vê no ecrã. */
test("os detalhes de um cartão saem escapados uma vez e não duas", () => {
    const card = String(telemetryCard({
        icon: "fa-x",
        title: "T",
        details: compactDetails({ batteryType: "A & B" }, ["batteryType"]),
    }));

    assert.match(card, /A &amp; B/);
    assert.doesNotMatch(card, /&amp;amp;/);
});

test("um detalhe que chegue em texto cru sai escapado, e não como marcação", () => {
    const card = String(telemetryCard({
        icon: "fa-x",
        title: "T",
        details: "<img src=x onerror=alert(1)>",
    }));

    assert.doesNotMatch(card, /<img/i);
    assert.match(card, /&lt;img/);
});

/** O `current` e o `total` chegam do aparelho pelo MQTT e vão parar a uma linha de detalhes. */
test("o compartimento reportado por um dispensador não escreve marcação", () => {
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
test("o nível reportado por um dispensador não escreve marcação", () => {
    state.selectedDetail = { model: { deviceType: "pill_dispenser" } };
    const content = uplinkCardContent("cells_remaining", {
        remaining: 5,
        level: "<img src=x onerror=alert(1)>",
    });

    assert.doesNotMatch(String(content.details), /<img/i);
});

test("o título de um dispensador não passa pelo escapamento duas vezes", () => {
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

/* ---- o campo de formulário não confia em quem o chama ---- */

test("um controlo construído com html passa intacto", () => {
    const rendered = markup(field("Nome", html`<input class="form-control">`));

    assert.match(rendered, /<input class="form-control">/);
});

test("mas um controlo em texto cru sai escapado", () => {
    const rendered = markup(field("Nome", "<img src=x onerror=alert(1)>"));

    assert.doesNotMatch(rendered, /<img/i);
});
