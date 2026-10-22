import { html } from "../html.js";
import { deviceTypeOptions } from "../domain.js";
import { deviceTypeIcon } from "./device-type-tiles.js";
import { countLabel } from "../i18n.js";

/** A migalha, a barra de progresso e as grelhas de tipo e fornecedor dos assistentes de aparelho e de modelo. */

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
