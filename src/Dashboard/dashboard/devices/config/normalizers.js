/** O `fallback` é o que fica quando o valor não decide nada -- e não «desligado». */
export function boolValue(value, fallback = false) {
    if (typeof value === "boolean") {
        return value;
    }
    // Há aparelhos que mandam o nível em vez do bit, e qualquer nível é estar ligado.
    if (typeof value === "number") {
        return value !== 0;
    }
    const text = String(value ?? "").trim().toLowerCase();
    if (["1", "true", "yes", "on"].includes(text)) {
        return true;
    }
    if (["0", "false", "no", "off"].includes(text)) {
        return false;
    }
    return fallback;
}
