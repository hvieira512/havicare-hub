import {
    createDeviceLink as apiCreateDeviceLink,
    getDevices as apiGetDevices,
    getLicenses as apiGetLicenses,
    saveDevice as apiSaveDevice,
} from "../../api/index.js";
import {state} from "../../state.js";
import {licenseTree} from "../../devices/classification-ui.js";
import {openCreateWizard} from "../../devices/create-wizard.js";
import {
    deviceTypeFields,
    ensureDeviceTypeSuppliersModelsLoaded,
    loadSummary,
    modelCommercialName,
    modelDeviceType,
    modelInternalName,
} from "../../devices/list-detail.js";

/**
 * O que o assistente de "Adicionar dispositivo" precisa do resto da aplicacao: abrir,
 * buscar as licencas de uma empresa, e criar o dispositivo no fim.
 *
 * Do estado do `bootstrap.js` so precisa do modal, para o mostrar e esconder -- nao toca
 * no `els`, porque o assistente desenha os seus proprios campos.
 */
let wizardModal = null;

export function initWizardHandlers(context) {
    wizardModal = context.wizardModal;
}

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
 * Devolve a mensagem de erro ou null, que e o contrato que o assistente espera -- ele
 * desenha o erro no seu lugar em vez de o modal saber onde.
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
