// As chaves ficam com os ajudantes de armazenamento, para qualquer vista ler o mesmo valor
// sem redeclarar a string.
export const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
export const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";
// Repetida à mão no `<head>` do `index.php`, que tem de aplicar o tema antes da primeira
// pintura e não pode esperar por um módulo. Mudar aqui é mudar lá.
export const THEME_STORAGE_KEY = "hub-dashboard-theme";

export function loadJsonStorage(key) {
    try {
        const stored = localStorage.getItem(key);
        return stored ? JSON.parse(stored) : null;
    } catch {
        return null;
    }
}

export function saveJsonStorage(key, value) {
    try {
        localStorage.setItem(key, JSON.stringify(value));
    } catch {}
}

export function loadTextStorage(key) {
    try {
        const stored = localStorage.getItem(key);
        return stored ? String(stored) : null;
    } catch {
        return null;
    }
}

export function saveTextStorage(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch {}
}

export function clearStorageKey(key) {
    try {
        localStorage.removeItem(key);
    } catch {}
}
