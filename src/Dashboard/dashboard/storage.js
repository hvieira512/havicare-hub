export const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
export const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";
// Repetida à mão no `assets/js/theme-init.js`.
export const THEME_STORAGE_KEY = "hub-dashboard-theme";
// O instante da última atividade, partilhado entre separadores para o relógio de inatividade ser um só.
export const LAST_ACTIVITY_STORAGE_KEY = "hub-dashboard-last-activity";

/** Num Safari privado o `localStorage` atira até ao ler: falhar vale «nada guardado». */
function attempt(work) {
    try {
        return work();
    } catch {
        return null;
    }
}

export const loadJsonStorage = (key) => attempt(() => JSON.parse(localStorage.getItem(key)));

export const saveJsonStorage = (key, value) => attempt(() => localStorage.setItem(key, JSON.stringify(value)));

export const loadTextStorage = (key) => attempt(() => localStorage.getItem(key)) || null;

export const saveTextStorage = (key, value) => attempt(() => localStorage.setItem(key, value));

export const clearStorageKey = (key) => attempt(() => localStorage.removeItem(key));
