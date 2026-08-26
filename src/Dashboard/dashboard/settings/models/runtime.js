let els;
let ui;
let callbacks = {};

export function initSettingsModelsRuntime(context) {
    els = context.els;
    ui = context.ui;
    callbacks = context.callbacks || {};
}

export function getSettingsModelsRuntime() {
    return { els, ui, callbacks };
}
