import {
    createDeviceLink as apiCreateDeviceLink,
    deleteDeviceLink as apiDeleteDeviceLink,
    getDevices as apiGetDevices,
} from "../api/index.js";
import { esc } from "../format.js";
import { state } from "../state.js";
import { linksToGateway, normalizeDeviceType } from "../domain.js";
import { eligibleGateways, gatewayLinkChanges } from "./gateway-links.js";
import { disposeTooltips, refreshTooltips } from "../tooltips.js";
import { linkSignal, signalMeter } from "./gateway-signal.js";

/**
 * O escolhedor de gateways no modal do dispositivo: com que gateways um sensor pode falar,
 * em cards seleccionáveis. Recebe o mapa de elementos pelo `initGatewayLinksUi`, como os
 * outros módulos de vista.
 */

let els;

export function initGatewayLinksUi(context) {
    els = context.els;
}

export function selectedGatewayKeys() {
    return [
        ...(els.deviceGatewayLinksList?.querySelectorAll(
            "input[type=checkbox][data-gateway-key]",
        ) || []),
    ]
        .filter((input) => input.checked)
        .map((input) => String(input.dataset.gatewayKey || "").trim().toLowerCase())
        .filter(Boolean);
}

function syncGatewayLinkButtons() {
    const inputs = [
        ...(els.deviceGatewayLinksList?.querySelectorAll(
            "input[type=checkbox][data-gateway-key]",
        ) || []),
    ];
    if (els.deviceGatewayLinksSelectAllBtn) {
        els.deviceGatewayLinksSelectAllBtn.disabled =
            inputs.length === 0 || inputs.every((input) => input.checked);
    }
    if (els.deviceGatewayLinksClearBtn) {
        els.deviceGatewayLinksClearBtn.disabled =
            inputs.length === 0 || inputs.every((input) => !input.checked);
    }
}

export function updateGatewayLinkSelection() {
    state.deviceModal.selectedGatewayKeys = selectedGatewayKeys();
    if (els.deviceGatewayLinksCount) {
        els.deviceGatewayLinksCount.textContent = String(
            state.deviceModal.selectedGatewayKeys.length,
        );
    }
    syncGatewayLinkButtons();
}

function setGatewayLinksDisabled(disabled) {
    els.deviceGatewayLinksList
        ?.querySelectorAll("input[type=checkbox]")
        .forEach((input) => {
            input.disabled = disabled;
        });
    if (disabled) {
        if (els.deviceGatewayLinksSelectAllBtn) {
            els.deviceGatewayLinksSelectAllBtn.disabled = true;
        }
        if (els.deviceGatewayLinksClearBtn) {
            els.deviceGatewayLinksClearBtn.disabled = true;
        }
    } else {
        syncGatewayLinkButtons();
    }
}

const GATEWAY_THUMB_PLACEHOLDER = `<svg class="gateway-card-thumb-icon" viewBox="0 0 24 24" fill="none"
    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    <rect x="2.5" y="13.5" width="19" height="7" rx="1.75"></rect>
    <path d="M6 17h.01M9.5 17h5"></path>
    <path d="M12 10.5v-7M8.75 6.75 12 3.5l3.25 3.25"></path>
</svg>`;

/**
 * O card de um gateway. Exportado para o assistente de adicionar o reutilizar: as classes e
 * os estados de foco vivem no CSS, e duplicar a marcação era duplicar essa contratação.
 */
export function gatewayCardMarkup(gateway, checked, signal = null) {
    const key = String(gateway.imei || "").trim().toLowerCase();
    const model = String(gateway.model || "").trim();
    const image = String(gateway.image || "").trim();
    const thumb = image
        ? `<img src="${esc(image)}" alt="" loading="lazy" decoding="async">`
        : GATEWAY_THUMB_PLACEHOLDER;

    return `<label class="gateway-card">
        <input class="form-check-input gateway-card-check" type="checkbox" data-gateway-key="${esc(key)}"${checked ? " checked" : ""}>
        <span class="gateway-card-thumb">${thumb}</span>
        <span class="gateway-card-text">
            <span class="gateway-card-mac">${esc(key)}</span>
            <span class="gateway-card-model">${esc(model || "Modelo desconhecido")}</span>
        </span>
        ${signalMeter(signal)}
    </label>`;
}

/**
 * Os sinais viajam nas linhas de ligação do próprio sensor, por isso só se aplicam enquanto
 * o modal estiver a editar o dispositivo escolhido na coluna de detalhe. A editar outro não
 * mostra sinal nenhum, em vez de mostrar o de outro dispositivo.
 */
function signalsForEditedDevice() {
    const editing = String(els.deviceImei?.value || "").trim().toLowerCase();
    const selected = String(
        state.selectedDetail?.device?.imei || "",
    ).trim().toLowerCase();
    if (!editing || editing !== selected) return new Map();

    const signals = new Map();
    for (const linked of state.selectedDetail?.linkedDevices || []) {
        const signal = linkSignal(linked);
        const key = String(linked.deviceKey || "").trim().toLowerCase();
        if (key && signal) signals.set(key, signal);
    }

    return signals;
}

function renderGatewayOptions(gateways = [], selectedKeys = [], emptyText = "") {
    const list = els.deviceGatewayLinksList;
    if (!list) return;

    const selected = new Set(
        selectedKeys.map((key) => String(key || "").trim().toLowerCase()),
    );
    disposeTooltips(list);
    if (gateways.length === 0) {
        list.innerHTML = emptyText
            ? `<p class="gateway-picker-empty">${esc(emptyText)}</p>`
            : "";
    } else {
        const signals = signalsForEditedDevice();
        list.innerHTML = gateways
            .map((gateway) => {
                const key = String(gateway.imei || "").trim().toLowerCase();
                return gatewayCardMarkup(
                    gateway,
                    selected.has(key),
                    signals.get(key) || null,
                );
            })
            .join("");
    }
    refreshTooltips(list);
    state.deviceModal.gatewayOptions = gateways;
    updateGatewayLinkSelection();
}

export async function refreshGatewayOptions(selectedKeys = null) {
    if (!els.deviceGatewayLinksRow || !els.deviceGatewayLinksList) return;

    const deviceType = normalizeDeviceType(
        els.deviceForm.dataset.deviceType || "watch",
    );
    const isGatewayLinked = linksToGateway(deviceType);
    els.deviceGatewayLinksRow.classList.toggle("d-none", !isGatewayLinked);
    if (!isGatewayLinked) {
        renderGatewayOptions([], []);
        return;
    }

    const company = els.deviceCompany.value || "";
    const licenseId = els.deviceLicenseId.value || "0";
    const preserved = selectedKeys === null
        ? selectedGatewayKeys()
        : selectedKeys;
    if (!company || licenseId === "0") {
        renderGatewayOptions(
            [],
            [],
            "Selecione primeiro a empresa e a licença do sensor.",
        );
        els.deviceGatewayLinksHelp.textContent =
            "Selecione primeiro a empresa e a licença do sensor.";
        return;
    }

    setGatewayLinksDisabled(true);
    els.deviceGatewayLinksHelp.textContent = "A carregar gateways...";
    const response = await apiGetDevices({
        page: 1,
        limit: 500,
        deviceType: "gateway",
        company,
        licenseId,
    });
    if (response?.error) {
        renderGatewayOptions(
            preserved.map((imei) => ({ imei })),
            preserved,
        );
        setGatewayLinksDisabled(true);
        els.deviceGatewayLinksHelp.textContent =
            "Não foi possível carregar os gateways disponíveis; as ligações atuais foram preservadas.";
        return;
    }

    const gateways = eligibleGateways(response.data || [], company, licenseId);
    renderGatewayOptions(gateways, preserved);
    els.deviceGatewayLinksHelp.textContent = gateways.length
        ? "Selecione um ou mais gateways autorizados a reportar dados deste sensor."
        : "Não existem gateways para esta empresa e licença.";
}

export async function syncGatewayLinks(sensorKey, currentKeys, desiredKeys) {
    const changes = gatewayLinkChanges(currentKeys, desiredKeys);
    for (const gatewayKey of changes.add) {
        const result = await apiCreateDeviceLink(gatewayKey, sensorKey);
        if (result?.error) return result.error.message || result.error.code;
    }
    for (const gatewayKey of changes.remove) {
        const result = await apiDeleteDeviceLink(gatewayKey, sensorKey);
        if (result?.error) return result.error.message || result.error.code;
    }
    return "";
}
