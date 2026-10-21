import { html } from "../html.js";
import {
    companyLabel,
    deviceTypeOptions,
    modelCommercialName,
    modelInternalName,
} from "../domain.js";
import { deviceTypeIcon } from "../components/device-type-tiles.js";
import { modelPreviewHtml } from "../components/model-image.js";
import { countLabel } from "../i18n.js";

/** Tipo, modelo e licença do assistente de adicionar e do modal de editar: só HTML, sem estado. */

/** As licenças por empresa: a `/api/licenses` vem ordenada por empresa, e parte-se onde o nome muda. */
export function licenseTree(licenses) {
    const groups = new Map();
    for (const license of licenses) {
        const company = String(license.companyName ?? "");
        if (!groups.has(company)) groups.set(company, []);
        groups.get(company).push({
            licenseId: String(license.licenseId ?? ""),
            name: String(license.name || ""),
        });
    }
    return [...groups].map(([company, entries]) => ({ company, licenses: entries }));
}

/** A chave de uma escolha: o `licenseId` só é único dentro da empresa. */
function licenseKey(company, licenseId) {
    return `${String(company ?? "")}:${String(licenseId ?? "")}`;
}

/**
 * O dono de uma notificação, confirmado na árvore. Sem empresa procura só o número, e um
 * número que exista em duas empresas devolve null: fica por escolher.
 */
export function ownerFromLicense(licenseId, tree = [], company = "") {
    const wanted = String(licenseId ?? "");
    if (wanted === "") return null;
    const wantedCompany = String(company ?? "").trim().toLowerCase();

    const matches = (tree || []).flatMap((group) =>
        (group.licenses || [])
            .filter((license) => String(license.licenseId) === wanted)
            .filter(() => wantedCompany === "" ||
                String(group.company ?? "").trim().toLowerCase() === wantedCompany)
            .map(() => ({ company: group.company, licenseId: wanted })),
    );

    return matches.length === 1 ? matches[0] : null;
}

/** A árvore do dono: escolhe-se uma licença, e a empresa, que é só o cabeçalho, vem dela. */
export function licensePickerHtml(tree, selected = null) {
    const chosen = selected
        ? licenseKey(selected.company, selected.licenseId)
        : "";
    const rows = [
        licenseRow({
            company: "",
            licenseId: "",
            label: "Sem licença",
            selected: chosen === licenseKey("", ""),
        }),
    ];

    for (const group of tree || []) {
        if ((group.licenses || []).length === 0) continue;
        rows.push(
            html`<div class="license-picker-company fw-medium text-secondary">${(companyLabel(group.company))}</div>`,
            html`<div class="filter-branch position-relative d-flex flex-column">${group.licenses
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
            }</div>`,
        );
    }

    return html`<div class="filter-list license-picker d-flex flex-column" role="radiogroup" aria-label="Licença">
        ${rows}
    </div>`;
}

function licenseRow({ company, licenseId, label, selected, nested = false }) {
    const classes = [
        "filter-option d-flex align-items-center text-start rounded-2",
        nested ? "filter-option-nested" : "",
        selected ? "selected" : "",
    ]
        .filter(Boolean)
        .join(" ");

    return html`
        <button type="button" role="radio" aria-checked="${selected ? "true" : "false"}"
            class="${classes}" data-license-pick
            data-license-company="${company}" data-license-id="${licenseId}">
            <span class="filter-option-box d-grid flex-shrink-0 rounded-circle"><i class="fa-solid fa-check"></i></span>
            <span class="flex-fill min-w-0 text-truncate">${label}</span>
        </button>`;
}

/** A licença escolhida, como a badge da trilha a mostra. */
export function licenseBadgeValue(owner, tree = []) {
    const licenseId = String(owner?.licenseId ?? "");
    if (licenseId === "") return "Sem licença";
    const company = String(owner?.company ?? "");
    const match = (tree || [])
        .find((group) => group.company === company)
        ?.licenses.find((license) => license.licenseId === licenseId);
    return match?.name ? `${match.name} (${licenseId})` : licenseId;
}

const WIZARD_BADGE = "wizard-badge d-inline-flex align-items-center gap-1 text-start rounded-3";

// Cinco nomes numa linha não cabem num telemóvel: fora do passo actual, a migalha só existe
// a partir de `md`. Abaixo disso quem diz onde se está são a barra e o contador.
const ONLY_ON_WIDE = "d-none d-md-inline-flex";

/** A migalha: o passo respondido é um botão com a escolha, e os que faltam não se clicam. */
export function wizardTrailHtml({ questions, badges = [], currentKey = "" }) {
    const answered = new Map(badges.map((badge) => [badge.key, badge]));

    const parts = questions
        .map((question, index) => {
            const badge = question.key === currentKey ? null : answered.get(question.key);
            const sep = index > 0
                ? html`<i class="fa-solid fa-caret-right wizard-trail-sep text-body-tertiary ${ONLY_ON_WIDE}"></i>`
                : "";
            const name = html`<span class="wizard-badge-key text-nowrap">${badge?.label ?? question.label}</span>`;
            if (badge) {
                return html`${sep}
            <button type="button" class="${WIZARD_BADGE} text-body-emphasis ${ONLY_ON_WIDE}" data-wizard-reopen="${badge.key}"
                title="Voltar a este passo">
                <i class="fa-solid fa-check text-success"></i>${name}
                <span class="wizard-badge-value text-truncate">· ${(String(badge.value))}</span>
            </button>`;
            }
            const state = question.key === currentKey
                ? "wizard-badge-now fw-semibold"
                : `wizard-badge-pending text-body-tertiary ${ONLY_ON_WIDE}`;
            return html`${sep}
            <span class="${WIZARD_BADGE} ${state}">${name}</span>`;
        });

    return html`${parts}`;
}

/** A classificação de um aparelho que já existe, em pastilhas: o nome do campo por cima do valor. */
export function classificationTrailHtml({ questions, values, openKey = "", known = true }) {
    const parts = questions
        .map((question, index) => {
            const open = question.key === openKey;
            const value = known && !open ? String(values[question.key] ?? "") : "";
            const sep = index === 0
                ? ""
                : html`<i class="fa-solid fa-caret-down d-md-none text-body-tertiary align-self-center" aria-hidden="true"></i>
                   <i class="fa-solid fa-caret-right d-none d-md-inline text-body-tertiary flex-shrink-0" aria-hidden="true"></i>`;

            return html`${sep}
            <button type="button" class="classification-pill btn btn-link text-decoration-none text-start d-flex flex-column lh-sm min-w-0 bg-primary-subtle rounded-3 px-3 py-2 border-0"
                data-wizard-reopen="${question.key}" aria-expanded="${open ? "true" : "false"}">
                <span class="section-label mb-0">${question.label}</span>
                <span class="fw-semibold text-body text-truncate">${value}</span>
            </button>`;
        });

    return html`${parts}`;
}

export function wizardProgressHtml(step, total) {
    const bars = Array.from(
        { length: total },
        (_, index) =>
            html`<span class="flex-fill rounded-pill ${index < step ? "bg-primary" : "bg-secondary-subtle"}"></span>`,
    );

    return html`<div class="wizard-progress d-flex gap-1" role="progressbar" aria-label="Progresso"
        aria-valuemin="1" aria-valuemax="${total}" aria-valuenow="${step}">${bars}</div>`;
}

/** Grelha de escolhas em cards; o `visual` é um ícone para o tipo e a fotografia para o modelo. */
export function cardGrid(label, cards) {
    return html`
        <div class="wizard-card-grid d-grid gap-2" role="group" aria-label="${label}">
            ${cards
                    .map(
                        (card) => html`
                <button type="button" class="wizard-card d-flex flex-column align-items-center gap-2 text-center rounded-3${card.selected ? " selected" : ""}"
                    aria-pressed="${card.selected ? "true" : "false"}" ${card.attrs}>
                    ${card.visual}
                    <span class="wizard-card-label fw-medium lh-sm">${card.label}</span>
                    ${card.sub ? html`<span class="wizard-card-sub text-secondary">${card.sub}</span>` : ""}
                </button>`,
                    )
            }
        </div>`;
}

/** O `attrsFor` e o `countFor` são de quem chama: só o assistente conta os modelos. */
export function deviceTypeCardsHtml({ attrsFor, selected = "", countFor = null }) {
    return cardGrid(
        "Tipo de dispositivo",
        deviceTypeOptions.map((option) => {
            const count = countFor ? countFor(option.value) : null;
            return {
                attrs: attrsFor(option.value),
                selected: option.value === selected,
                visual: html`<i class="fa-solid ${(deviceTypeIcon(option.value))} wizard-card-icon text-secondary"></i>`,
                label: option.label,
                sub: count === null
                    ? ""
                    : `${countLabel(count, "modelo", "modelos")}`,
            };
        }),
    );
}

/** O nome comercial é o título, que é o da caixa; o interno, o dos tópicos, é o subtítulo. */
export function modelCardsHtml({ models, attrsFor, selected = "" }) {
    return cardGrid(
        "Modelo",
        models.map((model) => {
            const internal = modelInternalName(model);
            const commercial = modelCommercialName(model);
            return {
                attrs: attrsFor(internal),
                selected: internal === selected,
                visual: html`<span class="wizard-card-thumb d-flex align-items-center justify-content-center w-100">${modelPreviewHtml(model, internal)}</span>`,
                label: commercial || internal,
                sub: commercial && commercial !== internal ? internal : "",
            };
        }),
    );
}

export function supplierCardsHtml({ suppliers, attrsFor, selected = "", countFor = null }) {
    return cardGrid(
        "Fornecedor",
        suppliers.map((name) => {
            const count = countFor ? countFor(name) : null;
            return {
                attrs: attrsFor(name),
                selected: name === selected,
                visual: "",
                label: name,
                sub: count === null
                    ? ""
                    : `${countLabel(count, "modelo", "modelos")}`,
            };
        }),
    );
}
