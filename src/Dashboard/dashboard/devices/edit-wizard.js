import { deviceTypeLabel } from "../domain.js";
import { state } from "../state.js";
import {
    licenseBadgeValue,
    licensePickerHtml,
    wizardTrailHtml,
} from "./classification-ui.js";

/**
 * A classificação no modal de edição: num dispositivo já registado o tipo e o modelo são
 * respostas dadas, e colapsam em etiquetas.
 *
 * Não usa o motor do `wizard.js` de propósito -- a verdade sobre o tipo, o modelo e a licença
 * vive nos elementos do formulário, que é de onde o `saveDevice` a lê, e uma segunda cópia
 * eram duas que podiam discordar.
 */

const TRAIL_QUESTIONS = [
    { key: "type", label: "Tipo" },
    { key: "model", label: "Modelo" },
    { key: "owner", label: "Licença" },
];

let els;
let onLicenseChange = () => {};
let licenseGroups = [];
let step = 2;
let openQuestion = null;

export function initEditWizard(context) {
    els = context.els;
    onLicenseChange = context.onLicenseChange || (() => {});

    els.deviceTrail?.addEventListener("click", handleTrailClick);
    els.deviceLicensePicker?.addEventListener("click", handleLicenseClick);
    els.deviceNextBtn?.addEventListener("click", () => goToStep(2));
}

/**
 * Prepara o modal para um dispositivo. Abre no passo 2 e não no 1: o que se costuma vir
 * alterar é o número de série, o SIM ou os gateways, e a classificação já está feita.
 */
export function resetEditWizard(groups = []) {
    licenseGroups = groups;
    step = 2;
    openQuestion = null;
}

/**
 * Uma resposta dada no passo 1. Escolher o tipo leva ao modelo, porque o modelo anterior
 * não existe no tipo novo e o que fica escolhido é um palpite. As outras fecham o passo.
 */
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
    els.deviceLicenseId.value = picked.dataset.licenseId || "0";
    // Os gateways autorizáveis são os da mesma empresa e licença.
    onLicenseChange();
    editWizardAnswered("owner");
}

/** As respostas, lidas dos elementos do formulário -- que é onde elas vivem. */
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
    // Enquanto o dispositivo não chegou, o formulário tem o que lá estava por omissão, que
    // é a classificação de outro aparelho: sem badges, a trilha diz o que se sabe -- nada.
    const known = !state.deviceModal.loading;

    els.deviceTrail.setAttribute("aria-valuenow", String(step));
    els.deviceTrail.innerHTML = wizardTrailHtml({
        questions: TRAIL_QUESTIONS,
        // A pergunta aberta não leva badge: o valor antigo está marcado na grelha abaixo.
        badges: TRAIL_QUESTIONS
            .filter((question) => known && question.key !== openQuestion)
            .map((question) => ({
                key: question.key,
                label: question.label,
                value: values[question.key],
            })),
        currentKey: openQuestion || "",
        step,
        // Sem contador: ver `wizardTrailHtml`. As etiquetas continuam a ser a saída para
        // voltar a uma pergunta, que é o que aqui faz falta.
        steps: [],
    });
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

/**
 * Traz os campos do aparelho de volta, se uma pergunta estava aberta. O `Guardar` nunca
 * desaparece, e por isso guardar com uma pergunta aberta fecha-a primeiro -- senão a
 * validação marcava campos escondidos e o pedido falhava sem nada que se visse.
 */
export function showDeviceFields() {
    if (step === 2) return;
    goToStep(2);
}
