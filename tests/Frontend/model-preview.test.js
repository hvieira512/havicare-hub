import test from "node:test";
import assert from "node:assert/strict";

import {
    modelImageHtml,
    modelPreviewHtml,
} from "../../src/Dashboard/dashboard/widgets.js";

const withImage = { commercialName: "HW20PRO", image: "/assets/models/hw20pro.png" };
const withoutImage = { commercialName: "HW20PRO" };

test("a miniatura leva o tamanho que lhe pedem, nos dois estados", () => {
    assert.match(modelImageHtml(withImage, 32), /style="width:32px;height:32px;"/);
    assert.match(modelImageHtml(withoutImage, 32), /style="width:32px;font-size:20px"/);
});

// Isto é o que torna desnecessária a cirurgia de string em settings/capabilities.js:
// o tamanho é um parâmetro desde sempre, não algo a remendar no HTML já produzido.
test("modelImageHtml devolve sempre marcação, nunca um valor falso", () => {
    for (const model of [withImage, withoutImage, {}, null, undefined]) {
        assert.equal(typeof modelImageHtml(model), "string");
        assert.ok(modelImageHtml(model).length > 0);
    }
});

test("a pré-visualização grande não fixa tamanho: quem manda é o contentor", () => {
    const html = modelPreviewHtml(withImage, "HW20PRO");

    assert.match(html, /^<img src="\/assets\/models\/hw20pro\.png" class="object-fit-contain"/);
    assert.doesNotMatch(html, /style=/);
});

test("sem imagem, a pré-visualização grande é o ícone com a etiqueta", () => {
    assert.equal(
        modelPreviewHtml(withoutImage, "HW20PRO"),
        "<div class=\"text-center text-secondary\"><i class=\"fa-solid fa-microchip fs-1 opacity-50\"></i><div class=\"small mt-2\">HW20PRO</div></div>",
    );
});

test("a etiqueta escapa o que vem do modelo", () => {
    assert.match(
        modelPreviewHtml({}, "<script>alert(\"x\")</script>"),
        /&lt;script&gt;/,
    );
});
