// As chaves ficam com os ajudantes de armazenamento, para qualquer vista ler o mesmo valor
// sem redeclarar a string.
export const FILTERS_STORAGE_KEY = "hub-dashboard-device-filters";
export const SELECTED_DEVICE_STORAGE_KEY = "hub-dashboard-selected-device";
// Repetida à mão no `<head>` do `index.php`, que tem de aplicar o tema antes da primeira
// pintura e não pode esperar por um módulo. Mudar aqui é mudar lá.
export const THEME_STORAGE_KEY = "hub-dashboard-theme";
// A credencial não passa por aqui: vive num cookie `HttpOnly` que o JavaScript não lê. O que
// fica é o instante da última atividade, partilhado entre separadores para o relógio de
// inatividade ser um só -- mexer num separador mantém os outros vivos.
export const LAST_ACTIVITY_STORAGE_KEY = "hub-dashboard-last-activity";

/**
 * Todo o acesso ao armazenamento passa por estes três ajudantes.
 *
 * O armazém chega como função porque num Safari em janela privada é a própria leitura de
 * `localStorage` que atira -- e aí a leitura tem de degradar para `null`, não derrubar quem a
 * pediu.
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
