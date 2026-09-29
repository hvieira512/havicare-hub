import { html, raw } from "../../html.js";
import { stateBadge } from "../state-badge.js";

/**
 * A casca de um cartão: o ícone, o título, e o corpo que quem chama traz. É só a moldura --
 * o uplink (o que um dispositivo reporta) e o downlink (o cartão de pedido) partilham-na, e o
 * que cada um diz vem de quem a chama.
 */
export function telemetryCard({
    span = 6,
    icon,
    title,
    value = "",
    details = "",
    // Há quanto tempo é a leitura, já escrito. Vazio quando não há leitura nenhuma.
    age = "",
    // O texto da tooltip, para quando diz mais do que a linha truncada.
    detailsTitle = "",
    body = "",
    feature = "",
    // Um cartão que abre alguma coisa em vez de pedir uma medição -- a presença abre a planta
    // da divisão. É o `data-action` que o ouvinte delegado da coluna resolve.
    action = "",
    pending = false,
    stateLabel = "",
    stateTone = "",
    tone = "",
}) {
    // Quando há um feature para pedir ou uma acção própria, é o cartão inteiro o botão.
    const clickable = feature !== "" || action !== "";
    const tag = clickable ? "button" : "div";
    const toneClass = tone ? ` telemetry-card-tone-${tone}` : "";
    const featureAttr = feature ? html` data-feature="${feature}"` : "";
    // O `p-0` tira ao botão o enchimento que o browser lhe dá: numa coluna de 9rem são doze
    // pixéis que saíam do nome da categoria.
    const attrs = clickable
        ? html` type="button" class="card telemetry-card h-100 w-100 p-0 telemetry-card-action text-start${toneClass}" data-action="${action || "requestFeature"}"${raw(featureAttr)}${pending ? " disabled" : ""}`
        : html` class="card telemetry-card h-100${toneClass}"`;
    // A pastilha leva a sua linha: num mosaico estreito não cabe ao lado do ícone e do nome.
    const state = stateLabel
        ? stateBadge(
                stateLabel,
                stateTone || (pending ? "warning" : "secondary"),
                "align-self-start",
            )
        : "";
    // Só o mosaico que abre outra coisa -- a planta da divisão -- leva sinal no canto. O de
    // pedir não: era o mesmo ícone em todos, e numa célula de 9rem custava o nome da categoria.
    const requestHint =
        action && !stateLabel
            ? `<span class="telemetry-card-hint flex-shrink-0" aria-hidden="true"><i class="fa-solid fa-up-right-and-down-left-from-center"></i></span>`
            : "";

    // Fora da linha do ícone, para ter a largura toda do cartão.
    const detailsTitleAttr = detailsTitle ? html` title="${detailsTitle}"` : "";
    const detailsHtml = details
        ? html`<div class="d-flex flex-wrap gap-1 telemetry-row-details text-secondary lh-sm"${raw(detailsTitleAttr)}>${raw(details)}</div>`
        : "";
    const valueHtml = value
        ? html`<div class="telemetry-card-value fw-semibold lh-sm tabular-nums text-break">${value}</div>`
        : "";
    const ageHtml = age
        ? html`<div class="telemetry-row-details text-secondary lh-sm">${age}</div>`
        : "";

    // Quantas células cabem por linha é do `.telemetry-card-grid`, no `device.css`. Aqui só
    // se diz quais pedem a linha toda.
    const cell = span === 12 ? "telemetry-card-wide min-w-0" : "min-w-0";

    // O valor tem linha própria: ao lado do ícone sobrava-lhe um terço da largura, e «2 pessoas»
    // partia-se a meio da palavra.
    return html`
    <div class="${cell}">
        <${tag}${raw(attrs)}>
            <div class="card-body d-flex flex-column gap-2">
                <div class="d-flex align-items-center gap-2">
                    <div class="telemetry-card-icon d-flex align-items-center justify-content-center flex-shrink-0 rounded-2">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="telemetry-card-title text-uppercase fw-normal text-secondary lh-sm flex-grow-1 min-w-0">${title}</div>
                    ${raw(requestHint)}
                </div>
                ${raw(valueHtml)}
                ${raw(state)}
                ${raw(detailsHtml)}
                ${raw(ageHtml)}
                ${raw(body)}
            </div>
        </${tag}>
    </div>`;
}
