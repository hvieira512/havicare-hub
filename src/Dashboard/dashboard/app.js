/**
 * A raiz de composição da dashboard: cacheia os elementos, cria os modais, entrega o `els` a
 * cada funcionalidade pelo seu `init`, liga os ouvintes e repõe o que ficou da sessão.
 *
 * É o único sítio que conhece toda a gente, e uma funcionalidade nunca importa outra -- é essa
 * regra que mantém o grafo de módulos sem ciclos. Os ouvintes vivem no `wiring/`, que é raiz
 * de composição na mesma, dividida por área.
 */
import { getDevice as apiGetDevice } from "./api/index.js";
import { refreshSelectedDetail, setDeviceFilters, state } from "./state.js";
import { cacheElements } from "./dom.js";
import { bindDeviceEvents } from "./wiring/devices.js";
import { bindSettingsEvents } from "./wiring/settings.js";
import { bindInvalidClearing } from "./validation.js";
import {
    FILTERS_STORAGE_KEY,
    SELECTED_DEVICE_STORAGE_KEY,
    loadJsonStorage,
    loadTextStorage,
} from "./storage.js";
import { storedFilterList } from "./devices/filters.js";
import {
    ensureProtocolsLoaded,
    initDeviceList,
    loadDevice,
} from "./devices/list.js";
import { renderSelection } from "./devices/detail.js";
import { initDeviceStream } from "./devices/stream.js";
import { initEditWizard } from "./devices/edit-wizard.js";
import { initDeviceModal } from "./devices/device-modal.js";
import {
    initDeviceConfigPanel,
    syncDeviceModalCommandStates,
} from "./devices/config/panel.js";
import { initCreateWizard, openWizard } from "./devices/create-wizard.js";
import {
    initGatewayLinksUi,
    refreshGatewayOptions,
} from "./devices/gateway-links-ui.js";
import { initNotifications } from "./notifications.js";
import { initSettings } from "./settings/index.js";
import { initSettingsClickHandlers } from "./settings/clicks.js";

/** De quanto em quanto tempo se relê o dispositivo escolhido. Ver a nota no `startDashboard`. */
const DEVICE_REFRESH_MS = 30000;

let els = {};
let deviceModal = null;
let deviceWizardModal = null;
let deviceSelectorModal = null;
let settingsModal = null;

export async function startDashboard() {
    els = cacheElements();
    initGatewayLinksUi({ els });
    initDeviceConfigPanel({ els });
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
    const ui = { deviceModal, deviceSelectorModal, settingsModal };

    initDeviceModal({ els, deviceModal, deviceSelectorModal, settingsModal });
    initEditWizard({
        els,
        // A autorização de um gateway é por empresa e licença: mudar de licença muda quais
        // são os elegíveis, e os que estavam marcados eram de outro cliente.
        onLicenseChange: () => void refreshGatewayOptions([]),
    });
    initCreateWizard({ els, wizardModal: deviceWizardModal });
    initSettingsClickHandlers({ els });
    initDeviceList({ els, ui });
    initSettings({ els, ui });
    initDeviceStream({
        renderSelection,
        onCommandsUpdated: syncDeviceModalCommandStates,
    });
    initNotifications({
        els,
        openAddDevice: openWizard,
    });

    // Um ouvinte só e não um por formulário: os campos marcados vivem em cinco formulários,
    // uns servidos pelo PHP e outros desenhados em JS.
    bindInvalidClearing(document);
    bindDeviceEvents({ els, ui });
    bindSettingsEvents({ els });

    await ensureProtocolsLoaded();

    // Os filtros guardados podem ser da forma antiga, com um valor por chave e `licenseId`
    // e `company` separados: o `storedFilterList` aceita as duas para não os perder.
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
    const storedSelectedImei = loadTextStorage(SELECTED_DEVICE_STORAGE_KEY);
    if (storedSelectedImei) {
        state.selectedImei = storedSelectedImei;
        void loadDevice(storedSelectedImei);
    } else {
        renderSelection();
    }

    // Isto relê o registo do dispositivo -- estado de ligação, modelo, configuração -- e não
    // o histórico: o `recent` preserva-se de propósito porque só o stream o traz, e é o
    // `stream.js` que garante que ele volta a ligar-se quando cai.
    setInterval(refreshSelectedDevice, DEVICE_REFRESH_MS);
}

function refreshSelectedDevice() {
    if (
        document.body.dataset.dashboardAuthRequired === "true" &&
        !window.hubDashboardApiToken?.access_token
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
