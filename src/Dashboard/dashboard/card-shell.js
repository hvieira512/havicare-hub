import { html, raw } from "./html.js";
import { stateBadge } from "./components/state-badge.js";

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
    // O texto da tooltip, para quando diz mais do que a linha truncada.
    detailsTitle = "",
    body = "",
    feature = "",
    pending = false,
    stateLabel = "",
    stateTone = "",
    tone = "",
}) {
    // Quando há um feature para pedir, é o cartão inteiro o botão.
    const clickable = feature !== "";
    const tag = clickable ? "button" : "div";
    const toneClass = tone ? ` telemetry-card-tone-${tone}` : "";
    const attrs = clickable
        ? html` type="button" class="card h-100 telemetry-card-action text-start${toneClass}" data-action="requestFeature" data-feature="${feature}"${pending ? " disabled" : ""}`
        : html` class="card h-100${toneClass}"`;
    // A pastilha leva a sua linha: num mosaico estreito não cabe ao lado do ícone e do nome.
    const state = stateLabel
        ? stateBadge(
                stateLabel,
                stateTone || (pending ? "warning" : "secondary"),
                "align-self-start",
            )
        : "";
    // Em repouso o avião de papel diz que o mosaico se pode pedir; a correr, a pastilha
    // diz em que estado está. Um mosaico que não se pode pedir não tem nada ali.
    const requestHint =
        clickable && !stateLabel
            ? "<span class=\"telemetry-card-hint flex-shrink-0\" aria-hidden=\"true\"><i class=\"fa-solid fa-paper-plane\"></i></span>"
            : "";

    // Fora da linha do ícone, para ter a largura toda do cartão.
    const detailsTitleAttr = detailsTitle ? html` title="${detailsTitle}"` : "";
    const detailsHtml = details
        ? html`<div class="d-flex flex-wrap gap-1 mt-2 telemetry-row-details"${raw(detailsTitleAttr)}>${raw(details)}</div>`
        : "";
    const valueHtml = value
        ? html`<div class="telemetry-card-value tabular-nums text-break">${value}</div>`
        : "";

    // Linha toda por omissão, metade só em ecrã grande.
    const columns = span === 12 ? "col-12" : `col-12 col-lg-${span}`;

    // O corpo é uma coluna só para separar a linha do ícone do corpo que alguns mosaicos
    // trazem -- a barra de humidade da fralda, por exemplo.
    return html`
    <div class="${columns}">
        <${tag}${raw(attrs)}>
            <div class="card-body p-3 d-flex flex-column gap-3">
                <div class="d-flex align-items-center gap-2 gap-sm-3">
                    <div class="telemetry-card-icon">
                        <i class="fa-solid ${icon}"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="telemetry-card-title">${title}</div>
                            ${raw(valueHtml)}
                        </div>
                        ${raw(requestHint)}
                    </div>
                    ${raw(state)}
                    ${raw(detailsHtml)}
                ${raw(body)}
            </div>
        </${tag}>
    </div>`;
}
