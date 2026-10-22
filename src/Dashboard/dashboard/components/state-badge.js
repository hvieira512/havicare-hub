import { html } from "../html.js";

/** A pastilha de estado: o ponto, o rótulo e um tom do Bootstrap. O rótulo diz sempre o estado. */
const TONES = ["primary", "secondary", "success", "warning", "danger", "info"];

/** O `state-badge` é o gancho do layout; os utilitários dão ao `badge` o peso e a medida da pastilha. */
const BASE =
    "state-badge badge rounded-pill d-inline-flex align-items-center gap-1 text-uppercase fw-semibold lh-sm px-2";

const toneOf = (tone) => (TONES.includes(tone) ? tone : "secondary");

/** Um estado neutro leva o cinzento suave do corpo, e não o secundário, quase preto. */
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
    // O ícone ocupa o lugar do ponto; ambos são decoração, e o leitor de ecrã lê o rótulo.
    const mark = icon
        ? html`<i class="fa-solid ${icon}" aria-hidden="true"></i>`
        : html`<span class="state-badge-dot rounded-circle d-inline-block" aria-hidden="true"></span>`;

    return html`<span class="${classes}">${mark}${label}</span>`;
}

export function onlineBadge(online, options = "") {
    return stateBadge(
        online ? "Ligado" : "Desligado",
        online ? "success" : "secondary",
        options,
    );
}

/** `measuring` e `idle` em quem só se liga para medir; os outros estão ligados ou desligados. */
export const presenceOf = (device) => device?.presence || (device?.online ? "online" : "offline");

export const PRESENCE_LABEL = {
    online: "Ligado",
    offline: "Desligado",
    measuring: "A medir",
    idle: "Sem medição em curso",
};

/** Entre leituras não há pastilha: o que se diz é quando foi a última. */
export function presenceBadge(device, options = "") {
    const presence = presenceOf(device);
    if (presence === "measuring") return stateBadge("A medir", "info", options);
    if (presence === "idle") return "";
    return onlineBadge(presence === "online", options);
}
