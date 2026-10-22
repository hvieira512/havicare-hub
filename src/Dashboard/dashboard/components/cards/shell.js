import { html } from "../../html.js";
import { stateBadge } from "../state-badge.js";

/** A casca de um cartão, partilhada pelo uplink e pelo downlink. */
export function telemetryCard({
    span = 6,
    icon,
    // O ícone pequeno no canto, como o relâmpago de uma bateria a carregar.
    badge = "",
    title,
    value = "",
    details = "",
    detailsTitle = "",
    body = "",
    feature = "",
    // Um cartão que abre alguma coisa em vez de pedir uma medição, como a planta da presença.
    action = "",
    pending = false,
    stateLabel = "",
    stateTone = "",
    tone = "",
}) {
    const clickable = feature !== "" || action !== "";
    const tag = clickable ? "button" : "div";
    const toneClass = tone ? ` telemetry-card-tone-${tone}` : "";
    const featureAttr = feature ? html` data-feature="${feature}"` : "";
    const attrs = clickable
        ? html` type="button" class="card h-100 w-100 telemetry-card-action text-start${toneClass}" data-action="${action || "requestFeature"}"${featureAttr}${pending ? " disabled" : ""}`
        : html` class="card h-100${toneClass}"`;
    // A pastilha leva a sua linha: num mosaico estreito não cabe ao lado do ícone e do nome.
    const state = stateLabel
        ? stateBadge(
                stateLabel,
                stateTone || (pending ? "warning" : "secondary"),
                "align-self-start",
            )
        : "";
    // Em repouso o avião de papel diz que o mosaico se pode pedir; a correr, a pastilha o estado.
    const requestHint =
        clickable && !stateLabel
            ? html`<span class="telemetry-card-hint flex-shrink-0" aria-hidden="true"><i class="fa-solid ${action ? "fa-up-right-and-down-left-from-center" : "fa-paper-plane"}"></i></span>`
            : "";

    // Pelo texto, e não pelo valor: um `Fragment` vazio é um objecto, e é sempre verdadeiro.
    const hasText = (part) => String(part ?? "") !== "";

    const detailsTitleAttr = hasText(detailsTitle) ? html` title="${detailsTitle}"` : "";
    const detailsHtml = hasText(details)
        ? html`<div class="d-flex flex-wrap gap-1 mt-2 telemetry-row-details text-secondary lh-sm"${detailsTitleAttr}>${details}</div>`
        : "";
    const valueHtml = hasText(value)
        ? html`<div class="telemetry-card-value fw-semibold lh-sm tabular-nums text-break">${value}</div>`
        : "";

    // A célula mede-se pelo contentor, e não pelo ecrã: ver `.telemetry-card-grid` no `device.css`.
    const cell = span === 12 ? "telemetry-card-wide min-w-0" : "min-w-0";

    return html`
    <div class="${cell}">
        <${tag}${attrs}>
            <div class="card-body p-3 d-flex flex-column gap-3">
                <div class="d-flex align-items-center gap-2 gap-sm-3">
                    <div class="telemetry-card-icon position-relative d-flex align-items-center justify-content-center flex-shrink-0 rounded-3">
                        <i class="fa-solid ${icon}"></i>
                        ${badge ? html`<span class="telemetry-card-badge d-flex align-items-center justify-content-center rounded-circle"><i class="fa-solid ${badge}"></i></span>` : ""}
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="telemetry-card-title text-uppercase fw-normal text-secondary lh-sm" title="${title}">${title}</div>
                            ${valueHtml}
                        </div>
                        ${requestHint}
                    </div>
                    ${state}
                    ${detailsHtml}
                ${body}
            </div>
        </${tag}>
    </div>`;
}
