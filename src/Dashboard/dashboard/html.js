import { esc } from "./format.js";

/**
 * O `html` é uma template tag que escapa cada interpolação, e o `raw` é a única saída dessa
 * regra. Escapar por omissão porque o que entra na marcação -- nomes de modelo, IMEI, texto
 * de alarme -- chega à base de dados pelo MQTT e pelo TCP sem passar por ninguém.
 *
 * Devolve texto e não um objecto embrulhado: compor dois construtores é sempre um `raw()` à
 * vista.
 *
 * O `raw` e o `trusted` são a mesma coisa e distinguem-se pela proveniência: o `raw` deixa
 * passar marcação construída ali mesmo, e o `trusted` marcação que veio de fora da função e
 * que alguém teve de escapar antes. Procurar por `trusted(` lista as fronteiras a sério.
 */

/** Um fragmento em que se confia: HTML já construído, que entra sem ser escapado. */
class Fragment extends String {}

/** Marcação construída aqui mesmo. */
export const raw = (value) => new Fragment(String(value ?? ""));

/**
 * Marcação que chegou de fora -- um parâmetro, ou o que um renderizador devolveu -- e que
 * entra sem ser escapada porque quem a construiu já tratou disso.
 *
 * É o `raw` com outro nome, e o nome é o ponto: são estas as fronteiras que uma auditoria
 * tem de rever, e misturadas com a composição rotineira ficavam uma entre cento e doze.
 */
export const trusted = raw;

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
    new Fragment(strings.reduce((out, part, index) => out + render(values[index - 1]) + part));
