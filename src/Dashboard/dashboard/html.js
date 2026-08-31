import { esc } from "./format.js";

/**
 * O `html` é uma template tag que escapa cada interpolação, e o `raw` é a única saída dessa
 * regra.
 *
 * A convenção antiga era o contrário: a marcação escrevia-se à mão e cabia a quem a escreve
 * lembrar-se do `esc()` em cada `${}`. Um esquecimento num dos setenta sítios é XSS guardado,
 * e o que lá entra são nomes de modelo, fornecedores, IMEI, número do SIM e texto de alarme,
 * que chegam à base de dados pelo MQTT/TCP sem passar por ninguém.
 *
 * O `html` devolve texto e não um objecto embrulhado, de propósito: as funções que constroem
 * marcação continuam a devolver `string`, que é o que o resto do código -- e os testes -- já
 * assumem. A consequência é que compor dois construtores é sempre um `raw()` à vista, e essa
 * é a propriedade que se quer: cada injecção de confiança lê-se no sítio onde acontece, e
 * procurar por `raw(` lista-as todas.
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
