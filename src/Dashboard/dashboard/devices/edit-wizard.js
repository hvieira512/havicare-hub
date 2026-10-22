import { deviceTypeLabel } from "../domain.js";
import { state } from "../state.js";
import {
    classificationTrailHtml,
    licenseBadgeValue,
    licensePickerHtml,
} from "./classification-ui.js";
import { els } from "../dom.js";

/** A classificação no modal de edição: a verdade vive nos elementos do formulário. */

const TRAIL_QUESTIONS = [
    { key: "type", label: "Tipo" },
    { key: "model", label: "Modelo" },
    { key: "owner", label: "Licença" },
];

let onLicenseChange = () => {};
let licenseGroups = [];
let step = 2;
let openQuestion = null;

export function initEditWizard(context) {
    onLicenseChange = context.onLicenseChange || (() => {});

    els.deviceTrail?.addEventListener("click", handleTrailClick);
    els.deviceLicensePicker?.addEventListener("click", handleLicenseClick);
    els.deviceNextBtn?.addEventListener("click", () => goToStep(2));
}

/** Abre no passo 2: o que se vem alterar é a identidade, o SIM ou os gateways. */
export function resetEditWizard(groups) {
    licenseGroups = groups;
    step = 2;
    openQuestion = null;
}

/** Escolher o tipo leva ao modelo, que o anterior não existe no tipo novo; as outras fecham. */
export function editWizardAnswered(key) {
    openQuestion = key === "type" ? "model" : null;
    step = openQuestion === null ? 2 : 1;
    renderEditWizard();
}

export function renderEditWizard() {
    if (!els?.deviceTrail) return;

    renderTrail();
    renderVisibleQuestion();
    renderFooter();
}

function goToStep(next) {
    step = next;
    openQuestion = null;
    renderEditWizard();
}

function handleTrailClick(event) {
    const badge = event.target.closest("[data-wizard-reopen]");
    if (!badge) return;
    openQuestion = badge.dataset.wizardReopen;
    step = 1;
    renderEditWizard();
}

function handleLicenseClick(event) {
    const picked = event.target.closest("[data-license-pick]");
    if (!picked) return;

    els.deviceCompany.value = picked.dataset.licenseCompany || "";
    els.deviceLicenseId.value = picked.dataset.licenseId || "";
    // Os gateways autorizáveis são os da mesma empresa e licença.
    onLicenseChange();
    editWizardAnswered("owner");
}

function answerValues() {
    return {
        type: deviceTypeLabel(els.deviceForm.dataset.deviceType || "watch"),
        model: els.deviceForm.dataset.model || "—",
        owner: licenseBadgeValue(
            {
                company: els.deviceCompany.value,
                licenseId: els.deviceLicenseId.value,
            },
            licenseGroups,
        ),
    };
}

function renderTrail() {
    const values = answerValues();
    // Até o dispositivo chegar, o formulário tem a classificação de outro aparelho.
    const known = !state.deviceModal.loading;

    const shape = { questions: TRAIL_QUESTIONS, values, openKey: openQuestion || "", known };

    els.deviceTrail.innerHTML = `
        <div class="d-flex flex-column flex-md-row align-items-stretch align-items-md-center justify-content-center gap-2">
            ${classificationTrailHtml(shape)}
        </div>`;
}

function renderVisibleQuestion() {
    // `none` é o parágrafo que fica no lugar da pergunta quando não há nenhuma aberta.
    const shownKey = step === 1 ? openQuestion || "none" : "";
    for (const block of els.deviceStep1.querySelectorAll("[data-device-question]")) {
        block.classList.toggle(
            "d-none",
            block.dataset.deviceQuestion !== shownKey,
        );
    }
    els.deviceStep1.classList.toggle("d-none", step !== 1);
    els.deviceStep2.classList.toggle("d-none", step !== 2);

    if (shownKey === "owner") {
        els.deviceLicensePicker.innerHTML = licensePickerHtml(licenseGroups, {
            company: els.deviceCompany.value,
            licenseId: els.deviceLicenseId.value,
        });
    }
}

function renderFooter() {
    els.deviceNextBtn.classList.toggle("d-none", step !== 1);
}

/** Guardar com uma pergunta aberta fecha-a primeiro, para a validação não marcar escondidos. */
export function showDeviceFields() {
    if (step === 2) return;
    goToStep(2);
}
