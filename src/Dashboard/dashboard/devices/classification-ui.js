import { html } from "../html.js";
import {
    companyLabel,
    modelCommercialName,
    modelInternalName,
} from "../domain.js";
import { modelPreviewHtml } from "../components/model-image.js";
import { cardGrid } from "../components/wizard-cards.js";
import { filterBranch, filterOption } from "../components/filter-tree.js";

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
            filterBranch(group.licenses.map((license) =>
                licenseRow({
                    company: group.company,
                    licenseId: license.licenseId,
                    label: license.name
                        ? `${license.name} (${license.licenseId})`
                        : license.licenseId,
                    selected: chosen === licenseKey(group.company, license.licenseId),
                    nested: true,
                }),
            )),
        );
    }

    return html`<div class="filter-list license-picker d-flex flex-column" role="radiogroup" aria-label="Licença">
        ${rows}
    </div>`;
}

function licenseRow({ company, licenseId, label, selected, nested = false }) {
    return filterOption({
        attrs: html`role="radio" aria-checked="${selected ? "true" : "false"}" data-license-pick data-license-company="${company}" data-license-id="${licenseId}"`,
        label,
        selected,
        nested,
        radio: true,
    });
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
