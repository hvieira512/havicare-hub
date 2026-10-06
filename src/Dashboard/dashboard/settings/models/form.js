import { html } from "../../html.js";
import {
    saveModel as apiSaveModel,
} from "../../api/index.js";
import { ensureModelTemplate } from "../../capability-catalog.js";
import {
    invalidateDeviceTypeSuppliersModels,
    setModelPreviewObjectUrl,
    state,
} from "../../state.js";
import { apiError, toast } from "../../dialogs.js";
import { field } from "../../components/form-field.js";
import { modelImageHtml } from "../../components/model-image.js";
import {
    deviceTypeCardsHtml,
    supplierCardsHtml,
    wizardProgressHtml,
    wizardTrailHtml,
} from "../../devices/classification-ui.js";
import { createWizard } from "../../devices/wizard.js";
import { deviceTypeLabel, normalizeDeviceType } from "../../domain.js";
import { getSettingsModelsRuntime } from "./shell.js";
import {
    backToModelList,
    loadSettingsModelFilters,
    showNewModelSlide,
} from "./list.js";

/**
 * O modelo novo: o assistente dos dispositivos com três passos. Nasce com o template de
 * capacidades do fornecedor para o tipo, e por isso trocar um ou outro volta a buscá-lo.
 */

/** Um passo por pergunta: é o que o contador, a barra e a migalha contam todos igual. */
const STEPS = ["Tipo", "Fornecedor", "Informações"];

const QUESTIONS = [
    {
        key: "deviceType",
        step: 1,
        // Os fornecedores são por tipo: trocar de tipo pode deixar o escolhido de fora.
        clears: ["supplier"],
        isAnswered: (answers) => Boolean(answers.deviceType),
        badges: (answers) => [
            { label: "Tipo", value: deviceTypeLabel(answers.deviceType) },
        ],
    },
    {
        key: "supplier",
        step: 2,
        clears: [],
        isAnswered: (answers) => Boolean(answers.supplier),
        badges: (answers) => [
            { label: "Fornecedor", value: answers.supplier.name },
        ],
    },
    {
        key: "info",
        step: 3,
        clears: [],
        isAnswered: (answers) =>
            Boolean(answers.commercialName && answers.internalModel),
        badges: () => [],
    },
];

const TRAIL_QUESTIONS = QUESTIONS.map((question) => ({
    key: question.key,
    label: STEPS[question.step - 1],
}));

const questionOfStep = (step) => QUESTIONS.find((question) => question.step === step);

const wizard = createWizard({ questions: QUESTIONS, steps: STEPS });

// A imagem escolhida. Fora das respostas porque o `<input type="file">` é redesenhado a
// cada passo e não se lhe pode devolver o ficheiro.
let chosenImage = null;

/** Os fornecedores que servem este tipo de dispositivo. */
function suppliersForDeviceType(deviceType) {
    const group = (state.settingsModal.modelFilters || []).find(
        (entry) =>
            normalizeDeviceType(entry?.deviceType || entry?.device_type || "watch") ===
            normalizeDeviceType(deviceType),
    );
    return group?.suppliers || [];
}

/* ---------- desenho ---------- */

function render() {
    const { els } = getSettingsModelsRuntime();
    const step = wizard.step();

    els.modelWizardStepCount.textContent = `${step} de ${STEPS.length}`;
    els.modelWizardProgress.innerHTML = wizardProgressHtml(step, STEPS.length);
    els.modelWizardTrail.innerHTML = wizardTrailHtml({
        questions: TRAIL_QUESTIONS,
        badges: wizard.badges(),
        currentKey: questionOfStep(step).key,
    });

    els.modelWizardAsk.innerHTML = renderStep(step, wizard.answers());
    els.modelWizardTemplateSummary.classList.toggle("d-none", !wizard.isLastStep());
    renderFooter();
}

function renderStep(step, answers) {
    if (step === 1) {
        return deviceTypeCardsHtml({
            attrsFor: (value) => html`data-model-type="${value}"`,
            selected: answers.deviceType || "",
            countFor: modelCountFor,
        });
    }
    if (step === 2) {
        return renderSuppliers(answers);
    }
    return renderInfo(answers);
}

/** Quantos modelos deste tipo já existem: o mesmo subtítulo do assistente dos dispositivos. */
function modelCountFor(deviceType) {
    return (state.settingsModal.modelCatalog || [])
        .filter(
            (group) =>
                normalizeDeviceType(group?.deviceType || group?.device_type || "watch") ===
                deviceType,
        )
        .flatMap((group) => group?.suppliers || [])
        .reduce((total, supplier) => total + (supplier.models || []).length, 0);
}

function renderSuppliers(answers) {
    const suppliers = suppliersForDeviceType(answers.deviceType);
    if (suppliers.length === 0) {
        return "<p class=\"text-secondary small mb-0\">Nenhum fornecedor regista este tipo de dispositivo.</p>";
    }

    return field(
        "Fornecedor",
        supplierCardsHtml({
            suppliers: suppliers.map((supplier) => supplier.name),
            selected: answers.supplier?.name || "",
            attrsFor: (name) => html`data-model-supplier="${name}"`,
            countFor: (name) =>
                suppliers.find((supplier) => supplier.name === name)?.models?.length ?? null,
        }),
    );
}

function renderInfo(answers) {
    const preview = chosenImage
        ? modelImageHtml({ image: state.modelPreviewObjectUrl }, 40)
        : modelImageHtml({}, 40);

    return html`
        <div class="row g-3">
        <div class="col-md-6">${field(
            "Nome comercial",
            html`<input type="text" class="form-control" data-model-field="commercialName" value="${answers.commercialName || ""}">`,
            { required: true },
        )}</div>
        <div class="col-md-6">${field(
            "Modelo interno",
            html`<input type="text" class="form-control" data-model-field="internalModel" value="${answers.internalModel || ""}">`,
            { required: true, help: "O código do fabricante, que é o que os tópicos usam." },
        )}</div>
        </div>
        <div class="d-flex align-items-center gap-3 border rounded-3 bg-body-tertiary p-3 mt-3 position-relative">
        <input type="file" accept="image/*" data-model-image class="position-absolute top-0 start-0 w-100 h-100 opacity-0 cursor-pointer" title="Imagem do modelo">
        <span class="flex-shrink-0 d-flex align-items-center">${preview}</span>
        <span class="flex-grow-1 min-w-0 text-truncate text-secondary">${chosenImage?.name || "Imagem do modelo"}</span>
        <span class="btn btn-outline-secondary btn-sm flex-shrink-0">Carregar</span>
        </div>`;
}

function renderFooter() {
    const { els } = getSettingsModelsRuntime();
    const step = wizard.step();

    els.modelWizardBackBtn.classList.toggle("d-none", !wizard.canGoBack());
    els.modelWizardBackBtn.innerHTML =
        html`<i class="fa-solid fa-arrow-left me-2"></i>${STEPS[step - 2] || ""}`;

    const last = wizard.isLastStep();
    els.modelWizardSaveBtn.innerHTML = last
        ? "<i class=\"fa-solid fa-floppy-disk me-2\"></i>Guardar modelo"
        : `Seguinte: ${STEPS[step]}<i class="fa-solid fa-arrow-right ms-2"></i>`;
    els.modelWizardSaveBtn.disabled = last
        ? !wizard.isComplete()
        : !wizard.canAdvance();
}

/* ---------- interacção ---------- */

function handleModelWizardClick(event) {
    const deviceType = event.target.closest("[data-model-type]");
    if (deviceType) {
        wizard.answerAndAdvance("deviceType", deviceType.dataset.modelType);
        render();
        void refreshTemplate();
        return;
    }

    const supplier = event.target.closest("[data-model-supplier]");
    if (supplier) {
        const name = supplier.dataset.modelSupplier;
        const answers = wizard.answers();
        wizard.answerAndAdvance("supplier", {
            name,
            id: suppliersForDeviceType(answers.deviceType).find(
                (entry) => entry.name === name,
            )?.id,
        });
        render();
        void refreshTemplate();
    }
}

/** Escrever não redesenha o passo, para não tirar o cursor de baixo dos dedos. */
function handleModelWizardInput(event) {
    const input = event.target.closest("[data-model-field]");
    if (!input) return;
    wizard.answer(input.dataset.modelField, input.value.trim());
    renderFooter();
}

function handleModelWizardChange(event) {
    const input = event.target.closest("[data-model-image]");
    if (!input) return;
    chosenImage = input.files?.[0] || null;
    setModelPreviewObjectUrl(chosenImage ? URL.createObjectURL(chosenImage) : null);
    render();
}

function handleModelWizardTrailClick(event) {
    const badge = event.target.closest("[data-wizard-reopen]");
    if (!badge) return;
    wizard.reopen(badge.dataset.wizardReopen);
    render();
}

function modelWizardBack() {
    wizard.back();
    render();
}

/** O botão do rodapé: avançar nos dois primeiros passos, gravar no último. */
function handleModelWizardSave() {
    if (!wizard.isLastStep()) {
        wizard.advance();
        render();
        return;
    }
    void saveModel();
}

/* ---------- o template do fornecedor ---------- */

/**
 * As capacidades predefinidas do fornecedor para este tipo: é o que o modelo herda ao
 * nascer, e o número que o último passo anuncia.
 */
async function refreshTemplate() {
    const { els } = getSettingsModelsRuntime();
    const { deviceType, supplier } = wizard.answers();
    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateSummary = "";

    if (!supplier?.id || !deviceType) {
        els.modelWizardTemplateSummary.textContent = "";
        return;
    }

    els.modelWizardTemplateSummary.textContent =
        "A carregar template de capacidades do fornecedor.";

    const response = await ensureModelTemplate(supplier.id, deviceType);
    if (response.error) {
        state.modelModal.templateSummary = apiError(response);
        els.modelWizardTemplateSummary.textContent = state.modelModal.templateSummary;
        return;
    }

    const capabilities = Array.isArray(response.enabledCapabilities)
        ? response.enabledCapabilities.map(String)
        : [];
    state.modelModal.enabledCapabilities = capabilities;
    state.modelModal.templateSupplier = String(response.supplier || supplier.name);
    state.modelModal.templateDeviceType = String(response.deviceType || deviceType);
    state.modelModal.templateSummary =
        `${capabilities.length} capacidades predefinidas para ${state.modelModal.templateSupplier} (${deviceTypeLabel(deviceType)}).`;
    els.modelWizardTemplateSummary.textContent = state.modelModal.templateSummary;
}

/* ---------- abrir e gravar ---------- */

function resetModelWizard() {
    setModelPreviewObjectUrl();
    chosenImage = null;
    wizard.reset();
    state.modelModal.enabledCapabilities = [];
    state.modelModal.templateSummary = "";
}

async function openNewModelForm() {
    if (!state.settingsModal.sectionLoaded.modelFilters) {
        await loadSettingsModelFilters();
    }
    resetModelWizard();
    render();
    showNewModelSlide();
}

async function saveModel() {
    const { deviceType, supplier, commercialName, internalModel } = wizard.answers();
    if (!wizard.isComplete()) return;

    const body = new FormData();
    body.append("supplier_id", String(supplier.id));
    body.append("internalModel", internalModel);
    body.append("commercialName", commercialName);
    body.append("deviceType", deviceType);
    if (chosenImage) {
        body.append("image", chosenImage);
    }
    body.append("capabilitiesConfigured", "1");
    for (const feature of state.modelModal.enabledCapabilities || []) {
        body.append("capabilities[]", String(feature));
    }

    const result = await apiSaveModel("", body);
    if (result.error) {
        toast("error", apiError(result));
        return;
    }

    // Um modelo novo entra na árvore e pode trazer um par fornecedor×tipo que ainda não
    // existia: as três listas em memória deixam de valer.
    state.settingsModal.sectionLoaded.models = false;
    state.settingsModal.sectionLoaded.modelFilters = false;
    invalidateDeviceTypeSuppliersModels();
    backToModelList();
}

export {
    handleModelWizardChange,
    handleModelWizardClick,
    handleModelWizardInput,
    handleModelWizardSave,
    handleModelWizardTrailClick,
    modelWizardBack,
    openNewModelForm,
    resetModelWizard,
    saveModel,
};
