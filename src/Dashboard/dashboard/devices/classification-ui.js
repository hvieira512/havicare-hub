import {esc} from "../format.js";
import {
    companyLabel,
    deviceTypeLabel,
    deviceTypeOptions,
    modelCommercialName,
    modelInternalName,
} from "../domain.js";
import {deviceTypeIcon, modelPreviewHtml} from "../widgets.js";

/**
 * O desenho da classificacao de um dispositivo -- tipo, modelo e licenca -- partilhado
 * pelo assistente de adicionar e pelo modal de editar.
 *
 * Sao construtores de HTML e nada mais: nao guardam estado nem escutam eventos. Quem os
 * usa e que sabe onde os por e o que fazer com o clique, e e por isso que os dois modais
 * podem ter fluxos diferentes sem duplicar a marcacao.
 */

/**
 * As licencas agrupadas pela empresa que as detem.
 *
 * A `/api/licenses` ja vem ordenada por empresa e depois por licenca, e traz o nome da
 * empresa em cada linha -- agrupar e so partir a lista onde o nome muda. Empresas sem
 * licencas nenhumas nao chegam aqui, porque a lista e de licencas.
 */
export function licenseTree(licenses = []) {
    const groups = new Map();
    for (const license of licenses) {
        const company = String(license.company_name ?? license.companyName ?? "");
        if (!groups.has(company)) groups.set(company, []);
        groups.get(company).push({
            licenseId: String(license.license_id ?? license.licenseId ?? ""),
            name: String(license.name || ""),
        });
    }
    return [...groups].map(([company, entries]) => ({company, licenses: entries}));
}

/** A chave de uma escolha: o `licenseId` sozinho nao chega, so e unico dentro da empresa. */
export function licenseKey(company, licenseId) {
    return `${String(company ?? "")}:${String(licenseId ?? "0")}`;
}

/**
 * A arvore de licencas por onde se escolhe o dono de um dispositivo.
 *
 * A empresa e so o cabecalho do grupo: escolhe-se uma licenca, e a empresa vem dela. O
 * mesmo desenho dos filtros do "Escolher dispositivo", mas de escolha unica -- daí o
 * `radiogroup` e a marca redonda em vez da caixa de visto.
 *
 * "Sem licenca" e a primeira e e folha: e a unica escolha que nao pertence a empresa
 * nenhuma, e no fim ficava atras de uma lista que cresce com cada cliente novo.
 */
export function licensePickerHtml(tree, selected = null) {
    const chosen = selected
        ? licenseKey(selected.company, selected.licenseId)
        : "";
    const rows = [
        licenseRow({
            company: "",
            licenseId: "0",
            label: "Sem licença",
            selected: chosen === licenseKey("", "0"),
        }),
    ];

    for (const group of tree || []) {
        if ((group.licenses || []).length === 0) continue;
        rows.push(
            `<div class="license-picker-company">${esc(companyLabel(group.company))}</div>`,
            `<div class="filter-branch">${group.licenses
                .map((license) =>
                    licenseRow({
                        company: group.company,
                        licenseId: license.licenseId,
                        label: license.name
                            ? `${license.name} (${license.licenseId})`
                            : license.licenseId,
                        selected: chosen === licenseKey(group.company, license.licenseId),
                        nested: true,
                    }),
                )
                .join("")}</div>`,
        );
    }

    return `<div class="filter-list license-picker" role="radiogroup" aria-label="Licença">
        ${rows.join("")}
    </div>`;
}

function licenseRow({company, licenseId, label, selected, nested = false}) {
    const classes = [
        "filter-option",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return `
        <button type="button" role="radio" aria-checked="${selected ? "true" : "false"}"
            class="${classes}" data-license-pick
            data-license-company="${esc(company)}" data-license-id="${esc(licenseId)}">
            <span class="filter-option-box"><i class="fa-solid fa-check"></i></span>
            <span class="filter-option-name">${esc(label)}</span>
        </button>`;
}

/** A licenca escolhida, por palavras: e o que a badge da trilha mostra. */
export function licenseBadgeValue(owner, tree = []) {
    const licenseId = String(owner?.licenseId ?? "0");
    if (licenseId === "0") return "Sem licença";
    const company = String(owner?.company ?? "");
    const match = (tree || [])
        .find((group) => group.company === company)
        ?.licenses.find((license) => license.licenseId === licenseId);
    return match?.name ? `${match.name} (${licenseId})` : licenseId;
}

/* ---------- a trilha ---------- */

/**
 * A trilha: as perguntas da classificacao em fila, e o passo no fim da linha.
 *
 * Uma respondida fica cheia e e um botao para voltar aquela pergunta; a activa fica
 * contornada; as que faltam ficam esbatidas, para se ver quantas sao sem parecerem
 * clicaveis. Mostrar so as respondidas deixava a linha vazia ao abrir e nao dizia quanto
 * faltava.
 */
export function wizardTrailHtml({
    questions,
    badges = [],
    currentKey = "",
    step = 1,
    steps = [],
}) {
    const answered = new Map(badges.map((badge) => [badge.key, badge]));

    return questions
        .map((question, index) => {
            const badge = answered.get(question.key);
            const sep = index > 0
                ? '<i class="fa-solid fa-caret-right wizard-trail-sep"></i>'
                : "";
            if (badge) {
                return `${sep}
            <button type="button" class="wizard-badge" data-wizard-reopen="${esc(badge.key)}"
                title="Voltar a esta pergunta">
                <span class="wizard-badge-key">${esc(badge.label)}</span>${esc(String(badge.value))}
            </button>`;
            }
            const pendingClass = question.key === currentKey
                ? "wizard-badge wizard-badge-now"
                : "wizard-badge wizard-badge-pending";
            return `${sep}
            <span class="${pendingClass}">
                <span class="wizard-badge-key">${esc(question.label)}</span>
            </span>`;
        })
        .join("")
        + `<span class="wizard-trail-step">Passo ${step} de ${steps.length} · ${esc(steps[step - 1] || "")}</span>`;
}

/* ---------- o tipo e o modelo ---------- */

/**
 * A grelha de escolhas em cards, partilhada pelo tipo de dispositivo e pelo modelo.
 *
 * `visual` e um icone ou uma miniatura: um tipo de dispositivo e uma ideia e leva icone,
 * um modelo e um objecto que existe e leva a fotografia. O resto da forma e o mesmo, e
 * duplica-la para o modelo era duplicar tambem os estados de foco e de selecao.
 */
export function cardGrid(label, cards) {
    return `
        <div class="wizard-card-grid" role="group" aria-label="${esc(label)}">
            ${cards
                .map(
                    (card) => `
                <button type="button" class="wizard-card${card.selected ? " selected" : ""}"
                    ${card.selected ? 'aria-pressed="true"' : ""} ${card.attrs}>
                    ${card.visual}
                    <span class="wizard-card-label">${esc(card.label)}</span>
                    ${card.sub ? `<span class="wizard-card-sub">${esc(card.sub)}</span>` : ""}
                </button>`,
                )
                .join("")}
        </div>`;
}

/**
 * Os tipos de dispositivo em cards.
 *
 * `attrsFor` e `countFor` sao de quem chama porque e ai que diferem: o assistente marca
 * os cards com os seus proprios atributos e conta os modelos de cada tipo, o modal de
 * edicao usa as accoes que ja tinha e nao conta nada -- o tipo ja esta escolhido.
 */
export function deviceTypeCardsHtml({attrsFor, selected = "", countFor = null}) {
    return cardGrid(
        "Tipo de dispositivo",
        deviceTypeOptions.map((option) => {
            const count = countFor ? countFor(option.value) : null;
            return {
                attrs: attrsFor(option.value),
                selected: option.value === selected,
                visual: `<i class="fa-solid ${esc(deviceTypeIcon(option.value))} wizard-card-icon"></i>`,
                label: option.label,
                sub: count === null
                    ? ""
                    : `${count} ${count === 1 ? "modelo" : "modelos"}`,
            };
        }),
    );
}

/**
 * Os modelos em cards, com fotografia.
 *
 * O nome comercial e o titulo e o modelo interno o subtitulo: e o comercial que a pessoa
 * reconhece da caixa, e o interno que aparece depois nos topicos e na base de dados.
 */
export function modelCardsHtml({models, attrsFor, selected = ""}) {
    return cardGrid(
        "Modelo",
        models.map((model) => {
            const internal = modelInternalName(model);
            const commercial = modelCommercialName(model);
            return {
                attrs: attrsFor(internal),
                selected: internal === selected,
                visual: `<span class="wizard-card-thumb">${modelPreviewHtml(model, internal)}</span>`,
                label: commercial || internal,
                sub: commercial && commercial !== internal ? internal : "",
            };
        }),
    );
}

/** Os fornecedores em pastilhas: sao poucos e nao tem imagem que justifique um card. */
export function supplierPillsHtml({suppliers, attrsFor, selected = ""}) {
    return `
        <div class="d-flex flex-wrap gap-2" role="group" aria-label="Fornecedor">
            ${suppliers
                .map(
                    (name) => `
                <button type="button" ${attrsFor(name)}
                    class="btn btn-sm ${name === selected ? "btn-primary" : "btn-outline-secondary"}"
                    aria-pressed="${name === selected ? "true" : "false"}">${esc(name)}</button>`,
                )
                .join("")}
        </div>`;
}

/** O que o tipo de dispositivo se chama, para quem so precisa da etiqueta. */
export {deviceTypeLabel};
