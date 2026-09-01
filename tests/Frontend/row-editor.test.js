import test from "node:test";
import assert from "node:assert/strict";

import { parseFragment } from "./support/dom.js";
import {
    editorOf,
    focusEditor,
    inlineEditor,
} from "../../src/Dashboard/dashboard/settings/row-editor.js";

/**
 * A vaga única do editor em linha.
 *
 * As três listagens que a usam -- empresa, licença e utilizador da API -- tinham cada uma a
 * sua cópia disto, e nenhuma tinha teste: a mecânica só se via a funcionar no ecrã. O que se
 * verifica aqui é o que as três precisam que seja verdade.
 */

test("abrir uma linha fecha a que estava aberta, mesmo sendo de outro tipo", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    editor.edit("company", 7);
    assert.equal(editor.at("company", 7), true);

    // É isto que os `editingCompany = null` espalhados pelos abridores faziam à mão.
    editor.edit("license", 3);
    assert.equal(editor.at("company", 7), false);
    assert.equal(editor.at("license", 3), true);
    assert.equal(renders, 2);
});

test("o id compara-se como texto, venha número ou string", () => {
    const editor = inlineEditor(() => {});

    editor.edit("license", 1001);
    assert.equal(editor.at("license", "1001"), true);
    assert.equal(editor.at("license", 1001), true);
    assert.equal(editor.at("license", 100), false);
});

test("o rascunho é a linha sem id, e não se confunde com a linha de id vazio de outro tipo", () => {
    const editor = inlineEditor(() => {});

    editor.draft("apiUser");
    assert.equal(editor.at("apiUser"), true, "o rascunho está aberto");
    assert.equal(editor.at("apiUser", 5), false, "e não é a linha 5");
    assert.equal(editor.at("company"), false, "nem o rascunho de outro tipo");
});

test("o extra viaja com a linha aberta", () => {
    const editor = inlineEditor(() => {});

    // Uma licença nova nasce dentro da empresa em que se carregou no `+`.
    editor.draft("license", { companyId: "4" });
    assert.equal(editor.at("license"), true);
    assert.equal(editor.open.companyId, "4");
});

/**
 * Um botão de editar sem `data-id` abria a linha de criar em branco no topo da lista, e
 * gravá-la criava um registo em vez de editar aquele em que se carregou. O rascunho passou a
 * pedir-se pelo nome.
 */
test("o edit sem id não abre nada, e não cai no rascunho", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    for (const missing of [undefined, null, ""]) {
        editor.edit("apiUser", missing);
        assert.equal(editor.open, null, `edit com ${String(missing)} não devia abrir nada`);
    }
    assert.equal(renders, 0, "e não devia sequer repintar");

    editor.draft("apiUser");
    assert.equal(editor.at("apiUser"), true, "o rascunho pedido pelo nome abre");
});

test("um extra com id ou kind não substitui a identidade da vaga", () => {
    const editor = inlineEditor(() => {});

    // O `extra` já carrega contexto da linha, e por isso passar-lhe um `id` é engano fácil.
    editor.edit("license", 7, { id: 99, kind: "company", companyId: "4" });

    assert.equal(editor.at("license", 7), true, "continua a ser a licença 7");
    assert.equal(editor.at("company", 99), false);
    assert.equal(editor.open.companyId, "4", "o resto do extra viaja na mesma");
});

test("o `open` é uma cópia: mexer nele não mexe na vaga", () => {
    const editor = inlineEditor(() => {});
    editor.edit("company", 1);

    editor.open.id = "99";

    assert.equal(editor.at("company", 1), true);
});

test("o cancel repinta e o reset não", () => {
    let renders = 0;
    const editor = inlineEditor(() => renders++);

    editor.edit("company", 1);
    assert.equal(renders, 1);

    editor.cancel();
    assert.equal(editor.open, null);
    assert.equal(renders, 2);

    editor.edit("company", 1);
    // O `reset` é para quem vai recarregar e repintar a seguir: repintar aqui era pintar
    // duas vezes, e a segunda por cima de dados já velhos.
    editor.reset();
    assert.equal(editor.open, null);
    assert.equal(renders, 3);
});

test("o editorOf lê o id e os campos do invólucro a que o botão pertence", () => {
    const root = parseFragment(`
        <div data-editor="company" data-id="12">
            <input data-field="name" value="  hitcare  ">
            <button id="save">Guardar</button>
        </div>`);
    const row = editorOf(root.querySelector("#save"), "company");

    assert.equal(row.id, "12");
    assert.equal(row.value("name"), "hitcare", "o valor vem aparado");
    assert.equal(row.field("name").tagName, "INPUT");
});

test("o editorOf devolve null quando o botão não está num editor deste tipo", () => {
    const root = parseFragment(`
        <div data-editor="license" data-id="3"><button id="save">Guardar</button></div>
        <button id="fora">Outro</button>`);

    assert.equal(editorOf(root.querySelector("#save"), "company"), null);
    assert.equal(editorOf(root.querySelector("#fora"), "license"), null);
});

test("o rascunho não traz id, e o editorOf devolve string vazia e não undefined", () => {
    const root = parseFragment(`
        <div data-editor="apiUser" data-id="">
            <input data-field="username" value="novo">
            <button id="save">Guardar</button>
        </div>`);

    assert.equal(editorOf(root.querySelector("#save"), "apiUser").id, "");
});

test("o foco cai no primeiro campo do editor, e não na primeira linha da lista", () => {
    const root = parseFragment(`
        <div class="linha"><input id="outra" value="linha de ver"></div>
        <div data-editor="company" data-id="1">
            <input id="primeiro" data-field="name">
            <select id="segundo" data-field="role"></select>
        </div>`);

    focusEditor(root);

    assert.equal(root.ownerDocument.activeElement.id, "primeiro");
});
