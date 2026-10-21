import { html } from "../html.js";

/** A imagem de um modelo, com o nome comercial, o interno, e o do modelo, por esta ordem. */

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

/** A imagem grande, ou o ícone com a etiqueta; o tamanho é do contentor. */
export function modelPreviewHtml(modelInfo, label = "Modelo") {
    return modelInfo?.image
        ? html`<img src="${modelInfo.image}" class="object-fit-contain mw-100" alt="${modelLabel(modelInfo, label)}">`
        : html`<div class="text-center text-secondary"><i class="fa-solid fa-microchip fs-1 opacity-50"></i><div class="small mt-2">${label}</div></div>`;
}
