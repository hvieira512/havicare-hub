// As chaves ficam com os ajudantes de armazenamento, para qualquer vista ler o mesmo valor
// sem redeclarar a string.
export const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
export const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";
// Repetida à mão no `assets/js/theme-init.js`, que aplica o tema antes da primeira pintura.
// Mudar aqui é mudar lá.
export const THEME_STORAGE_KEY = "hub-dashboard-theme";
// A credencial vive num cookie `HttpOnly`; aqui fica o instante da última atividade, partilhado
// entre separadores para o relógio de inatividade ser um só.
export const LAST_ACTIVITY_STORAGE_KEY = "hub-dashboard-last-activity";

/**
 * Todo o acesso ao armazenamento passa por estes três ajudantes. O armazém chega como função
 * porque num Safari privado a própria leitura atira, e aí degrada para `null`.
 */
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

// A serialização fica dentro da guarda: um valor que o `JSON.stringify` recuse é tão pouco
// razão para rebentar como um armazém fechado.
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
