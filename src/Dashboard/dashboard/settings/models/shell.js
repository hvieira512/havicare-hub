import {state} from "../../state.js";

/**
 * O contexto e a navegação do separador do catálogo: três slides de um carrossel -- a lista,
 * o formulário de um modelo novo, e a ficha de um modelo -- que precisam do mesmo `els` e do
 * mesmo carrossel. Ficam num módulo que não importa nenhum dos três.
 */
let els;
let ui;

export function initSettingsModels(context) {
    els = context.els;
    ui = context.ui;
}

export function getSettingsModelsRuntime() {
    return { els, ui };
}

/**
 * O carrossel dos três slides, criado à primeira vez que alguém precisa dele: preguiçoso e
 * não preso ao `shown.bs.tab`, que não dispara no separador de entrada.
 */
export function modelsCarousel() {
    if (!state.settingsModal.modelsCarousel && els?.modelsCarousel) {
        state.settingsModal.modelsCarousel = new bootstrap.Carousel(
            els.modelsCarousel,
            {interval: false, wrap: false, touch: false},
        );
    }

    return state.settingsModal.modelsCarousel;
}
