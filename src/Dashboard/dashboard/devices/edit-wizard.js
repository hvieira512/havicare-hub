import {deviceTypeLabel} from "../domain.js";
import {state} from "../state.js";
import {
    licenseBadgeValue,
    licensePickerHtml,
    wizardTrailHtml,
} from "./classification-ui.js";

/**
 * A classificacao no modal de edicao, com a mesma forma do assistente de adicionar.
 *
 * O separador Geral tinha os tres controlos da classificacao sempre abertos -- o mosaico
 * de tipos, as pastilhas de fornecedor e as de modelo -- por cima do MAC e dos gateways.
 * Eram trinta e tal controlos a vista para editar um numero de serie, e um dispositivo ja
 * registado tem tipo e modelo: sao respostas dadas, nao perguntas por fazer. Aqui
 * colapsam em etiquetas, e so se abrem quando se toca numa.
 *
 * Nao usa o motor do `wizard.js` de proposito. Num dispositivo que existe nao ha respostas
 * em falta -- o estado e so qual a pergunta aberta e em que passo se esta, duas variaveis.
 * A verdade sobre o tipo, o modelo e a licenca continua a viver nos elementos do
 * formulario, que e de onde o `saveDevice` e o separador das configuracoes a leem; ter
 * uma segunda copia dela dentro de um motor era ter duas que podiam discordar.
 */

const STEPS = ["Classificação", "Este aparelho"];

const TRAIL_QUESTIONS = [
    {key: "type", label: "Tipo"},
    {key: "model", label: "Modelo"},
    {key: "owner", label: "Licença"},
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
    els.deviceBackBtn?.addEventListener("click", () => goToStep(1));
    els.deviceNextBtn?.addEventListener("click", () => goToStep(2));
}

/**
 * Prepara o modal para um dispositivo.
 *
 * Abre no passo 2 e nao no 1: o que se costuma vir alterar e o numero de serie, o SIM ou
 * os gateways, e a classificacao ja esta feita -- fica na trilha, a vista e a um clique.
 */
export function resetEditWizard(groups = []) {
    licenseGroups = groups;
    step = 2;
    openQuestion = null;
}

/**
 * Uma resposta dada no passo 1.
 *
 * Escolher o tipo leva ao modelo, porque o modelo anterior nao existe no tipo novo e o
 * que fica escolhido e o primeiro da lista -- um palpite que tem de ser confirmado.
 * Qualquer outra resposta fecha o passo.
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
    // Os gateways que se podem autorizar sao os da mesma empresa e licenca.
    onLicenseChange();
    editWizardAnswered("owner");
}

/** As respostas, lidas dos elementos do formulario -- que sao onde elas vivem. */
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
    // Enquanto o dispositivo nao chegou, o formulario ainda tem o que la estava por
    // omissao: mostrar isso era escrever a classificacao de outro aparelho. Sem badges, a
    // trilha fica com as tres perguntas esbatidas, que e o que se sabe -- nada.
    const known = !state.deviceModal.loading;

    els.deviceTrail.setAttribute("aria-valuenow", String(step));
    els.deviceTrail.innerHTML = wizardTrailHtml({
        questions: TRAIL_QUESTIONS,
        // A pergunta aberta nao leva badge: e a que se esta a responder, e o valor antigo
        // esta na grelha por baixo, marcado.
        badges: TRAIL_QUESTIONS
            .filter((question) => known && question.key !== openQuestion)
            .map((question) => ({
                key: question.key,
                label: question.label,
                value: values[question.key],
            })),
        currentKey: openQuestion || "",
        step,
        steps: STEPS,
    });
}

function renderVisibleQuestion() {
    // `none` e o paragrafo que fica no lugar da pergunta quando nao ha nenhuma aberta.
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
    els.deviceBackBtn.classList.toggle("d-none", step !== 2);
    els.deviceNextBtn.classList.toggle("d-none", step !== 1);
    // Guardar so no passo do aparelho: e o unico onde ha campos por validar, e responder
    // no passo 1 traz para ca sozinho.
    els.saveDeviceBtn.classList.toggle("d-none", step !== 2);
}
