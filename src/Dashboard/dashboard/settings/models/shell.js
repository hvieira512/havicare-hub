/**
 * O contexto e a navegação do separador do catálogo: os três painéis -- a lista, o
 * formulário e a ficha -- num módulo que não importa nenhum deles.
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

/** Mostra um dos painéis: 0 a lista, 1 o modelo novo, 2 a ficha. */
export function showModelsPane(index) {
    [...els.modelsPanes.children].forEach((pane, at) => {
        pane.hidden = at !== index;
    });
}

export function isModelListShown() {
    return !els.modelsPanes.firstElementChild.hidden;
}
