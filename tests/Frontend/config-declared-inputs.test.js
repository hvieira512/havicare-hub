import assert from "node:assert/strict";
import test from "node:test";
import { readdirSync, readFileSync } from "node:fs";

import "./support/browser-env.js";
import { CONFIG_INPUTS } from "../../src/Dashboard/dashboard/devices/config/inputs/index.js";

/**
 * Todo o tipo de campo que uma definição declara tem de ter quem o desenhe.
 *
 * Um tipo que o registo não conhece não rebenta: degrada para o editor de JSON em cru, na
 * cara de quem gere dispositivos. Apagar um renderizador, ou escrever o nome com um erro,
 * passava as quatro suites e só se via no ecrã.
 *
 * As cinco capacidades abaixo são a excepção: para elas o painel ignora o `input` declarado
 * e usa o nome da capacidade, porque o hub funde várias entradas nativas num cartão só.
 * Estão aqui pelo nome para que uma sexta tenha de passar por este teste.
 */
const SOBREPOSTAS = new Set([
    "alarm_clock",
    "phonebook",
    "sos_contacts",
    "call_whitelist",
    "whitelist_enabled",
]);

/**
 * Os tipos que as definições declaram só para as capacidades sobrepostas, e que por isso
 * nunca chegam a ser escolhidos: a lista telefónica do 4P Touch diz `contacts`, os números
 * SOS dizem `list`, e os alarmes dizem `alarms`, `reminders` ou `json` conforme o
 * fornecedor. O painel ignora os quatro e desenha pelo nome da capacidade.
 *
 * Ficam nomeados para que o teste continue a apanhar um tipo novo escrito com um erro. Que
 * o catálogo da API publique um `input` que a dashboard não honra é outra conversa, e está
 * por resolver.
 */
const DECLARADOS_MAS_IGNORADOS = new Set(["list", "contacts", "alarms", "reminders", "json"]);

const DEFINICOES = new URL("../../src/Command/Configuration/Definition/", import.meta.url);

/**
 * Os tipos de campo declarados nas definições, lidos do código PHP.
 *
 * As duas formas de declarar têm o tipo no quarto argumento -- `$entry('key', 'comando',
 * 'Rótulo', 'tipo', …)` e a chamada em várias linhas -- e há ainda o `input:` nomeado. Ler o
 * texto é frágil de propósito: falhar a encontrar um nome tira cobertura, nunca inventa um
 * alarme.
 */
function declaredInputs() {
    const nomes = new Set();

    for (const ficheiro of readdirSync(DEFINICOES).filter((name) => name.endsWith(".php"))) {
        const fonte = readFileSync(new URL(ficheiro, DEFINICOES), "utf8");

        for (const [, tipo] of fonte.matchAll(/input:\s*'([^']+)'/g)) {
            nomes.add(tipo);
        }
        for (const [, tipo] of fonte.matchAll(
            /\$entry\(\s*'[^']*',\s*'[^']*',\s*'[^']*',\s*'([^']+)'/g,
        )) {
            nomes.add(tipo);
        }
        for (const [, tipo] of fonte.matchAll(
            /make\(\s*'[^']*',\s*\n\s*'[^']*',\s*\n\s*'[^']*',\s*\n\s*'([^']+)'/g,
        )) {
            nomes.add(tipo);
        }
    }

    return nomes;
}

test("as definições declaram tipos de campo que alguém desenha", () => {
    const declarados = declaredInputs();
    assert.ok(declarados.size > 10, "o leitor das definições não encontrou tipos suficientes");

    const orfaos = [...declarados].filter(
        (tipo) => !CONFIG_INPUTS[tipo] &&
            !SOBREPOSTAS.has(tipo) &&
            !DECLARADOS_MAS_IGNORADOS.has(tipo),
    );

    assert.deepEqual(orfaos, [], "estes tipos caem no editor de JSON em cru");
});

test("a tabela de sobreposição continua a ser as cinco conhecidas", () => {
    const fonte = readFileSync(
        new URL("../../src/Dashboard/dashboard/devices/config/catalog-model.js", import.meta.url),
        "utf8",
    );
    const bloco = fonte.match(/const genericInputs = new Set\(\[([^\]]*)\]\)/)[1];

    assert.deepEqual(
        new Set([...bloco.matchAll(/"([^"]+)"/g)].map((m) => m[1])),
        SOBREPOSTAS,
    );
});

test("cada capacidade sobreposta tem o seu renderizador", () => {
    for (const nome of SOBREPOSTAS) {
        assert.ok(CONFIG_INPUTS[nome], `o painel escolhe ${nome}, e ninguém o desenha`);
    }
});

test("nenhum renderizador registado ficou sem desenhar nem sem ler", () => {
    for (const [nome, descritor] of Object.entries(CONFIG_INPUTS)) {
        assert.equal(typeof descritor.read, "function", `${nome} não sabe ler o que desenha`);

        const desenha = typeof descritor.render === "function" ||
            typeof descritor.control === "function";
        assert.ok(desenha || nome === "action", `${nome} não desenha nada e não é uma acção`);
    }
});
