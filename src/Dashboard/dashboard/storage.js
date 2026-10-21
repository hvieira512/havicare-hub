export const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
export const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";
// Repetida à mão no `assets/js/theme-init.js`.
export const THEME_STORAGE_KEY = "hub-dashboard-theme";
// O instante da última atividade, partilhado entre separadores para o relógio de inatividade ser um só.
export const LAST_ACTIVITY_STORAGE_KEY = "hub-dashboard-last-activity";

/** O armazém chega como função porque num Safari privado a própria leitura atira. */
function readItem(store, key) {
    try {
        return store().getItem(key);
    } catch {
        return null;
    }
}

function writeItem(store, key, value) {
    try {
        store().setItem(key, value);
    } catch {}
}

function removeItem(store, key) {
    try {
        store().removeItem(key);
    } catch {}
}

function writeJson(store, key, value) {
    try {
        store().setItem(key, JSON.stringify(value));
    } catch {}
}

const local = () => localStorage;

const parseJson = (stored) => {
    try {
        return stored ? JSON.parse(stored) : null;
    } catch {
        return null;
    }
};

export function loadJsonStorage(key) {
    return parseJson(readItem(local, key));
}

export function saveJsonStorage(key, value) {
    writeJson(local, key, value);
}

export function loadTextStorage(key) {
    const stored = readItem(local, key);
    return stored ? String(stored) : null;
}

export function saveTextStorage(key, value) {
    writeItem(local, key, value);
}

export function clearStorageKey(key) {
    removeItem(local, key);
}
