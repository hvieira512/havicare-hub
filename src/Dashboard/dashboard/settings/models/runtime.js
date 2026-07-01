let els;
let ui;
let callbacks = {};
let modelsSearchTimer = null;

export function initSettingsModelsRuntime(context) {
    els = context.els;
    ui = context.ui;
    callbacks = context.callbacks || {};
}

export function getSettingsModelsRuntime() {
    return { els, ui, callbacks };
}

export function scheduleModelsSearch(callback, delayMs = 250) {
    if (modelsSearchTimer) {
        clearTimeout(modelsSearchTimer);
    }
    modelsSearchTimer = setTimeout(callback, delayMs);
}
