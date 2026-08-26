import {esc} from "../format.js";
import {state} from "../state.js";
import {modelPreviewHtml} from "../widgets.js";
import {
    deviceTypeFields,
    deviceTypeLabel,
    deviceTypeOptions,
    findModelInfo,
    modelCommercialName,
    modelInternalName,
    modelsForSupplierAndType,
    suppliersForDeviceType,
} from "../domain.js";
import {createWizard} from "./wizard.js";
import {
    licenseBadgeValue,
    licensePickerHtml,
} from "./classification-ui.js";
import {gatewayCardMarkup} from "./gateway-links-ui.js";

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
 */

let els;
let wizard;
let onCreate;
let licenseGroups = [];

const ICONS = {
    watch: "fa-clock",
    ncs: "fa-bell-concierge",
    radar: "fa-wifi",
    gateway: "fa-tower-broadcast",
    diaper_sensor: "fa-droplet",
    bracelet: "fa-ring",
};

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
    onCreate = context.onCreate;
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
export function openCreateWizard(licenseList = [], seed = {}) {
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
    const answered = new Map(
        wizard.badges().map((badge) => [badge.key, badge]),
    );
    const currentKey = wizard.current()?.key || "";
    const step = wizard.step();

    els.wizardTrail.setAttribute("aria-valuenow", String(step));
    els.wizardTrail.innerHTML = TRAIL_QUESTIONS
        .map((question, index) => {
            const badge = answered.get(question.key);
            const sep = index > 0 ? '<span class="wizard-trail-sep">›</span>' : "";
            if (badge) {
                return `${sep}
            <button type="button" class="wizard-badge" data-wizard-reopen="${esc(badge.key)}"
                title="Voltar a esta pergunta">
                <span class="wizard-badge-key">${esc(badge.label)}</span>${esc(String(badge.value))}
            </button>`;
            }
            const pendingClass = question.key === currentKey
                ? "wizard-badge wizard-badge-now"
                : "wizard-badge wizard-badge-pending";
            return `${sep}
            <span class="${pendingClass}">
                <span class="wizard-badge-key">${esc(question.label)}</span>
            </span>`;
        })
        .join("")
        + `<span class="wizard-trail-step">Passo ${step} de ${STEPS.length} · ${esc(STEPS[step - 1])}</span>`;
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

function renderStepDone() {
    return `<p class="text-secondary small mb-0">
        ${wizard.isLastStep()
            ? "Tudo preenchido. Crie o dispositivo."
            : "Este passo está completo. Siga para o próximo."}
    </p>`;
}

function modelCountFor(type) {
    return (state.deviceTypeSuppliersModels || []).filter(
        (model) => (model.device_type || model.deviceType) === type,
    ).length;
}

/**
 * A grelha de escolhas em cards, partilhada pelo tipo de dispositivo e pelo modelo.
 *
 * `visual` e um icone ou uma miniatura: um tipo de dispositivo e uma ideia e leva icone,
 * um modelo e um objecto que existe e leva a fotografia. O resto da forma e a mesma, e
 * duplica-la para o modelo era duplicar tambem os estados de foco e de selecao.
 */
function cardGrid(label, cards) {
    return `
        <div class="wizard-card-grid" role="group" aria-label="${esc(label)}">
            ${cards
                .map(
                    (card) => `
                <button type="button" class="wizard-card" ${card.attrs}>
                    ${card.visual}
                    <span class="wizard-card-label">${esc(card.label)}</span>
                    ${card.sub ? `<span class="wizard-card-sub">${esc(card.sub)}</span>` : ""}
                </button>`,
                )
                .join("")}
        </div>`;
}

function renderTypeGrid() {
    return cardGrid(
        "Tipo de dispositivo",
        deviceTypeOptions.map((option) => {
            const count = modelCountFor(option.value);
            return {
                attrs: `data-wizard-type="${esc(option.value)}"`,
                visual: `<i class="fa-solid ${esc(ICONS[option.value] || "fa-microchip")} wizard-card-icon"></i>`,
                label: option.label,
                sub: `${count} ${count === 1 ? "modelo" : "modelos"}`,
            };
        }),
    );
}

/**
 * Os modelos do fornecedor escolhido, com fotografia.
 *
 * O nome comercial e o titulo e o modelo interno o subtitulo: e o comercial que a pessoa
 * reconhece da caixa, e o interno que aparece depois nos tópicos e na base de dados.
 */
function renderModelGrid(supplier, models) {
    return cardGrid(
        "Modelo",
        models.map((model) => {
            const internal = modelInternalName(model);
            const commercial = modelCommercialName(model);
            return {
                attrs: `data-wizard-model="${esc(internal)}" data-wizard-model-supplier="${esc(supplier)}"`,
                visual: `<span class="wizard-card-thumb">${modelPreviewHtml(model, internal)}</span>`,
                label: commercial || internal,
                sub: commercial && commercial !== internal ? internal : "",
            };
        }),
    );
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
            <div class="d-flex flex-wrap gap-2">
                ${suppliers
                    .map(
                        (name) => `
                    <button type="button" class="btn btn-sm ${name === supplier ? "btn-primary" : "btn-outline-secondary"}"
                        data-wizard-supplier="${esc(name)}">${esc(name)}</button>`,
                    )
                    .join("")}
            </div>
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
        wizard.answer("type", type.dataset.wizardType);
        render();
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
        wizard.answer("model", {
            supplier: model.dataset.wizardModelSupplier,
            model: model.dataset.wizardModel,
        });
        render();
        return;
    }

    const license = event.target.closest("[data-license-pick]");
    if (license) {
        wizard.answer("owner", {
            company: license.dataset.licenseCompany || "",
            licenseId: license.dataset.licenseId || "0",
        });
        render();
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
    const error = await onCreate(wizard.answers());
    els.wizardNextBtn.disabled = false;
    if (error) setError(error);
}
