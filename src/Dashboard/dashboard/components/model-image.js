import { html } from "../html.js";

/**
 * A imagem de um modelo, pequena para uma listagem e grande para uma ficha.
 *
 * As duas partilham a cadeia que escolhe o nome: o comercial, depois o interno, depois o do
 * modelo. É a mesma peça com dois tamanhos, e por isso vivem no mesmo ficheiro.
 */

const modelLabel = (modelInfo, fallback) =>
    modelInfo?.commercial_name ||
    modelInfo?.commercialName ||
    modelInfo?.internal_model ||
    modelInfo?.internalModel ||
    modelInfo?.model ||
    fallback;

export function modelImageHtml(modelInfo, size = 40) {
    const label = modelLabel(modelInfo, "Modelo");
    return modelInfo?.image
        ? html`<img src="${modelInfo.image}" class="object-fit-contain" alt="${label}" style="width:${size}px;height:${size}px;">`
        : html`<i class="fa-solid fa-microchip text-secondary" style="width:${size}px;font-size:${Math.round(size * 0.62)}px"></i>`;
}

/**
 * A imagem grande, ou o ícone com a etiqueta quando não há imagem. Não fixa tamanho de
 * propósito: quem manda é o contentor, que já o limita por CSS.
 */
export function modelPreviewHtml(modelInfo, label = "Modelo") {
    return modelInfo?.image
        ? html`<img src="${modelInfo.image}" class="object-fit-contain mw-100" alt="${modelLabel(modelInfo, label)}">`
        : html`<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${label}</div></div>`;
}
