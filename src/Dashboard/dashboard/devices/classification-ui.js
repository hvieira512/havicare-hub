import {esc} from "../format.js";
import {companyLabel} from "../domain.js";

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
