import { esc } from "./format.js";

/**
 * O `html` escapa cada interpolação e devolve um `Fragment`, que passa intacto noutro `html`.
 * O que não é fragmento é texto, e texto sai escapado: nomes de modelo, IMEI e texto de alarme
 * chegam à base de dados pelo MQTT e pelo TCP sem passar por ninguém.
 */

/** Marcação em que se confia, e que por isso não volta a ser escapada. */
class Fragment extends String {}

/** Marcação construída aqui mesmo. */
export const raw = (value) => new Fragment(String(value ?? ""));

/** Marcação que veio de fora da função. É o `raw`, e o nome separa as fronteiras a rever. */
export const trusted = raw;

/** Um fragmento passa intacto, uma lista junta-se sem separador, e o resto sai escapado. */
const render = (value) => {
    if (value instanceof Fragment) return String(value);
    if (Array.isArray(value)) return value.map(render).join("");
    return esc(value);
};

export const html = (strings, ...values) =>
    new Fragment(strings.reduce((out, part, index) => out + render(values[index - 1]) + part));
