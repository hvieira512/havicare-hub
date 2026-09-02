import { html, raw } from "../html.js";

/**
 * A pastilha de estado da plataforma: o ponto, o rótulo e um tom.
 *
 * É feita das classes do Bootstrap e não de CSS próprio. A altura de linha é fixada por
 * utilitário e não herdada, e é isso que a mantém do seu tamanho onde quer que caia -- dentro
 * de uma célula do AG Grid, que dá ao conteúdo a altura da linha, a versão da casa herdava-a
 * e transbordava.
 *
 * O fundo é subtil e o texto de ênfase: o mesmo par em toda a plataforma. A leitura nunca
 * pode depender da cor, e por isso o rótulo diz sempre qual é o estado.
 */
const TONES = ["primary", "secondary", "success", "warning", "danger", "info"];

/**
 * O `state-badge` não veste nada: é o gancho por onde um ecrã a posiciona no seu layout.
 *
 * Os três utilitários no fim corrigem o `badge` cru, que é mais pesado e mais baixo do que a
 * pastilha da plataforma: 600 em vez de 700, a caixa a 1,25 em vez de colada às letras, e
 * 8px de lado em vez de 6,8. São degraus que o Bootstrap já tem, e por isso não há CSS.
 */
const BASE =
    "state-badge badge rounded-pill d-inline-flex align-items-center gap-1 text-uppercase fw-semibold lh-sm px-2";

/** Um tom que o Bootstrap não tem geraria uma classe que não existe, e a pastilha ficava nua. */
const toneOf = (tone) => (TONES.includes(tone) ? tone : "secondary");

/**
 * O texto de ênfase do secundário é quase preto, e um estado neutro pintado assim lê-se com o
 * peso de um alarme. O `text-body-secondary` é o cinzento suave do corpo, que é o que um
 * estado sem cor própria deve ter.
 */
const textOf = (name) =>
    name === "secondary" ? "text-body-secondary" : `text-${name}-emphasis`;

/**
 * O terceiro parâmetro nasceu classe extra e continua a aceitá-la em texto. Um ícone precisa
 * de outra opção ao lado dela, e um objecto nomeia as duas onde uma quarta posição obrigava
 * a passar vazio o que não interessa.
 */
const optionsOf = (options) =>
    typeof options === "string" ? { class: options } : options ?? {};

export function stateBadge(label, tone = "secondary", options = "") {
    const { icon = "", class: extraClass = "" } = optionsOf(options);
    const name = toneOf(tone);
    const classes = [BASE, `bg-${name}-subtle`, textOf(name), extraClass]
        .filter(Boolean)
        .join(" ");
    // O ícone ocupa o lugar do ponto: são duas marcas para o mesmo sítio. Ambas são
    // decoração -- quem lê o estado lê o rótulo --, e por isso saem do alcance do leitor.
    const mark = icon
        ? html`<i class="fa-solid ${icon}" aria-hidden="true"></i>`
        : "<span class=\"state-badge-dot rounded-circle d-inline-block\" aria-hidden=\"true\"></span>";

    return html`<span class="${classes}">${raw(mark)}${label}</span>`;
}

/** Ligado ou desligado: a mesma expressão em três ecrãs, com um parâmetro só. */
export function onlineBadge(online) {
    return stateBadge(online ? "Ligado" : "Desligado", online ? "success" : "secondary");
}
