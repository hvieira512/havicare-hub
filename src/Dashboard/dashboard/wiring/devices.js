/**
 * A ligação dos ouvintes da coluna dos dispositivos: a lista, os filtros, o modal, o painel
 * de configuração e a coluna de detalhe.
 *
 * Isto continua a ser raiz de composição, não uma funcionalidade: pode conhecer toda a gente
 * e importar de onde precisar. O que não muda é a regra da casa -- uma funcionalidade nunca
 * importa outra --, e é justamente por isso que estes ouvintes vivem aqui: quase todos
 * atravessam duas ou três funcionalidades, e pô-los dentro de uma delas obrigava-a a importar
 * as outras. O `app.js` tinha 567 linhas por causa disto; o que se ganhou foi dividi-lo por
 * área, não mudar quem conhece quem.
 */
import { state } from "../state.js";
import { syncPhoneControl } from "../phone.js";
import { normalizeDeviceType } from "../domain.js";
import {
    handleConfigFeedbackClosed,
    handleDeviceConfigChange,
    handleDeviceConfigClick,
    handleDeviceConfigInput,
} from "../devices/config/handlers.js";
import {
    renderDeviceConfigurationModal,
    syncConfigSectionDirty,
} from "../devices/config/panel.js";
import {
    clearDeviceFilters,
    handleDeviceFilterClick,
    handleDeviceOnlineFilterChange,
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
} from "../devices/filters.js";
import {
    handleDeviceListLimitChange,
    handleDeviceListSearchInput,
    handleDevicePaginationClick,
    openDeviceSelector,
    selectDevice,
} from "../devices/list.js";
import {
    applyDetailFilters,
    applyDetailSearch,
    clearDetailFilters,
    removeDetailFilter,
    requestTelemetryFeature,
    toggleActivityRow,
    updateDetailFilterDraft,
} from "../devices/detail.js";
import { DEVICE_CARD_ACTION } from "../devices/device-card.js";
import { editWizardAnswered } from "../devices/edit-wizard.js";
import {
    editDevice,
    ensureDeviceConfigurationCatalogLoaded,
    handleDeleteDeviceBtnClick,
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
    bindDeviceForm();
    bindListAndFilters();
    bindDetail();
    bindConfigPanel();
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
    els.openDeviceSelectorBtn.addEventListener("click", () => {
        void openDeviceSelector();
    });
    els.emptyStateSelectDeviceBtn.addEventListener("click", () => {
        void openDeviceSelector();
    });
    els.selectedDeviceEditBtn.addEventListener("click", () => {
        if (!state.selectedDetail?.device) return;
        const m = state.selectedDetail.model;
        void editDevice(
            state.selectedDetail.device.imei,
            m?.supplier || "",
            m?.internalModel || "",
        );
    });
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
    els.deviceSupplierButtons.addEventListener(
        "click",
        handleDeviceSupplierClick,
    );
    els.deviceTypeButtons.addEventListener("click", handleDeviceTypeClick);
    els.deviceModelButtons.addEventListener("click", handleDeviceModelClick);
    els.deviceGeneralTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "general";
    });
    els.deviceConfigTabBtn.addEventListener("shown.bs.tab", () => {
        state.deviceModal.activeTab = "config";
        void (async () => {
            await ensureDeviceConfigurationCatalogLoaded();
            renderDeviceConfigurationModal();
        })();
    });
}

function bindListAndFilters() {
    els.deviceListLimit.addEventListener("change", handleDeviceListLimitChange);
    els.deviceListSearch.addEventListener("input", handleDeviceListSearchInput);
    // Um ouvinte por coluna e não um por controlo: as opções são redesenhadas a cada
    // resposta, e ligar o ouvinte a cada botão obrigava a religá-los todos de cada vez.
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
    els.deviceList.addEventListener("click", handleDeviceListClick);
    els.deviceListPagination.addEventListener(
        "click",
        handleDevicePaginationClick,
    );
}

function bindDetail() {
    // As duas listas abrem a linha carregada, ao rato e ao teclado. O ouvinte fica na lista
    // e não em cada linha: elas voltam a desenhar-se a cada mensagem do stream, e prender
    // ouvintes a linhas que se deitam fora a cada segundo era prendê-los ao lixo.
    for (const list of [els.telemetryList, els.downlinkRequests]) {
        list?.addEventListener("click", toggleActivityRow);
        list?.addEventListener("keydown", toggleActivityRow);
    }
    els.telemetryPager.addEventListener("click", handleTelemetryPagerClick);
    els.downlinkPager?.addEventListener("click", handleDownlinkPagerClick);
    els.applyDetailFiltersBtn.addEventListener("click", applyDetailFilters);
    els.clearDetailFiltersBtn.addEventListener("click", clearDetailFilters);
    els.detailFilterFrom.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterTo.addEventListener("change", updateDetailFilterDraft);
    els.detailFilterType.addEventListener("change", updateDetailFilterDraft);
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
    // Um ouvinte por evento, e não dois: o "Enviar" de cada bloco acende por diferença,
    // depois de quem trata o campo ter feito o seu trabalho.
    for (const [type, handle] of [
        ["click", handleDeviceConfigClick],
        ["input", handleDeviceConfigInput],
        ["change", handleDeviceConfigChange],
    ]) {
        els.deviceConfigRoot.addEventListener(type, (event) => {
            handle(event);
            const section = event.target.closest("[data-config-section]");
            if (section) syncConfigSectionDirty(section);
        });
    }
    els.deviceConfigRoot.addEventListener(
        "closed.bs.alert",
        handleConfigFeedbackClosed,
    );
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
    renderDeviceConfigurationModal();
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
}

function handleRequestGridClick(event) {
    const button = event.target.closest("[data-action=\"requestFeature\"]");
    if (button) requestTelemetryFeature(String(button.dataset.feature || ""));
}
