import { html, raw } from "../html.js";

/**
 * A pastilha de estado da plataforma: o ponto, o rótulo e um tom, só com classes do Bootstrap.
 * A altura de linha é fixada para caber numa célula do AG Grid; o rótulo diz sempre o estado.
 */
const TONES = ["primary", "secondary", "success", "warning", "danger", "info"];

/**
 * O `state-badge` é só o gancho para o layout de cada ecrã. Os três utilitários no fim põem o
 * `badge` cru no peso, na altura e no enchimento da pastilha da plataforma.
 */
const BASE =
    "state-badge badge rounded-pill d-inline-flex align-items-center gap-1 text-uppercase fw-semibold lh-sm px-2";

/** Um tom que o Bootstrap não tem geraria uma classe que não existe, e a pastilha ficava nua. */
const toneOf = (tone) => (TONES.includes(tone) ? tone : "secondary");

/** Um estado neutro leva o cinzento suave do corpo, e não a ênfase do secundário, quase preta. */
const textOf = (name) =>
    name === "secondary" ? "text-body-secondary" : `text-${name}-emphasis`;

/** O terceiro parâmetro aceita a classe extra em texto, ou um objecto com a classe e o ícone. */
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
export function onlineBadge(online, options = "") {
    return stateBadge(
        online ? "Ligado" : "Desligado",
        online ? "success" : "secondary",
        options,
    );
}
