/** A raiz de composição da dashboard: uma funcionalidade nunca importa outra. */
import { getDevice as apiGetDevice } from "./api/index.js";
import { getDashboardApiToken } from "./api/http.js";
import { refreshSelectedDetail, setDeviceFilters, state } from "./state.js";
import { cacheElements } from "./dom.js";
import { trackStickyTop } from "./sticky-top.js";
import { bindDeviceEvents } from "./wiring/devices.js";
import { bindSettingsEvents } from "./wiring/settings.js";
import { bindInvalidClearing } from "./validation.js";
import {
    FILTERS_STORAGE_KEY,
    SELECTED_DEVICE_STORAGE_KEY,
    loadJsonStorage,
    loadTextStorage,
} from "./storage.js";
import { storedFilterList } from "./devices/list-filters.js";
import {
    ensureProtocolsLoaded,
    initDeviceList,
    restoreSelectedDevice,
} from "./devices/list.js";
import { renderSelection } from "./devices/detail.js";
import { initDeviceStream } from "./devices/stream.js";
import { initEditWizard } from "./devices/edit-wizard.js";
import { configPanelIfLoaded, initDeviceModal, loadConfigPanel } from "./devices/device-modal.js";
import { initCreateWizard, openWizard } from "./devices/create-wizard.js";
import {
    initGatewayLinksUi,
    refreshGatewayOptions,
} from "./devices/gateway-links-ui.js";
import { initNotifications } from "./notifications.js";
import { initRadarMapModal } from "./devices/radar-map-modal.js";
import { initSettings } from "./settings/index.js";

const DEVICE_REFRESH_MS = 30000;

let els = {};
let deviceModal = null;
let deviceWizardModal = null;
let deviceSelectorModal = null;
let settingsModal = null;
let radarMapModal = null;

/** A fiação não se repete num segundo arranque: modais, observadores e temporizadores não se desligam. */
let wired = false;

export async function startDashboard() {
    if (!wired) {
        wired = true;
        wireDashboard();
        startSelectedDevicePolling();
    }

    await ensureProtocolsLoaded();

    const stored = loadJsonStorage(FILTERS_STORAGE_KEY);
    if (stored && typeof stored === "object") {
        setDeviceFilters({
            deviceType: storedFilterList(stored.deviceType),
            supplier: storedFilterList(stored.supplier),
            model: storedFilterList(stored.model),
            license: storedFilterList(stored.license),
            online: typeof stored.online === "boolean" ? stored.online : null,
        });
    }
    restoreSelectedDevice(loadTextStorage(SELECTED_DEVICE_STORAGE_KEY));
}

function wireDashboard() {
    els = cacheElements();
    trackStickyTop({
        navbar: document.querySelector(".dashboard-navbar"),
        tabs: [els.activityTabs, els.deviceTabs],
    });
    initGatewayLinksUi({ els });
    deviceModal = new bootstrap.Modal(document.getElementById("deviceModal"));
    deviceWizardModal = new bootstrap.Modal(
        document.getElementById("deviceWizardModal"),
    );
    deviceSelectorModal = new bootstrap.Modal(
        document.getElementById("deviceSelectorModal"),
    );
    settingsModal = new bootstrap.Modal(
        document.getElementById("settingsModal"),
    );
    radarMapModal = new bootstrap.Modal(
        document.getElementById("radarMapModal"),
    );
    const ui = { deviceModal, deviceSelectorModal, settingsModal, radarMapModal };

    initDeviceModal({ els, deviceModal, deviceSelectorModal, settingsModal });
    initRadarMapModal({ els, modals: { radarMap: radarMapModal } });
    initEditWizard({
        els,
        // Os gateways elegíveis são da mesma empresa e licença do sensor.
        onLicenseChange: () => void refreshGatewayOptions([]),
    });
    initCreateWizard({ els, wizardModal: deviceWizardModal });
    initDeviceList({ els, ui });
    initSettings({ els, ui });
    initDeviceStream({
        renderSelection,
        // Sem modal aberto não há onde aplicar o estado de entrega; o `editDevice` relê tudo.
        onCommandsUpdated: (imei, commands) => {
            const loaded = configPanelIfLoaded();
            if (loaded) {
                loaded.panel.syncDeviceModalCommandStates(imei, commands);
                return;
            }
            if (String(state.deviceModal.imei || "") !== String(imei || "")) return;
            void loadConfigPanel()
                .then(({ panel }) => panel.syncDeviceModalCommandStates(imei, commands))
                .catch(() => {});
        },
    });
    initNotifications({
        els,
        openAddDevice: openWizard,
    });

    // Um ouvinte só para os campos marcados de todos os formulários.
    bindInvalidClearing(document);
    bindDeviceEvents({ els, ui });
    bindSettingsEvents({ els });
}

/** Relê o registo do dispositivo, e não o histórico, que só o stream traz; escondido, não sonda. */
export function startSelectedDevicePolling() {
    const refreshWhenVisible = () => {
        if (!document.hidden) {
            refreshSelectedDevice();
        }
    };

    setInterval(refreshWhenVisible, DEVICE_REFRESH_MS);
    document.addEventListener("visibilitychange", refreshWhenVisible);
}

function refreshSelectedDevice() {
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !getDashboardApiToken()?.access_token
    ) {
        return;
    }
    if (!state.selectedImei) {
        return;
    }

    apiGetDevice(state.selectedImei).then((detail) => {
        if (detail?.error) return;
        if (state.selectedImei !== detail.device?.imei) return;
        refreshSelectedDetail(detail);
        renderSelection();
    });
}
