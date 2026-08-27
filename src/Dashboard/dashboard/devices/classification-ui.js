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
 * O desenho da classificação de um dispositivo -- tipo, modelo e licença -- partilhado
 * pelo assistente de adicionar e pelo modal de editar.
 *
 * São construtores de HTML e nada mais: não guardam estado nem escutam eventos, e é por
 * isso que os dois modais podem ter fluxos diferentes sem duplicar a marcação.
 */

/**
 * As licenças agrupadas pela empresa que as detém. A `/api/licenses` já vem ordenada por
 * empresa e traz o nome em cada linha: agrupar é só partir a lista onde o nome muda.
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

/** A chave de uma escolha: o `licenseId` só é único dentro da empresa. */
function licenseKey(company, licenseId) {
    return `${String(company ?? "")}:${String(licenseId ?? "0")}`;
}

/**
 * Um `licenseId` sozinho, resolvido na árvore para ganhar a empresa que lhe falta: a
 * notificação de um radar não autorizado traz a licença do tópico `radar/{licenseId}/{uid}`
 * e o assistente quer pré-selecioná-la.
 *
 * Se o mesmo número existir em duas empresas não há como saber qual é, e devolve nada: por
 * escolher é melhor do que escolhido mal sem ninguém reparar.
 */
export function ownerFromLicense(licenseId, tree = []) {
    const wanted = String(licenseId ?? "");
    if (wanted === "" || wanted === "0") return null;

    const matches = (tree || []).flatMap((group) =>
        (group.licenses || [])
            .filter((license) => String(license.licenseId) === wanted)
            .map(() => ({company: group.company, licenseId: wanted})),
    );

    return matches.length === 1 ? matches[0] : null;
}

/**
 * A árvore de licenças por onde se escolhe o dono de um dispositivo. A empresa é só o
 * cabeçalho do grupo: escolhe-se uma licença, e a empresa vem dela. O mesmo desenho dos
 * filtros do "Escolher dispositivo", mas de escolha única -- daí o `radiogroup`.
 *
 * "Sem licença" é a primeira e é folha: é a única que não pertence a empresa nenhuma, e no
 * fim ficaria atrás de uma lista que cresce com cada cliente novo.
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

/** A licença escolhida, por palavras: é o que a badge da trilha mostra. */
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
 * A trilha: as perguntas da classificação em fila, e o passo no fim da linha. Uma
 * respondida é um botão para voltar àquela pergunta, a activa fica contornada, e as que
 * faltam ficam esbatidas -- para se ver quantas são sem parecerem clicáveis.
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
 * `visual` é um ícone ou uma miniatura: um tipo é uma ideia e leva ícone, um modelo é um
 * objecto que existe e leva a fotografia.
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
 * Os tipos de dispositivo em cards. O `attrsFor` e o `countFor` são de quem chama porque é
 * aí que diferem: o assistente conta os modelos de cada tipo, o modal de edição não conta.
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
 * Os modelos em cards. O nome comercial é o título e o modelo interno o subtítulo: é o
 * comercial que se reconhece da caixa, e o interno que aparece nos tópicos e na base.
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

/** Os fornecedores em pastilhas: são poucos e não têm imagem que justifique um card. */
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

/** O que o tipo de dispositivo se chama, para quem só precisa da etiqueta. */
export {deviceTypeLabel};
