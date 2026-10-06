import { html } from "../html.js";
import {
    createDeviceLink as apiCreateDeviceLink,
    getDevices as apiGetDevices,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../licenses.js";
import { toast } from "../dialogs.js";
import { state } from "../state.js";
import { field } from "../components/form-field.js";
import { modelPreviewHtml } from "../components/model-image.js";
import {
    deviceTypeFields,
    deviceTypeLabel,
    findModelInfo,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
    modelsForSupplierAndType,
    suppliersForDeviceType,
} from "../domain.js";
import { createWizard } from "./wizard.js";
import {
    deviceTypeCardsHtml,
    licenseBadgeValue,
    licensePickerHtml,
    licenseTree,
    modelCardsHtml,
    ownerFromLicense,
    supplierCardsHtml,
    wizardProgressHtml,
    wizardTrailHtml,
} from "./classification-ui.js";
import { gatewayCardMarkup } from "./gateway-links-ui.js";
import {
    ensureDeviceTypeSuppliersModelsLoaded,
    loadSummary,
} from "./list.js";

/**
 * O assistente de adicionar um dispositivo, uma pergunta por passo. O que varia por tipo vem
 * da tabela `DEVICE_TYPES` e não de ramificações aqui.
 */

let els;
let wizard;
let wizardModal = null;
let licenseGroups = [];

/** Um passo por pergunta: é o que o contador, a barra e a migalha contam todos igual. */
const STEPS = ["Tipo", "Fornecedor", "Modelo", "Licença", "Identificação"];

/** Cada pergunta sabe quando está respondida, que badges produz e o que invalida. */
const QUESTIONS = [
    {
        key: "type",
        step: 1,
        clears: ["supplier", "model", "identity", "gateways"],
        isAnswered: (a) => Boolean(a.type),
        badges: (a) => [{ label: "Tipo", value: deviceTypeLabel(a.type) }],
    },
    {
        key: "supplier",
        step: 2,
        clears: ["model", "identity"],
        isAnswered: (a) => Boolean(a.supplier),
        badges: (a) => [{ label: "Fornecedor", value: a.supplier }],
    },
    {
        key: "model",
        step: 3,
        clears: ["identity"],
        isAnswered: (a) => Boolean(a.model?.supplier && a.model?.model),
        badges: (a) => [{ label: "Modelo", value: a.model.model }],
    },
    {
        key: "owner",
        step: 4,
        // Os gateways autorizáveis são os da mesma empresa e licença, daí trocar de
        // licença limpar os que já estavam escolhidos.
        clears: ["gateways"],
        // "Sem licença" é uma resposta como as outras, por isso não trava o avanço.
        optional: true,
        isAnswered: (a) => Boolean(a.owner),
        badges: (a) => [
            { label: "Licença", value: licenseBadgeValue(a.owner, licenseGroups) },
        ],
    },
    {
        key: "identity",
        step: 5,
        clears: [],
        isAnswered: (a) => Boolean(a.identity),
        badges: () => [],
    },
];

const TRAIL_QUESTIONS = QUESTIONS.map((question) => ({
    key: question.key,
    label: STEPS[question.step - 1],
}));

const questionOfStep = (step) => QUESTIONS.find((question) => question.step === step);

export function initCreateWizard(context) {
    els = context.els;
    wizardModal = context.wizardModal;
    wizard = createWizard({ questions: QUESTIONS, steps: STEPS });

    els.wizardAsk?.addEventListener("click", handleClick);
    els.wizardAsk?.addEventListener("change", handleChange);
    els.wizardAsk?.addEventListener("input", handleInput);
    els.wizardTrail?.addEventListener("click", handleTrailClick);
    els.wizardBackBtn?.addEventListener("click", () => {
        wizard.back();
        render();
    });
    els.wizardNextBtn?.addEventListener("click", () => {
        if (wizard.isLastStep()) {
            void create();
            return;
        }
        wizard.advance();
        render();
    });
}

/**
 * `seed` são respostas de partida, tiradas da notificação de um dispositivo não autorizado;
 * alteram-se na trilha como as outras.
 */
function openCreateWizard(licenseList = [], seed = {}) {
    licenseGroups = licenseList;
    wizard.reset();
    for (const [key, value] of Object.entries(seed)) {
        if (value) wizard.answer(key, value);
    }
    answerSingleSupplier();
    // Abre onde há alguma coisa para fazer: para no primeiro passo com uma pergunta por
    // responder, e nunca salta o último -- criar é um clique deliberado.
    while (wizard.current() === null && wizard.canAdvance()) {
        wizard.advance();
    }
    setError("");
    render();
}

/** Um tipo com um só fornecedor não tem nada a perguntar: a única resposta dá-se sozinha. */
function answerSingleSupplier() {
    const answers = wizard.answers();
    if (!answers.type || answers.supplier) return;
    const suppliers = suppliersForDeviceType(answers.type, state.deviceTypeSuppliersModels);
    if (suppliers.length === 1) wizard.answerAndAdvance("supplier", suppliers[0]);
}

/* ---------- desenho ---------- */

function render() {
    renderHead();
    renderArt();
    renderAsk();
    renderFooter();
}

/** O contador, a barra e a migalha dizem a mesma coisa em três formas: passo x de cinco. */
function renderHead() {
    const step = wizard.step();
    els.wizardStepCount.textContent = `${step} de ${STEPS.length}`;
    els.wizardProgress.innerHTML = wizardProgressHtml(step, STEPS.length);
    els.wizardTrail.innerHTML = wizardTrailHtml({
        questions: TRAIL_QUESTIONS,
        badges: wizard.badges(),
        currentKey: questionOfStep(step).key,
    });
}

/** A imagem do modelo, pelo `modelPreviewHtml` -- o mesmo desenho do modal de edição. */
function renderArt() {
    const answers = wizard.answers();
    const chosen = answers.model;
    els.wizardArt.classList.toggle("d-none", !chosen);
    if (!chosen) {
        els.wizardArt.innerHTML = "";
        return;
    }
    const info = findModelInfo(chosen.supplier, chosen.model, state.deviceTypeSuppliersModels);
    els.wizardArt.innerHTML = `
        ${modelPreviewHtml(info, chosen.model)}
        <div class="wizard-art-name fw-semibold text-center">${chosen.supplier} ${chosen.model}</div>
        <div class="wizard-art-sub text-secondary text-center">${(deviceTypeLabel(answers.type))}</div>`;
}

/**
 * A pergunta do passo actual, com a escolha marcada. O `handleInput` responde sem redesenhar,
 * para não tirar o cursor de baixo dos dedos.
 */
function renderAsk() {
    const question = questionOfStep(wizard.step());
    els.wizardAsk.innerHTML = renderQuestion(question.key);
    // Reinicia a animação de entrada a cada pergunta nova.
    els.wizardAsk.style.animation = "none";
    void els.wizardAsk.offsetHeight;
    els.wizardAsk.style.animation = "";
}

function renderQuestion(key) {
    const answers = wizard.answers();
    switch (key) {
        case "type":
            return renderTypeGrid(answers.type);
        case "supplier":
            return renderSupplier(answers.type, answers.supplier);
        case "model":
            return renderModel(answers.type, answers.supplier, answers.model?.model);
        case "owner":
            return renderOwner(answers.owner);
        case "identity":
            return renderIdentity(answers);
        default:
            return "";
    }
}

function answerAndRender(key, value) {
    if (sameAnswer(wizard.answers()[key], value)) {
        // Repetir a resposta que já lá estava não invalida as seguintes: só segue em frente.
        if (wizard.canAdvance()) wizard.advance();
    } else {
        wizard.answerAndAdvance(key, value);
        answerSingleSupplier();
    }
    render();
}

function sameAnswer(previous, value) {
    return JSON.stringify(previous ?? null) === JSON.stringify(value);
}

function modelCountFor(type) {
    return (state.deviceTypeSuppliersModels || []).filter(
        (model) => (model.device_type || model.deviceType) === type,
    ).length;
}

function renderTypeGrid(selected) {
    return deviceTypeCardsHtml({
        attrsFor: (value) => html`data-wizard-type="${value}"`,
        countFor: modelCountFor,
        selected: selected || "",
    });
}

function renderSupplier(type, selected) {
    const suppliers = suppliersForDeviceType(type, state.deviceTypeSuppliersModels);
    if (suppliers.length === 0) {
        return html`<p class="text-secondary small mb-0">Nenhum modelo registado para este tipo.
            Registe o modelo no catálogo antes de adicionar o dispositivo.</p>`;
    }

    return field(
        "Fornecedor",
        supplierCardsHtml({
            suppliers,
            selected: selected || "",
            attrsFor: (name) => html`data-wizard-supplier="${name}"`,
        }),
        { help: suppliers.length === 1 ? "Só um fornecedor tem modelos deste tipo." : "" },
    );
}

function renderModel(type, supplier, selected) {
    const models = modelsForSupplierAndType(supplier, type, state.deviceTypeSuppliersModels);
    if (models.length === 0) {
        return "<p class=\"text-secondary small mb-0\">Este fornecedor não tem modelos deste tipo.</p>";
    }

    return modelCardsHtml({
        models,
        selected: selected || "",
        attrsFor: (internal) =>
            html`data-wizard-model="${internal}" data-wizard-model-supplier="${supplier}"`,
    });
}

function renderOwner(owner) {
    return html`
        <label class="form-label-sm">Licença</label>
        ${licensePickerHtml(licenseGroups, owner)}`;
}

function renderIdentity(answers) {
    const fields = deviceTypeFields(answers.type);
    const gateways = eligibleGatewayList(answers);

    return html`
        <div>
            <label class="form-label-sm" for="wizardIdentity">${fields.identity.label}</label>
            <input type="text" class="form-control" id="wizardIdentity" data-wizard-identity
                placeholder="${fields.identity.placeholder}" value="${answers.identity || ""}">
            <div class="form-text">${fields.identity.help}</div>
        </div>
        ${fields.sim
            ? html`<div>
                <label class="form-label-sm" for="wizardSim">Número do SIM</label>
                <input type="text" class="form-control" id="wizardSim" data-wizard-sim
                    value="${answers.sim || ""}">
               </div>`
            : ""}
        ${fields.gatewayLinks
            ? html`<div>
                <label class="form-label-sm">Gateways autorizados</label>
                <div class="gateway-picker d-grid gap-2 overflow-y-auto">
                    ${gateways.length
                        ? gateways
                                .map((gateway) =>
                                    gatewayCardMarkup(
                                        gateway,
                                        (answers.gateways || []).includes(
                                            String(gateway.imei || "").toLowerCase(),
                                        ),
                                    ),
                                )
                                .join("")
                        : "<div class=\"gateway-picker-empty small text-secondary\">Nenhum gateway nesta empresa e licença.</div>"}
                </div>
                <div class="form-text">Só os selecionados podem reportar dados deste sensor.</div>
               </div>`
            : ""}`;
}

/**
 * Os gateways da mesma empresa e licença: a autorização é por par, não global. A ausência de
 * dono escreve-se de duas maneiras, e as chaves normalizam-nas.
 */
function eligibleGatewayList(answers) {
    const owner = answers.owner || {};
    return (state.wizardGateways || []).filter(
        (gateway) =>
            companyKey(gateway.company) === companyKey(owner.company) &&
            licenseIdKey(gateway.licenseId) === licenseIdKey(owner.licenseId),
    );
}

function companyKey(value) {
    const name = String(value ?? "").trim();
    return name === "null" ? "" : name;
}

function licenseIdKey(value) {
    const id = String(value ?? "").trim();
    return id === "" ? "0" : id;
}

/** Os dois botões nomeiam o passo para onde levam. */
function renderFooter() {
    const step = wizard.step();
    const last = wizard.isLastStep();

    // Escondido e não desactivado no primeiro passo: um botão cinzento que nunca serve
    // convida a ser premido.
    els.wizardBackBtn.classList.toggle("d-none", !wizard.canGoBack());
    els.wizardBackBtn.innerHTML =
        html`<i class="fa-solid fa-arrow-left me-2"></i>${STEPS[step - 2] || ""}`;
    els.wizardNextBtn.innerHTML = last
        ? "<i class=\"fa-solid fa-plus me-2\"></i>Criar dispositivo"
        : `Seguinte: ${STEPS[step]}<i class="fa-solid fa-arrow-right ms-2"></i>`;
    // Com um POST no ar o botão fica desligado, mesmo que escrever num campo redesenhe o rodapé.
    els.wizardNextBtn.disabled = creating ||
        (last ? !wizard.isComplete() : !wizard.canAdvance());
}

/* ---------- interacção ---------- */

function handleClick(event) {
    const type = event.target.closest("[data-wizard-type]");
    if (type) {
        answerAndRender("type", type.dataset.wizardType);
        return;
    }

    const supplier = event.target.closest("[data-wizard-supplier]");
    if (supplier) {
        answerAndRender("supplier", supplier.dataset.wizardSupplier);
        return;
    }

    const model = event.target.closest("[data-wizard-model]");
    if (model) {
        answerAndRender("model", {
            supplier: model.dataset.wizardModelSupplier,
            model: model.dataset.wizardModel,
        });
        return;
    }

    const license = event.target.closest("[data-license-pick]");
    if (license) {
        answerAndRender("owner", {
            company: license.dataset.licenseCompany || "",
            licenseId: license.dataset.licenseId || "0",
        });
    }
}

function handleTrailClick(event) {
    const badge = event.target.closest("[data-wizard-reopen]");
    if (!badge) return;
    wizard.reopen(badge.dataset.wizardReopen);
    render();
}

function handleChange(event) {
    const gateway = event.target.closest("[data-gateway-key]");
    if (gateway) {
        const chosen = [
            ...els.wizardAsk.querySelectorAll("[data-gateway-key]:checked"),
        ].map((input) => input.dataset.gatewayKey);
        wizard.answer("gateways", chosen);
        renderFooter();
    }
}

function handleInput(event) {
    const identity = event.target.closest("[data-wizard-identity]");
    if (identity) {
        wizard.answer("identity", identity.value.trim());
        renderFooter();
        return;
    }
    const sim = event.target.closest("[data-wizard-sim]");
    if (sim) {
        wizard.answer("sim", sim.value.trim());
    }
}

function setError(message) {
    els.wizardError.textContent = message;
    els.wizardError.classList.toggle("d-none", message === "");
}

let creating = false;

async function create() {
    if (creating) return;
    setError("");
    creating = true;
    els.wizardNextBtn.disabled = true;
    try {
        const error = await createDeviceFromWizard(wizard.answers());
        if (error) setError(error);
    } finally {
        creating = false;
        els.wizardNextBtn.disabled = false;
    }
}

/* ---------- o que o assistente precisa do resto da aplicação ---------- */

/** Abre o assistente com as licenças, todas de uma vez, e os gateways já carregados. */
export async function openWizard(source = "") {
    await ensureDeviceTypeSuppliersModelsLoaded();
    const licenses = await ensureLicensesLoaded();
    await loadWizardGateways();
    const tree = licenseTree(licenses ?? []);
    openCreateWizard(tree, seedFromNotification(source, tree));
    wizardModal.show();
}

/**
 * As respostas que uma notificação de dispositivo não autorizado já permite dar: o hub
 * disse o protocolo, o modelo reportado e a identidade. Sem notificação, começa do zero.
 */
function seedFromNotification(source, tree = []) {
    const notification = source && typeof source === "object" ? source : null;
    const identity = String(notification?.imei || source || "").trim();
    if (identity === "") return {};

    const protocol = String(notification?.protocol || "").trim();
    const reported = String(notification?.model || "").trim();
    const candidates = (state.deviceTypeSuppliersModels || []).filter(
        (model) => String(model.protocol || "") === protocol,
    );
    const detected = candidates.find(
        (model) =>
            modelInternalName(model) === reported ||
            modelCommercialName(model) === reported,
    ) || candidates[0] || null;

    const owner = ownerFromLicense(notification?.licenseId, tree, notification?.company);
    if (!detected) return owner ? { identity, owner } : { identity };

    return {
        type: modelDeviceType(detected),
        supplier: String(detected.supplier || ""),
        model: {
            supplier: String(detected.supplier || ""),
            model: modelInternalName(detected),
        },
        identity,
        ...(owner ? { owner } : {}),
    };
}

/** Os gateways registados, para o assistente poder oferecer os da mesma empresa. */
async function loadWizardGateways() {
    const result = await apiGetDevices({ deviceType: "gateway", limit: 500 });
    state.wizardGateways = result?.error
        ? []
        : (result.data || []).map((device) => ({
                imei: String(device.imei || "").toLowerCase(),
                model: device.model || "",
                image: device.image || "",
                company: device.company || "",
                licenseId: device.licenseId ?? device.license_id ?? "",
            }));
}

/**
 * Cria o dispositivo e autoriza os gateways escolhidos; devolve a mensagem de erro ou null. Se
 * só a autorização falha, o dispositivo já existe: o assistente fecha e avisa o que faltou.
 */
export async function createDeviceFromWizard(answers) {
    const fields = deviceTypeFields(answers.type);
    const identity = String(answers.identity || "").trim();
    const byImei = fields.identity.field === "imei";

    const result = await apiSaveDevice(
        identity,
        answers.model.supplier,
        answers.model.model,
        answers.type,
        // Sem empresa não há licença: é o "0" que a whitelist já usa para dizer isso.
        String(answers.owner?.licenseId || "0"),
        fields.sim ? String(answers.sim || "") : "",
        byImei ? "" : identity,
        "",
        answers.owner?.company || "",
    );
    if (result?.error) {
        return result._httpStatus === 409
            ? "Já existe um dispositivo com esta identidade."
            : result.error.message || result.error.code;
    }

    const unauthorized = [];
    for (const gatewayKey of answers.gateways || []) {
        const linked = await apiCreateDeviceLink(gatewayKey, identity);
        if (linked?.error) {
            unauthorized.push(gatewayKey);
        }
    }

    wizardModal.hide();
    await loadSummary();
    if (unauthorized.length > 0) {
        // Autoriza-se no modal de edição, que é onde os gateways de um dispositivo se mexem.
        toast(
            "warning",
            "Dispositivo criado, com gateways por autorizar",
            `Ficou por autorizar: ${unauthorized.join(", ")}. Pode fazê-lo em Editar.`,
        );
    }
    return null;
}
