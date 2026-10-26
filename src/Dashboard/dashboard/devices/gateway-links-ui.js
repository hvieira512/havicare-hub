import {
    createDeviceLink as apiCreateDeviceLink,
    deleteDeviceLink as apiDeleteDeviceLink,
    getDevices as apiGetDevices,
} from "../api/index.js";
import { allPages } from "../api/http.js";
import { apiError } from "../dialogs.js";
import { esc } from "../format.js";
import { state } from "../state.js";
import { linksToGateway, normalizeDeviceType } from "../domain.js";
import { eligibleGateways, gatewayLinkChanges, normalizeKey } from "./gateway-links.js";
import { disposeTooltips, refreshTooltips } from "../tooltips.js";
import { linkSignal } from "./gateway-signal.js";
import { deviceCheckCard } from "./device-check-card.js";
import { els } from "../dom.js";

/** O escolhedor de gateways do modal do dispositivo: com que gateways um sensor pode falar. */

export function selectedGatewayKeys() {
    return [
        ...(els.deviceGatewayLinksList?.querySelectorAll(
            "input[type=checkbox][data-gateway-key]",
        ) || []),
    ]
        .filter((input) => input.checked)
        .map((input) => normalizeKey(input.dataset.gatewayKey))
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

/** Os sinais vêm das ligações do dispositivo escolhido: a editar outro, não há sinal. */
function signalsForEditedDevice() {
    const editing = normalizeKey(els.deviceImei?.value);
    const selected = normalizeKey(state.selectedDetail?.device?.imei);
    if (!editing || editing !== selected) return new Map();

    const signals = new Map();
    for (const linked of state.selectedDetail?.linkedDevices || []) {
        const signal = linkSignal(linked);
        const key = normalizeKey(linked.deviceKey);
        if (key && signal) signals.set(key, signal);
    }

    return signals;
}

function renderGatewayOptions(gateways = [], selectedKeys = [], emptyText = "") {
    const list = els.deviceGatewayLinksList;
    if (!list) return;

    const selected = new Set(
        selectedKeys.map(normalizeKey),
    );
    disposeTooltips(list);
    if (gateways.length === 0) {
        list.innerHTML = emptyText
            ? `<p class="gateway-picker-empty mb-0 small text-secondary">${esc(emptyText)}</p>`
            : "";
    } else {
        const signals = signalsForEditedDevice();
        list.innerHTML = gateways
            .map((gateway) => {
                const key = normalizeKey(gateway.imei);
                return deviceCheckCard(
                    gateway,
                    selected.has(key),
                    signals.get(key) || null,
                );
            })
            .join("");
    }
    refreshTooltips(list);
    updateGatewayLinkSelection();
}

/** Só a última escolha desenha: senão ligava-se o sensor a um gateway de outro cliente. */
let gatewayOptionsGeneration = 0;

export async function refreshGatewayOptions(selectedKeys = null) {
    gatewayOptionsGeneration += 1;
    const generation = gatewayOptionsGeneration;
    if (!els.deviceGatewayLinksRow || !els.deviceGatewayLinksList) return;

    const deviceType = normalizeDeviceType(
        els.deviceForm.dataset.deviceType || "watch",
    );
    const isGatewayLinked = linksToGateway(deviceType, state.deviceModal.protocol);
    els.deviceGatewayLinksRow.classList.toggle("d-none", !isGatewayLinked);
    if (!isGatewayLinked) {
        renderGatewayOptions([], []);
        return;
    }

    const company = els.deviceCompany.value || "";
    const licenseId = els.deviceLicenseId.value;
    const preserved = selectedKeys === null
        ? selectedGatewayKeys()
        : selectedKeys;
    if (!company || !licenseId) {
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
    const response = await allPages(apiGetDevices, { deviceType: "gateway", company, licenseId });
    if (generation !== gatewayOptionsGeneration) {
        return;
    }
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

/** Tenta todas as ligações e devolve as que falharam, `{ gatewayKey, error }`. */
export async function syncGatewayLinks(sensorKey, currentKeys, desiredKeys) {
    const changes = gatewayLinkChanges(currentKeys, desiredKeys);
    const failed = [];
    for (const [keys, request] of [[changes.add, apiCreateDeviceLink], [changes.remove, apiDeleteDeviceLink]]) {
        for (const gatewayKey of keys) {
            const result = await request(gatewayKey, sensorKey);
            if (result?.error) failed.push({ gatewayKey, error: apiError(result) });
        }
    }
    return failed;
}
