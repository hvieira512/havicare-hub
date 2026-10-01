import test from "node:test";
import assert from "node:assert/strict";
import { globSync, readFileSync } from "node:fs";

// Tem de vir antes dos módulos do dashboard: o nome de uma capacidade vem do catálogo, e
// esse caminho passa pelo `api/http.js`, que toca em `window` ao carregar.
import "./support/browser-env.js";
import { parseFragment } from "./support/dom.js";
import { html, raw, trusted } from "../../src/Dashboard/dashboard/html.js";
import { deviceLicenseBlock } from "../../src/Dashboard/dashboard/components/device-license.js";
import { uplinkCardContent } from "../../src/Dashboard/dashboard/components/cards/telemetry.js";
import { telemetryCard } from "../../src/Dashboard/dashboard/components/cards/shell.js";
import { compactDetails } from "../../src/Dashboard/dashboard/components/cards/shared.js";

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

/**
 * Esta é a regra que o resto do ficheiro protege, e é a que inverte a omissão.
 *
 * Encaixar um construtor noutro não leva `raw()`, porque o `html` devolve um fragmento e o
 * fragmento passa intacto. O que **não** passa é texto, e por isso um produtor novo que
 * devolva uma string sai escapado sozinho -- que é exactamente o que faltava quando a chave
 * de configuração que um aparelho inventava entrou na dashboard como marcação.
 */
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

/**
 * O fragmento é uma `String` e não um objecto à parte: `String(x)`, `+`, `.join()` e a
 * atribuição a `innerHTML` continuam todos a funcionar sem ninguém pensar nisso.
 */
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

/* ---------- a fronteira de confiança ---------- */

/**
 * O `raw` e o `trusted` fazem o mesmo e distinguem-se pela proveniência. A separação só serve
 * para alguma coisa enquanto o `trusted` continuar a ser o conjunto pequeno: foi um `raw()`
 * perdido entre cento e doze que deixou passar a chave de configuração que um aparelho
 * inventava, e que entrava na dashboard como marcação.
 */
test("o trusted deixa passar marcação construída por quem chama", () => {
    assert.equal(markup(html`<p>${trusted("<b>a</b>")}</p>`), "<p><b>a</b></p>");
});

test("sem o trusted, o que vem de quem chama sai escapado", () => {
    assert.equal(
        markup(html`<p>${"<img src=x onerror=alert(1)>"}</p>`),
        "<p>&lt;img src=x onerror=alert(1)&gt;</p>",
    );
});

test("as fronteiras de confiança continuam a caber numa mão", () => {
    const files = globSync("src/Dashboard/dashboard/**/*.js", { cwd: ROOT });
    const sites = files.flatMap((rel) => {
        const src = readFileSync(`${ROOT}/${rel}`, "utf8");
        return [...src.matchAll(/trusted\(/g)].map(() => rel);
    }).filter((rel) => !rel.endsWith("html.js"));

    assert.ok(
        sites.length <= 12,
        `o trusted está em ${sites.length} sítios: ou há fronteiras novas a rever, ou passou a usar-se onde o raw chegava.\n${[...new Set(sites)].join("\n")}`,
    );
});

/**
 * A inversão da omissão tem um custo que é preciso prender: quem entrega **marcação** numa
 * fronteira tem de o dizer, senão ela sai escapada duas vezes e o utilizador lê
 * `A &amp; B` à letra. É feio e visível -- que é o ponto, por oposição ao `esc()` esquecido,
 * que era XSS em silêncio.
 */
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
