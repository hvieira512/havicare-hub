import { fieldLabel, fieldValue } from "../../format.js";
import { html, raw } from "../../html.js";

/**
 * O que mais do que uma família de cartões precisa.
 */

export function compactDetails(data, keys) {
    return joinMarkup(
        keys
            .filter(
                (key) =>
                    data[key] !== undefined &&
                    data[key] !== null &&
                    data[key] !== "",
            )
            // O `fieldValue` traduz enumerações; sem ele saía "Estado do sono: awake".
            .map((key) => html`${fieldLabel(key)}: ${fieldValue(key, data[key])}`),
    );
}

/**
 * Juntar fragmentos devolve texto, e texto volta a ser escapado por quem o receber. O que sai
 * daqui já está escapado peça a peça, e por isso diz-se que é marcação.
 */
export const joinMarkup = (parts, separator = " · ") => raw(parts.filter(Boolean).join(separator));
