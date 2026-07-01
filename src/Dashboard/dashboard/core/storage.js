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
