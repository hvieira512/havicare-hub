/** O contexto e a navegação do catálogo: a lista, o formulário e a ficha. */
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
