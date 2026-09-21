import { html, raw } from "../html.js";

/**
 * Uma definição numa linha: o nome à esquerda, e à direita o que lhe pertence -- a pastilha
 * de estado e o que se faz com ela.
 *
 * É a mesma linha em três sítios que antes a escreviam à mão: o cartão de uma acção sem
 * parâmetros, o cartão de uma definição de um campo, e cada linha de um grupo. Escrever a
 * mesma marcação três vezes deixava-as a divergir no espaçamento e na ordem, e a pastilha
 * acabava num sítio diferente conforme o ecrã.
 *
 * O `badge` e as `actions` entram já construídos. É o único acoplamento, e é o que mantém a
 * linha ignorante do vocabulário da configuração: quem sabe de `data-config-*` é o painel.
 */
export function settingRow({
    title,
    note = "",
    badge = "",
    control = "",
    unit = "",
    actions = "",
    class: extraClass = "",
}) {
    // O controlo e a unidade andam juntos e não se separam ao quebrar a linha: «5» numa
    // linha e «min» na seguinte deixa de ser uma medida.
    const field = control === ""
        ? ""
        : html`<div class="d-flex align-items-center gap-2">${raw(control)}${unit === "" ? "" : raw(html`<span class="small text-secondary flex-shrink-0">${unit}</span>`)}</div>`;

    return html`
        <div class="${`d-flex align-items-center justify-content-between gap-3 flex-wrap ${extraClass}`.trim()}">
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold">${title}</div>
                ${note === "" ? "" : raw(html`<div class="small text-secondary">${note}</div>`)}
            </div>
            ${raw(badge)}
            ${raw(field)}
            ${raw(actions)}
        </div>`;
}
