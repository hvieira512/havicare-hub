import test from "node:test";
import assert from "node:assert/strict";

const { apiError } = await import("../../src/Dashboard/dashboard/dialogs.js");
const { markApiFields } = await import("../../src/Dashboard/dashboard/validation.js");

/** O `message` da API é inglês e é para quem integra: o ecrã traduz pelo `code`. */

test("an API error shows the Portuguese text of its code, never the message", () => {
    const text = apiError({ error: { code: "device_exists", message: "Device with this IMEI already exists" } });

    assert.equal(text, "Já existe um aparelho com este IMEI.");
});

test("an unknown code falls back to a generic Portuguese text", () => {
    const text = apiError({ error: { code: "something_new", message: "Something new happened" } });

    assert.equal(text, "Não foi possível concluir a operação.");
});

test("an invalid request names its fields in Portuguese", () => {
    const text = apiError({
        error: { code: "invalid_request", message: "The request contains invalid fields", fields: { imei: ["x"], licenseId: ["y"] } },
    });

    assert.equal(text, "Campos em falta ou inválidos: IMEI, Licença.");
});

test("a body that is not JSON reads as an invalid server answer", () => {
    assert.equal(apiError({ error: { code: "invalid_json" } }), "O servidor deu uma resposta inválida.");
});

test("the API fields mark their inputs, empty ones as required", () => {
    document.body.innerHTML = "<form><input id=\"name\" value=\"\"><input id=\"url\" value=\"ftp:x\"></form>";
    const inputs = { name: document.getElementById("name"), baseUrl: document.getElementById("url") };

    const marked = markApiFields(
        { error: { code: "invalid_request", fields: { name: ["name is required"], baseUrl: ["baseUrl must be a URL"] } } },
        (field) => inputs[field],
    );

    assert.equal(marked, true);
    assert.equal(inputs.name.nextElementSibling.textContent, "Campo obrigatório");
    assert.equal(inputs.baseUrl.nextElementSibling.textContent, "Valor inválido");
});

test("an error without fields marks nothing", () => {
    document.body.innerHTML = "<form><input id=\"name\"></form>";

    assert.equal(markApiFields({ error: { code: "duplicate" } }, () => document.getElementById("name")), false);
});
