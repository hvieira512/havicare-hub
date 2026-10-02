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

/** Juntar fragmentos devolve texto, que seria escapado outra vez: o resultado é marcação. */
export const joinMarkup = (parts, separator = " · ") =>
    // Filtrado pelo texto e não pela verdade: um fragmento vazio é um objecto, e passava o
    // filtro para deixar o separador pendurado à frente do que sobrava.
    raw(parts.filter((part) => String(part ?? "") !== "").join(separator));
