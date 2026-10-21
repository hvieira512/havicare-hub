/** O texto de cada `code` da API, do dicionário. O `message` é inglês e não se mostra. */
import { DICTIONARY, fieldLabel } from "../i18n.js";

const GENERIC = "Não foi possível concluir a operação.";

/** O texto de um código, ou `null` quando o código não é conhecido. */
export function errorCodeText(code) {
    return DICTIONARY.errors[code] ?? null;
}

/** O que mostrar de um resultado com `error`: o texto do código, e os campos de um pedido inválido. */
export function errorText(result) {
    const error = result?.error ?? {};
    const fields = Object.keys(error.fields ?? {});
    if (error.code === "invalid_request" && fields.length > 0) {
        return `Campos em falta ou inválidos: ${fields.map(fieldLabel).join(", ")}.`;
    }

    return errorCodeText(error.code) ?? GENERIC;
}
