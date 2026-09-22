/**
 * Os ouvintes da coluna dos dispositivos: a lista, os filtros, o modal, o painel de
 * configuração e o detalhe.
 *
 * É raiz de composição e não uma funcionalidade, e por isso pode importar de onde precisar.
 * Vivem aqui porque quase todos atravessam duas ou três funcionalidades, e pô-los dentro de
 * uma obrigava-a a importar as outras.
 */
import { state } from "../state.js";
import { syncPhoneControl } from "../phone.js";
import { normalizeDeviceType } from "../domain.js";
import { confirmDestructive } from "../dialogs.js";
import { emptyPanel } from "../components/empty-panel.js";
import { html } from "../html.js";
import {
    clearDeviceFilters,
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
    clearDetailFilters,
    handleDownlinkPagerClick,
    handleTelemetryPagerClick,
    removeDetailFilter,
    updateDetailFilterDraft,
} from "../devices/detail-filters.js";
import { toggleActivityRow } from "../devices/activity-table.js";
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
    bindDeviceForm();
    bindListAndFilters();
    bindDetail();
    bindConfigPanel();
    bindRadarMap();
}

/**
 * A planta da divisão de um radar.
 *
 * O `shown` é preciso porque o Konva mede o contentor ao montar a tela, e antes de o modal
 * abrir ele tem largura zero -- a planta nascia num canto.
 */
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
        void openConfigPanel();
    });
    bindUnsentConfigGuard();
}

/**
 * A acção do botão que dá a saída depois de a carga do painel falhar.
 *
 * Recarrega a página, e não pede o módulo outra vez: o browser guarda no mapa de módulos a
 * falha por URL, e um segundo `import()` do mesmo especificador resolve para a entrada nula
 * **sem voltar à rede**. Medido contra o hub local -- duas tentativas, um só pedido. Um botão
 * que pedisse outra vez prometia uma recuperação que não acontece.
 */
const CONFIG_RETRY_ACTION = "reloadForConfigPanel";

/**
 * Abrir o separador é o que manda vir o painel de configurações.
 *
 * Entre o clique e o módulo chegar há rede pelo meio, e a raiz não pode ficar vazia: escreve
 * a mesma frase que o painel escreve enquanto vai buscar o catálogo, para as duas esperas se
 * lerem como uma só. Um esqueleto de barras seria afirmar uma forma que ainda não se sabe --
 * o que o painel desenha depende do protocolo e do modelo.
 */
async function openConfigPanel() {
    els.deviceConfigRoot.innerHTML = emptyPanel("A carregar configurações...");

    let panel;
    try {
        ({ panel } = await loadConfigPanel());
    } catch {
        // Sem isto a raiz ficava com a frase da espera para sempre, e sem caminho de volta.
        els.deviceConfigRoot.innerHTML = html`<div class="text-secondary py-3">
            Não foi possível carregar as configurações.
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" data-action="${CONFIG_RETRY_ACTION}">Recarregar a página</button>
        </div>`;
        return;
    }

    await ensureDeviceConfigurationCatalogLoaded();
    panel.renderDeviceConfigurationModal();
}

/** Fechar com configuração escrita e por enviar deitava-a fora em silêncio. */
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
    ]) {
        els.deviceConfigRoot.addEventListener(type, (event) => {
            // Sem o painel carregado a raiz só tem a frase da espera ou a da falha, e não há
            // controlo nenhum para tratar.
            const loaded = configPanelIfLoaded();
            if (!loaded) return;

            pick(loaded.handlers)(event);
            const section = event.target.closest("[data-config-section]");
            if (section) loaded.panel.syncConfigSectionDirty(section);
            // Os interruptores agrupados não vivem numa secção: a conta das alterações é do
            // grupo, e é o rodapé dele que acende.
            const group = event.target.closest("[data-config-group]");
            if (group) loaded.panel.syncConfigGroupDirty(group);
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
