import { esc } from "./format.js";

/**
 * O `html` é uma template tag que escapa cada interpolação, e o `raw` é a única saída dessa
 * regra. Escapar por omissão porque o que entra na marcação -- nomes de modelo, IMEI, texto
 * de alarme -- chega à base de dados pelo MQTT e pelo TCP sem passar por ninguém.
 *
 * Devolve texto e não um objecto embrulhado, e por isso compor dois construtores é sempre um
 * `raw()` à vista: procurar por `raw(` lista todas as injecções de confiança.
 */

/** Um fragmento em que se confia: HTML já construído, que entra sem ser escapado. */
class Fragment extends String {}

export const raw = (value) => new Fragment(String(value ?? ""));

/**
 * Um valor interpolado. Um fragmento passa intacto, uma lista junta-se sem separador --
 * porque é isso que o `.map(...).join("")` do código já fazia -- e tudo o resto sai escapado,
 * com o `null` e o `undefined` a darem texto vazio, como no `esc()`.
 */
const render = (value) => {
    if (value instanceof Fragment) return String(value);
    if (Array.isArray(value)) return value.map(render).join("");
    return esc(value);
};

export const html = (strings, ...values) =>
    strings.reduce((out, part, index) => out + render(values[index - 1]) + part);
