import {
    createDeviceLink as apiCreateDeviceLink,
    getDevices as apiGetDevices,
    getLicenses as apiGetLicenses,
    saveDevice as apiSaveDevice,
} from "../api/index.js";
import {esc} from "../format.js";
import {state} from "../state.js";
import {modelPreviewHtml} from "../widgets.js";
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
import {createWizard} from "./wizard.js";
import {
    deviceTypeCardsHtml,
    licenseBadgeValue,
    licensePickerHtml,
    licenseTree,
    modelCardsHtml,
    supplierPillsHtml,
    wizardTrailHtml,
} from "./classification-ui.js";
import {gatewayCardMarkup} from "./gateway-links-ui.js";
import {
    ensureDeviceTypeSuppliersModelsLoaded,
    loadSummary,
} from "./list.js";

/**
 * O assistente de adicionar um dispositivo: quatro perguntas, duas em cada ecra.
 *
 * O que o distingue do formulario que substitui: mostra uma pergunta de cada vez, e cada
 * resposta colapsa numa badge. O ecra nunca acumula controlos -- tres perguntas passam
 * pelo passo 1 e ha no maximo dois campos a vista.
 *
 * O que e por tipo de dispositivo vem da tabela `DEVICE_TYPES`, e nao de ramificacoes
 * aqui. O passo 2 de um relogio tem IMEI e SIM; o de um medidor de fraldas tem MAC e
 * gateways; e nenhum dos dois esta escrito neste ficheiro.
 *
 * A meio do ficheiro comeca o que o assistente precisa do resto da aplicacao: abrir,
 * carregar as licencas e os gateways, e criar o dispositivo no fim. Estava num modulo a
 * parte com este mesmo nome noutra pasta, o que dava dois ficheiros `create-wizard.js`.
 */

let els;
let wizard;
let wizardModal = null;
let licenseGroups = [];

const STEPS = ["Classificação", "Este aparelho"];

/**
 * As perguntas. Cada uma sabe quando esta respondida, que badges produz e o que a sua
 * resposta invalida -- e nada mais. O desenho de cada uma esta em `renderQuestion`.
 */
/**
 * As perguntas que aparecem na trilha, respondidas ou nao, com o nome que levam antes de
 * ter resposta. A do passo 2 nao entra: a trilha e a classificacao, e o passo 2 e sobre
 * este aparelho em concreto.
 */
const TRAIL_QUESTIONS = [
    {key: "type", label: "Tipo"},
    {key: "model", label: "Modelo"},
    {key: "owner", label: "Licença"},
];

const QUESTIONS = [
    {
        key: "type",
        step: 1,
        clears: ["model", "identity", "gateways"],
        isAnswered: (a) => Boolean(a.type),
        badges: (a) => [{label: "Tipo", value: deviceTypeLabel(a.type)}],
    },
    {
        key: "model",
        step: 1,
        clears: ["identity"],
        isAnswered: (a) => Boolean(a.model?.supplier && a.model?.model),
        badges: (a) => [{label: "Modelo", value: a.model.model}],
    },
    {
        key: "owner",
        step: 1,
        // Os gateways que se podem autorizar sao os da mesma empresa e licenca: trocar de
        // licenca depois de os escolher deixava-os la, vindos de outro cliente.
        clears: ["gateways"],
        // Um dispositivo pode nao ter licenca, e "Sem licença" e uma resposta como as
        // outras -- por isso a pergunta nao trava o avanco enquanto ninguem lhe tocar.
        optional: true,
        isAnswered: (a) => Boolean(a.owner),
        badges: (a) => [
            {label: "Licença", value: licenseBadgeValue(a.owner, licenseGroups)},
        ],
    },
    {
        key: "identity",
        step: 2,
        clears: [],
        isAnswered: (a) => Boolean(a.identity),
        badges: () => [],
    },
];

export function initCreateWizard(context) {
    els = context.els;
    wizardModal = context.wizardModal;
    wizard = createWizard({questions: QUESTIONS, steps: STEPS});

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
 * Abre o assistente. Um dispositivo novo nao herda respostas de outro.
 *
 * `seed` sao respostas de partida, e existe por causa das notificacoes: quando o hub ve
 * um dispositivo nao autorizado, o aviso leva ao assistente com o tipo, o modelo e a
 * identidade que ele reportou ja preenchidos, para nao se escrever a mao o que o hub
 * acabou de dizer. Sao respostas normais e nao um modo especial -- o utilizador pode
 * alterar qualquer uma pela trilha.
 */
function openCreateWizard(licenseList = [], seed = {}) {
    licenseGroups = licenseList;
    wizard.reset();
    for (const [key, value] of Object.entries(seed)) {
        if (value) wizard.answer(key, value);
    }
    setError("");
    render();
}

/* ---------- desenho ---------- */

function render() {
    renderTrail();
    renderArt();
    renderAsk();
    renderFooter();
}

/**
 * A trilha, com o passo a que pertence no fim da linha.
 *
 * Eram duas linhas a dizer a mesma coisa: uma barra "1 · Classificacao / 2 · Este
 * aparelho" e, debaixo dela, os badges das respostas. Uma linha basta -- os badges dizem
 * onde se esta, e o passo diz quanto falta.
 *
 * Cada badge e um botao para a sua pergunta. O "alterar" so reabria a ultima resposta,
 * o que obrigava a refazer tudo o que viesse depois para voltar ao tipo.
 */
/**
 * As tres perguntas da classificacao estao sempre na trilha, e o passo no fim da linha.
 *
 * So as respondidas apareciam, o que deixava a linha vazia ao abrir e nao dizia quantas
 * perguntas faltavam. Uma pendente esbatida ja diz o que vem a seguir; a activa fica
 * contornada; a respondida fica cheia e e um botao para voltar aquela pergunta.
 */
function renderTrail() {
    const step = wizard.step();
    els.wizardTrail.setAttribute("aria-valuenow", String(step));
    els.wizardTrail.innerHTML = wizardTrailHtml({
        questions: TRAIL_QUESTIONS,
        badges: wizard.badges(),
        currentKey: wizard.current()?.key || "",
        step,
        steps: STEPS,
    });
}

/**
 * A imagem do modelo, que entra quando ja se sabe qual e.
 *
 * Reutiliza o `modelPreviewHtml`, que e o mesmo desenho que o modal de edicao usa -- a
 * dashboard ja serve a imagem real de cada modelo e nao ha aqui um segundo caminho.
 */
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
        <div class="wizard-art-name">${esc(chosen.supplier)} ${esc(chosen.model)}</div>
        <div class="wizard-art-sub">${esc(deviceTypeLabel(answers.type))}</div>`;
}

function renderAsk() {
    const question = wizard.current();
    els.wizardAsk.innerHTML = question ? renderQuestion(question.key) : renderStepDone();
    // Reinicia a animacao de entrada a cada pergunta nova.
    els.wizardAsk.style.animation = "none";
    void els.wizardAsk.offsetHeight;
    els.wizardAsk.style.animation = "";
}

function renderQuestion(key) {
    const answers = wizard.answers();
    switch (key) {
        case "type":
            return renderTypeGrid();
        case "model":
            return renderModel(answers.type);
        case "owner":
            return renderOwner(answers.owner);
        case "identity":
            return renderIdentity(answers);
        default:
            return "";
    }
}

function answerAndRender(key, value) {
    wizard.answerAndAdvance(key, value);
    render();
}

/**
 * O que fica no lugar da pergunta quando o passo nao tem nenhuma aberta.
 *
 * No passo 1 so se chega aqui pelo "Anterior", e o que ha para fazer e mudar uma resposta
 * -- que se faz nas etiquetas da trilha, mesmo por cima.
 */
function renderStepDone() {
    return `<p class="text-secondary small mb-0">
        ${wizard.isLastStep()
            ? "Tudo preenchido. Crie o dispositivo."
            : "Toque numa etiqueta acima para alterar uma resposta."}
    </p>`;
}

function modelCountFor(type) {
    return (state.deviceTypeSuppliersModels || []).filter(
        (model) => (model.device_type || model.deviceType) === type,
    ).length;
}

function renderTypeGrid() {
    return deviceTypeCardsHtml({
        attrsFor: (value) => `data-wizard-type="${esc(value)}"`,
        countFor: modelCountFor,
    });
}

function renderModelGrid(supplier, models) {
    return modelCardsHtml({
        models,
        attrsFor: (internal) =>
            `data-wizard-model="${esc(internal)}" data-wizard-model-supplier="${esc(supplier)}"`,
    });
}

function renderModel(type) {
    const suppliers = suppliersForDeviceType(type, state.deviceTypeSuppliersModels);
    if (suppliers.length === 0) {
        return `<p class="text-secondary small mb-0">Nenhum modelo registado para este tipo.
            Registe o modelo no catálogo antes de adicionar o dispositivo.</p>`;
    }
    const supplier = pendingSupplier ?? (suppliers.length === 1 ? suppliers[0] : null);
    const models = supplier
        ? modelsForSupplierAndType(supplier, type, state.deviceTypeSuppliersModels)
        : [];

    return `
        <div>
            <label class="form-label form-label-sm">Fornecedor</label>
            ${supplierPillsHtml({
                suppliers,
                selected: supplier,
                attrsFor: (name) => `data-wizard-supplier="${esc(name)}"`,
            })}
            ${suppliers.length === 1
                ? '<div class="form-text">Só um fornecedor tem modelos deste tipo.</div>'
                : ""}
        </div>
        ${supplier
            ? `<div>
                <label class="form-label form-label-sm">Modelo</label>
                ${models.length
                    ? renderModelGrid(supplier, models)
                    : '<p class="text-secondary small mb-0">Este fornecedor não tem modelos deste tipo.</p>'}
               </div>`
            : ""}`;
}

function renderOwner(owner) {
    return `
        <label class="form-label form-label-sm">Licença</label>
        ${licensePickerHtml(licenseGroups, owner)}`;
}

function renderIdentity(answers) {
    const fields = deviceTypeFields(answers.type);
    const gateways = eligibleGatewayList(answers);

    return `
        <div>
            <label class="form-label form-label-sm" for="wizardIdentity">${esc(fields.identity.label)}</label>
            <input type="text" class="form-control" id="wizardIdentity" data-wizard-identity
                placeholder="${esc(fields.identity.placeholder)}" value="${esc(answers.identity || "")}">
            <div class="form-text">${esc(fields.identity.help)}</div>
        </div>
        ${fields.sim
            ? `<div>
                <label class="form-label form-label-sm" for="wizardSim">Número do SIM</label>
                <input type="text" class="form-control" id="wizardSim" data-wizard-sim
                    placeholder="+351 9xx xxx xxx" value="${esc(answers.sim || "")}">
               </div>`
            : ""}
        ${fields.gatewayLinks
            ? `<div>
                <label class="form-label form-label-sm">Gateways autorizados</label>
                <div class="gateway-picker">
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
                        : '<div class="gateway-picker-empty">Nenhum gateway nesta empresa e licença.</div>'}
                </div>
                <div class="form-text">Só os selecionados podem reportar dados deste sensor.</div>
               </div>`
            : ""}`;
}

/**
 * Os gateways da mesma empresa e licença: a autorização é por par, não global.
 *
 * A ausência escreve-se de duas maneiras conforme quem a escreveu -- a base de dados
 * guarda a empresa vazia como `null` e a licença como `0`, e o assistente diz "" e "0".
 * Sem as normalizar, um gateway sem dono nunca aparecia a um sensor sem dono.
 */
function eligibleGatewayList(answers) {
    const owner = answers.owner || {};
    return (state.wizardGateways || []).filter(
        (gateway) =>
            companyKey(gateway.company) === companyKey(owner.company)
            && licenseIdKey(gateway.licenseId) === licenseIdKey(owner.licenseId),
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

function renderFooter() {
    // Escondido e nao desactivado no primeiro passo: um botao cinzento que nunca serve
    // ocupa espaco e convida a ser premido. Voltar a uma resposta ja dada faz-se pelo
    // "alterar" na trilha, que e onde ela esta.
    els.wizardBackBtn.classList.toggle("d-none", !wizard.canGoBack());
    const last = wizard.isLastStep();
    els.wizardNextBtn.innerHTML = last
        ? '<i class="fa-solid fa-plus me-2"></i>Criar dispositivo'
        : 'Seguinte<i class="fa-solid fa-arrow-right ms-2"></i>';
    els.wizardNextBtn.disabled = last ? !wizard.isComplete() : !wizard.canAdvance();
}

/* ---------- interacção ---------- */

// O fornecedor escolhido enquanto a pergunta do modelo está aberta. Não é resposta: só
// passa a ser quando o modelo também estiver escolhido, porque é o par que identifica.
let pendingSupplier = null;

function handleClick(event) {
    const type = event.target.closest("[data-wizard-type]");
    if (type) {
        pendingSupplier = null;
        answerAndRender("type", type.dataset.wizardType);
        return;
    }

    const supplier = event.target.closest("[data-wizard-supplier]");
    if (supplier) {
        pendingSupplier = supplier.dataset.wizardSupplier;
        renderAsk();
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

async function create() {
    setError("");
    els.wizardNextBtn.disabled = true;
    const error = await createDeviceFromWizard(wizard.answers());
    els.wizardNextBtn.disabled = false;
    if (error) setError(error);
}

/* ---------- o que o assistente precisa do resto da aplicacao ---------- */

/**
 * Abre o assistente.
 *
 * Carrega as licencas e os gateways antes de mostrar, para a primeira pergunta ja poder
 * dizer quantos modelos existem por tipo e a terceira ja ter a arvore de licencas.
 *
 * As licencas vem todas de uma vez e nao empresa a empresa: a arvore mostra-as todas ao
 * mesmo tempo, e uma licenca acabada de criar tem de estar la -- e a que se quer usar.
 */
export async function openWizard(source = "") {
    await ensureDeviceTypeSuppliersModelsLoaded();
    const licenses = await apiGetLicenses({limit: 500});
    await loadWizardGateways();
    openCreateWizard(
        licenseTree(licenses?.error ? [] : licenses.data || []),
        seedFromNotification(source),
    );
    wizardModal.show();
}

/**
 * As respostas que uma notificacao de dispositivo nao autorizado ja permite dar.
 *
 * O hub viu o dispositivo e disse o protocolo, o modelo reportado e a identidade. Isso
 * chega para o tipo e o modelo, e evita escrever a mao o que ele acabou de dizer. Sem
 * notificacao devolve nada, e o assistente comeca do zero.
 */
function seedFromNotification(source) {
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
            modelInternalName(model) === reported
            || modelCommercialName(model) === reported,
    ) || candidates[0] || null;

    if (!detected) return {identity};

    return {
        type: modelDeviceType(detected),
        model: {
            supplier: String(detected.supplier || ""),
            model: modelInternalName(detected),
        },
        identity,
    };
}

/** Os gateways registados, para o assistente poder oferecer os da mesma empresa. */
async function loadWizardGateways() {
    const result = await apiGetDevices({deviceType: "gateway", limit: 500});
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
 * Cria o dispositivo e, se for retransmitido, autoriza os gateways escolhidos.
 *
 * Devolve a mensagem de erro ou null: o erro desenha-se no lugar do assistente, em vez de
 * o modal ter de saber onde.
 */
async function createDeviceFromWizard(answers) {
    const fields = deviceTypeFields(answers.type);
    const identity = String(answers.identity || "").trim();
    const byImei = fields.identity.field === "imei";

    const result = await apiSaveDevice(
        identity,
        answers.model.supplier,
        answers.model.model,
        answers.type,
        // Sem empresa nao ha licenca: e o "0" que a whitelist ja usa para dizer isso.
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

    for (const gatewayKey of answers.gateways || []) {
        const linked = await apiCreateDeviceLink(gatewayKey, identity);
        if (linked?.error) {
            return `Dispositivo criado, mas não foi possível autorizar o gateway ${gatewayKey}.`;
        }
    }

    wizardModal.hide();
    await loadSummary();
    return null;
}
