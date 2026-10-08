/**
 * Os ouvintes da coluna dos dispositivos. É raiz de composição, e por isso pode importar de
 * onde precisar: quase todos atravessam duas ou três funcionalidades.
 */
import { state } from "../state.js";
import { syncPhoneControl } from "../phone.js";
import { normalizeDeviceType } from "../domain.js";
import { confirmDestructive } from "../dialogs.js";
import { emptyPanel } from "../components/empty-panel.js";
import { html } from "../html.js";
import {
    clearDeviceFilters,
    handleDeviceFilterChipRemove,
    handleDeviceFilterClick,
    handleDeviceOnlineFilterChange,
} from "../devices/list-filters.js";
import {
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    loadSummary,
    openDeviceSelector,
    selectDevice,
} from "../devices/list.js";
import {
    requestTelemetryFeature,
} from "../devices/detail.js";
import {
    applyDetailFilters,
    applyDetailSearch,
    applyDetailType,
    clearDetailFilters,
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
    removeDetailFilter,
    syncDetailSearchPlaceholder,
    updateDetailFilterDraft,
} from "../devices/detail-filters.js";
import { toggleActivityRow } from "../devices/activity-table.js";
import { handleConnectionHistoryClick } from "../devices/connection-history.js";
import { DEVICE_CARD_ACTION } from "../devices/device-card.js";
import {
    closeRadarMap,
    openRadarMap,
    resizeRadarMap,
    syncRadarMap,
} from "../devices/radar-map-modal.js";
import { editWizardAnswered } from "../devices/edit-wizard.js";
import {
    configPanelIfLoaded,
    editDevice,
    ensureDeviceConfigurationCatalogLoaded,
    handleDeleteDeviceBtnClick,
    syncDeleteFooterButton,
    loadConfigPanel,
    renderDeviceSelectors,
    renderDeviceTypeSelector,
    saveDevice,
    setDeviceFormError,
    syncDeviceModalContext,
} from "../devices/device-modal.js";
import { openWizard } from "../devices/create-wizard.js";
import {
    refreshGatewayOptions,
    updateGatewayLinkSelection,
} from "../devices/gateway-links-ui.js";

let els;
let ui;

export function bindDeviceEvents(context) {
    els = context.els;
    ui = context.ui;

    bindEntryPoints();
    bindDeviceTabs();
    bindDeviceForm();
    bindListAndFilters();
    bindDetail();
    bindConfigPanel();
    bindRadarMap();
}

/** A planta de um radar, montada no `shown` porque o Konva mede o contentor ao montar a tela. */
function bindRadarMap() {
    els.radarMapSyncBtn?.addEventListener("click", () => void syncRadarMap());
    const root = document.getElementById("radarMapModal");
    root?.addEventListener("shown.bs.modal", () => resizeRadarMap());
    root?.addEventListener("hidden.bs.modal", () => closeRadarMap());
    globalThis.addEventListener("resize", () => resizeRadarMap());
}

/** Os botões que abrem o assistente e o selector, e o atalho de editar o escolhido. */
function bindEntryPoints() {
    els.addDeviceBtn.addEventListener("click", () => {
        void openWizard();
    });
    els.openAddDeviceFromSelectorBtn.addEventListener("click", () => {
        ui.deviceSelectorModal?.hide();
        void openWizard();
    });
    for (const button of [
        els.openDeviceSelectorBtn,
        els.emptyStateSelectDeviceBtn,
        els.deviceBandSelectBtn,
    ]) {
        button.addEventListener("click", () => {
            void openDeviceSelector();
        });
    }
    for (const button of [els.selectedDeviceEditBtn, els.deviceBandEditBtn]) {
        button.addEventListener("click", () => {
            if (!state.selectedDetail?.device) return;
            const m = state.selectedDetail.model;
            void editDevice(
                state.selectedDetail.device.imei,
                m?.supplier || "",
                m?.internalModel || "",
            );
        });
    }
}

/**
 * A régua do telemóvel manda na régua do cartão da atividade, para as duas não divergirem; a
 * telemetria são os cartões da coluna do aparelho, mostrados pelo atributo na raiz.
 */
function bindDeviceTabs() {
    for (const button of els.deviceTabs.querySelectorAll(".nav-link")) {
        button.addEventListener("click", () => showDeviceTab(button));
    }
    showDeviceTab(els.deviceTabTelemetry);
}

function showDeviceTab(button) {
    for (const link of els.deviceTabs.querySelectorAll(".nav-link")) {
        link.classList.toggle("active", link === button);
        link.setAttribute("aria-selected", String(link === button));
    }

    const pane = button.dataset.bsTarget;
    els.dashboardApp.dataset.deviceTab = pane ? "activity" : "telemetry";
    const tab = pane && els.activityTabs.querySelector(`[data-bs-target="${pane}"]`);
    if (tab) globalThis.bootstrap?.Tab.getOrCreateInstance(tab).show();
}

function bindDeviceForm() {
    els.saveDeviceBtn.addEventListener("click", saveDevice);
    els.deviceForm.addEventListener("submit", (event) => {
        event.preventDefault();
        saveDevice();
    });
    els.deviceGatewayLinksSelectAllBtn.addEventListener("click", () => {
        setAllGatewayLinks(true);
    });
    els.deviceGatewayLinksClearBtn.addEventListener("click", () => {
        setAllGatewayLinks(false);
    });
    els.deviceImei.addEventListener("input", handleDeviceImeiInput);
    els.deviceLicenseId.addEventListener("input", handleDeviceImeiInput);
    els.deviceDeviceId.addEventListener("input", handleDeviceImeiInput);
    els.deviceForm.addEventListener("input", handleDeviceFormInput);
    els.deviceForm.addEventListener("change", handleDeviceFormChange);
    els.deleteDeviceBtn.addEventListener("click", handleDeleteDeviceBtnClick);
    els.deleteDeviceFooterBtn?.addEventListener("click", handleDeleteDeviceBtnClick);
    els.deviceSupplierButtons.addEventListener(
        "click",
        handleDeviceSupplierClick,
    );
    els.deviceTypeButtons.addEventListener("click", handleDeviceTypeClick);
    els.deviceModelButtons.addEventListener("click", handleDeviceModelClick);
    els.deviceGeneralTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "general";
        syncDeleteFooterButton();
    });
    els.deviceConfigTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "config";
        syncDeleteFooterButton();
        void openConfigPanel();
    });
    bindUnsentConfigGuard();
}

/**
 * A saída depois de a carga do painel falhar: recarrega a página, porque um segundo `import()`
 * resolve para a falha guardada sem voltar à rede.
 */
const CONFIG_RETRY_ACTION = "reloadForConfigPanel";

/**
 * Abrir o separador manda vir o painel de configurações; enquanto não chega, a raiz mostra a
 * mesma frase de espera que o painel, para as duas esperas se lerem como uma.
 */
async function openConfigPanel() {
    els.deviceConfigRoot.innerHTML = emptyPanel("A carregar configurações...");

    let panel;
    try {
        ({ panel } = await loadConfigPanel());
    } catch {
        els.deviceConfigRoot.innerHTML = html`<div class="text-secondary py-3">
            Não foi possível carregar as configurações.
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-action="${CONFIG_RETRY_ACTION}">Recarregar a página</button>
        </div>`;
        return;
    }

    await ensureDeviceConfigurationCatalogLoaded();
    panel.renderDeviceConfigurationModal();
}

/** Fechar com configuração escrita e por enviar pede confirmação antes de a deitar fora. */
function bindUnsentConfigGuard() {
    let confirmedClose = false;

    els.deviceModal.addEventListener("hide.bs.modal", (event) => {
        if (confirmedClose) {
            confirmedClose = false;
            return;
        }

        // Painel por carregar é painel sem campos escritos: não há nada por enviar.
        const pending = configPanelIfLoaded()
            ?.panel.unsentConfigChanges(els.deviceConfigRoot) ?? 0;
        if (pending === 0) return;

        event.preventDefault();
        void confirmDestructive(
            "Fechar sem enviar?",
            `${pending} ${pending === 1 ? "alteração fica" : "alterações ficam"} por enviar ao dispositivo.`,
            "Fechar sem enviar",
        ).then(({ isConfirmed }) => {
            if (!isConfirmed) return;
            confirmedClose = true;
            ui.deviceModal.hide();
        });
    });
}

function bindListAndFilters() {
    els.deviceListLimit.addEventListener("change", handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener("input", handleDeviceListSearchInput);
    // Um ouvinte por coluna e não por controlo: as opções são redesenhadas a cada resposta.
    for (const root of [
        els.deviceTypeFilter,
        els.deviceSupplierFilter,
        els.deviceLicenseFilter,
    ]) {
        root?.addEventListener("click", handleDeviceFilterClick);
    }
    for (const input of document.querySelectorAll("input[name=\"deviceOnlineFilter\"]")) {
        input.addEventListener("change", handleDeviceOnlineFilterChange);
    }
    els.clearDeviceFiltersBtn.addEventListener("click", clearDeviceFilters);
    els.deviceActiveFilters.addEventListener("click", handleDeviceFilterChipRemove);
    els.deviceList.addEventListener("click", handleDeviceListClick);
    els.deviceListPagination.addEventListener(
        "click",
        handleDevicePaginationClick,
    );
}

function bindDetail() {
    // As duas listas abrem a linha carregada, ao rato e ao teclado, com o ouvinte na lista porque
    // as linhas se redesenham a cada mensagem do stream.
    for (const list of [els.telemetryList, els.downlinkRequests]) {
        list?.addEventListener("click", toggleActivityRow);
        list?.addEventListener("keydown", toggleActivityRow);
    }
    els.telemetryPager.addEventListener("click", handleTelemetryPagerClick);
    els.downlinkPager?.addEventListener("click", handleDownlinkPagerClick);
    els.telemetryLoadMore?.addEventListener("click", handleTelemetryPagerClick);
    els.downlinkLoadMore?.addEventListener("click", handleDownlinkPagerClick);
    els.connectionHistory.addEventListener("click", handleConnectionHistoryClick);
    els.activityTabs?.addEventListener("shown.bs.tab", syncDetailSearchPlaceholder);
    globalThis
        .matchMedia?.("(min-width: 1200px)")
        ?.addEventListener("change", syncDetailSearchPlaceholder);
    els.applyDetailFiltersBtn.addEventListener("click", applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener("click", clearDetailFilters);
    els.detailFilterFrom.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterTo.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterType.addEventListener("change", applyDetailType);
    els.detailFilterSeverity.addEventListener("change", applyDetailType);
    els.detailSearch.addEventListener("input", applyDetailSearch);
    els.detailActiveFilters.addEventListener("click", (event) => {
        const button = event.target.closest("[data-action=\"removeDetailFilter\"]");
        if (!button) return;
        const key = button.dataset.filterKey;
        // A pastilha do intervalo cobre as duas datas, por isso limpa as duas.
        if (key === "range") {
            removeDetailFilter("from");
            removeDetailFilter("to");
            return;
        }
        removeDetailFilter(key);
    });
    els.requestGrid.addEventListener("click", handleRequestGridClick);
}

function bindConfigPanel() {
    els.deviceConfigRoot.addEventListener("click", (event) => {
        if (event.target.closest(`[data-action="${CONFIG_RETRY_ACTION}"]`)) {
            window.location.reload();
        }
    });

    // Um ouvinte por evento, e não dois: o "Enviar" de cada bloco acende por diferença,
    // depois de quem trata o campo ter feito o seu trabalho.
    for (const [type, pick] of [
        ["click", (handlers) => handlers.handleDeviceConfigClick],
        ["input", (handlers) => handlers.handleDeviceConfigInput],
        ["change", (handlers) => handlers.handleDeviceConfigChange],
        ["reset", (handlers) => handlers.handleDeviceConfigReset],
    ]) {
        els.deviceConfigRoot.addEventListener(type, (event) => {
            // Sem o painel carregado a raiz só tem a frase da espera ou a da falha, e não há
            // controlo nenhum para tratar.
            const loaded = configPanelIfLoaded();
            if (!loaded) return;

            pick(loaded.handlers)(event);
            const section = event.target.closest("[data-config-section]");
            if (section) loaded.panel.syncConfigSectionDirty(section);
        });
    }
    els.deviceConfigRoot.addEventListener("closed.bs.alert", (event) => {
        configPanelIfLoaded()?.handlers.handleConfigFeedbackClosed(event);
    });
}

function setAllGatewayLinks(checked) {
    els.deviceGatewayLinksList
        ?.querySelectorAll("input[data-gateway-key]")
        .forEach((input) => {
            input.checked = checked;
        });
    updateGatewayLinkSelection();
}

async function handleDeviceImeiInput() {
    await syncDeviceModalContext();
    configPanelIfLoaded()?.panel.renderDeviceConfigurationModal();
}

function handleDeviceFormInput(event) {
    setDeviceFormError("");
    if (event.target.matches("[data-phone-local]")) {
        syncPhoneControl(event.target);
        void syncDeviceModalContext();
    }
}

function handleDeviceFormChange(event) {
    setDeviceFormError("");
    if (event.target.matches("#deviceGatewayLinksList input[data-gateway-key]")) {
        updateGatewayLinkSelection();
    }
    if (event.target.matches("[data-phone-country]")) {
        syncPhoneControl(event.target);
        void syncDeviceModalContext();
    }
}

function handleDeviceSupplierClick(event) {
    const button = event.target.closest("[data-action=\"selectDeviceSupplier\"]");
    // Escolher o fornecedor não responde à pergunta do modelo: é o par que identifica, e a
    // pergunta só fecha quando houver modelo.
    if (button) renderDeviceSelectors(button.dataset.value, "");
}

async function handleDeviceTypeClick(event) {
    const button = event.target.closest("[data-action=\"selectDeviceType\"]");
    if (!button) return;

    const deviceType = normalizeDeviceType(button.dataset.value);
    renderDeviceTypeSelector(deviceType);
    await renderDeviceSelectors("", "", deviceType);
    await refreshGatewayOptions([]);
    editWizardAnswered("type");
}

function handleDeviceModelClick(event) {
    const button = event.target.closest("[data-action=\"selectDeviceModel\"]");
    if (!button) return;
    els.deviceForm.dataset.model = button.dataset.value;
    renderDeviceSelectors(
        els.deviceForm.dataset.supplier,
        button.dataset.value,
    );
    editWizardAnswered("model");
}

function handleDeviceListClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;
    const { action, imei } = button.dataset;
    // A acção vem do módulo do cartão em vez de ser a string repetida aqui: quem muda a
    // marcação muda o ouvinte no mesmo sítio.
    if (action === DEVICE_CARD_ACTION) selectDevice(imei);
    else if (action === "retryDeviceList") void loadSummary();
    else if (action === "clearDeviceFilters") void clearDeviceFilters();
}

function handleRequestGridClick(event) {
    const button = event.target.closest("[data-action]");
    if (!button) return;

    if (button.dataset.action === "requestFeature") {
        requestTelemetryFeature(String(button.dataset.feature || ""));
        return;
    }
    // O cartão da presença abre a planta da divisão do radar escolhido.
    if (button.dataset.action === "openRadarMap") {
        void openRadarMap(String(state.selectedDetail?.device?.imei || ""));
    }
}
