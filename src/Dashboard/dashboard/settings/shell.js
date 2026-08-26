import {
    getApiUsers as apiGetApiUsers,
    getLicenses as apiGetLicenses,
    getModels as apiGetModels,
} from "../api/index.js";
import {state} from "../state.js";
import {renderPagination, resolvePaginationPage} from "../pagination.js";

/**
 * A casca do modal de definicoes: o menu da esquerda com as suas contagens, a troca de
 * separador, e a paginacao que as listagens partilham.
 *
 * Nao conhece nenhuma seccao. E ao contrario que funciona -- cada seccao importa daqui o
 * que precisa --, e por isso e que este ficheiro pode ser importado por todas sem nenhum
 * ciclo. Quem sabe que seccoes existem e o `index.js`.
 */
let els;
let ui;

export function initSettingsShell(context) {
    els = context.els;
    ui = context.ui;
}

export function showSettingsModal() {
    ui.settingsModal.show();
}

/** Abre o separador de uma seccao. */
export function activateSettingsSection(section) {
    state.settingsModal.section = section;
    const button =
        {
            models: els.settingsModelsTabBtn,
            capabilities: els.settingsCapabilitiesTabBtn,
            company: els.settingsCompanyTabBtn,
            apiUsers: els.settingsApiUsersTabBtn,
        }[section] || els.settingsModelsTabBtn;
    bootstrap.Tab.getOrCreateInstance(button).show();
}

/**
 * As contagens do menu, todas, ao abrir o modal.
 *
 * Cada separador enche a sua quando carrega, mas so carrega quando se abre -- e o menu
 * ficava com um numero no primeiro separador e nada nos outros. Sao tres pedidos de uma
 * linha cada, so para ler o total da paginacao.
 *
 * Falha em silencio: um numero que nao se sabe nao aparece, e a contagem do separador
 * enche-a quando ele abrir.
 */
export async function loadSettingsNavCounts() {
    const asks = [
        ["Models", apiGetModels],
        // O separador chama-se "Licenças" e conta licenças, não as empresas que as detêm.
        ["Company", apiGetLicenses],
        ["ApiUsers", apiGetApiUsers],
    ];
    await Promise.all(asks.map(async ([key, ask]) => {
        const response = await ask({page: 1, limit: 1});
        if (response?.error) return;
        const total = response?.pagination?.total;
        if (Number.isFinite(Number(total))) setSettingsNavCount(key, total);
    }));
}

/**
 * A contagem de uma seccao no menu das definicoes.
 *
 * Cada carregador chama isto com o seu total, em vez de haver um sitio que sabe contar
 * tudo -- so quem foi buscar a lista e que sabe quantos sao.
 */
export function setSettingsNavCount(key, total) {
    // `els?.` e nao `els.`: quem desenha uma seccao nao tem de saber se o modal ja foi
    // inicializado, e sem isto a primeira contagem antes do `initSettings` rebentava.
    const element = els?.[`settings${key}Count`];
    if (!element) return;
    const known = Number.isFinite(Number(total));
    element.textContent = known ? String(total) : "";
    element.classList.toggle("d-none", !known);
}

export function renderSettingsPagination(
    pagination,
    rootEl,
    summaryEl,
    controlsEl,
    action,
) {
    renderPagination({
        pagination,
        rootEl,
        summaryEl,
        controlsEl,
        actionPrefix: action,
    });
}

export function handleSettingsPaginationClick(event, paginationKey, loadFn) {
    const nextPage = resolvePaginationPage(
        event,
        state.settingsModal[paginationKey],
        paginationActionPrefix(paginationKey),
    );
    if (nextPage === null) return;
    void loadFn(nextPage);
}

function paginationActionPrefix(paginationKey) {
    return (
        {
            apiUsersPagination: "settingsApiUsersPage",
            companyPagination: "settingsCompanyPage",
            licensesPagination: "settingsLicensesPage",
        }[paginationKey] || ""
    );
}

/** Abre ou fecha um `collapse` do Bootstrap sem depender do botao que o comanda. */
export function toggleCollapse(element, show) {
    if (!element || typeof bootstrap === "undefined") return;
    const instance = bootstrap.Collapse.getOrCreateInstance(element, {toggle: false});
    if (show) {
        instance.show();
    } else {
        instance.hide();
    }
}
