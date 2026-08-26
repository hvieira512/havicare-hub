import {state} from "../../state.js";

/**
 * O contexto e a navegacao do separador do catalogo.
 *
 * O separador sao tres slides de um carrossel -- a lista, o formulario de um modelo novo,
 * e a ficha de um modelo --, e os tres precisam do mesmo `els` e do mesmo carrossel. Ficam
 * aqui, num modulo que nao importa nenhum dos tres, para que qualquer um o possa importar.
 *
 * Os `callbacks` sao o que vem de fora do separador: a `index.js` das definicoes liga-os
 * quando arranca.
 */
let els;
let ui;
let callbacks = {};

export function initSettingsModels(context) {
    els = context.els;
    ui = context.ui;
    callbacks = context.callbacks || {};
}

export function getSettingsModelsRuntime() {
    return { els, ui, callbacks };
}

/**
 * O carrossel dos tres slides, criado a primeira vez que alguem precisa dele.
 *
 * Preguicoso e nao preso ao `shown.bs.tab` do separador: o catalogo e o separador de
 * entrada, e esse evento nao dispara em quem ja esta activo.
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
