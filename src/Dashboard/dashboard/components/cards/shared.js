import { fieldLabel, fieldValue } from "../../format.js";
import { html } from "../../html.js";

/**
 * O que mais do que uma família de cartões precisa.
 */

export function compactDetails(data, keys) {
    return (
        keys
            .filter(
                (key) =>
                    data[key] !== undefined &&
                    data[key] !== null &&
                    data[key] !== "",
            )
            // O `fieldValue` traduz enumerações; sem ele saía "Estado do sono: awake".
            .map((key) => html`${fieldLabel(key)}: ${fieldValue(key, data[key])}`)
            .join(" · ")
    );
}
