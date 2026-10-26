import { fieldLabel, fieldValue } from "../../core/format.js";
import { html } from "../../core/html.js";

export function compactDetails(data, keys) {
    return joinMarkup(
        keys
            .filter(
                (key) =>
                    data[key] !== undefined &&
                    data[key] !== null &&
                    data[key] !== "",
            )
            // O `fieldValue` traduz enumerações, como o `awake` do estado do sono.
            .map((key) => html`${fieldLabel(key)}: ${fieldValue(key, data[key])}`),
    );
}

/** Os fragmentos com o separador entre eles; filtrados pelo texto, que um fragmento vazio é um objecto. */
export const joinMarkup = (parts, separator = " · ") =>
    html`${parts
        .filter((part) => String(part ?? "") !== "")
        .flatMap((part, index) => (index === 0 ? [part] : [separator, part]))}`;
