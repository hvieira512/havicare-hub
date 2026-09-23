import { html, raw } from "../html.js";

/**
 * Uma definição numa linha: o nome à esquerda, e à direita o que lhe pertence -- a pastilha
 * de estado e o que se faz com ela.
 *
 * Serve os três desenhos do painel de configurações -- o cartão de uma acção sem parâmetros,
 * o cartão de uma definição de um campo, e cada linha de um grupo -- para o espaçamento, a
 * ordem e o lugar da pastilha serem os mesmos nos três.
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

    // O bloco do nome reparte o espaço que sobra em vez de o reclamar todo: com `flex-grow-1`
    // sozinho a base é o conteúdo, e uma descrição de duas linhas passava a largura toda --
    // não sobrava espaço para o botão, e o `flex-wrap` mandava-o para a linha de baixo,
    // encostado à esquerda. A classe fica na folha de estilo porque é `flex: 1 1 0`, e o
    // Bootstrap não tem utilitário para a base a zero.
    return html`
        <div class="${`d-flex align-items-center justify-content-between gap-3 flex-wrap ${extraClass}`.trim()}">
            <div class="setting-row-title min-w-0">
                <div class="fw-semibold">${title}</div>
                ${note === "" ? "" : raw(html`<div class="small text-secondary">${note}</div>`)}
            </div>
            ${raw(badge)}
            ${raw(field)}
            ${raw(actions)}
        </div>`;
}
