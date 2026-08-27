import {
    getApiUsers as apiGetApiUsers,
    getModels as apiGetModels,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../licenses.js";
import { state } from "../state.js";
import { resolvePaginationPage } from "../pagination.js";

/**
 * A casca do modal de definições: o menu da esquerda com as suas contagens, a troca de
 * separador, e a paginação que as listagens partilham.
 *
 * Não conhece nenhuma secção -- cada secção importa daqui o que precisa --, e é por isso que
 * pode ser importado por todas sem ciclo nenhum. Quem sabe que secções existem é o `index.js`.
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

/** Abre o separador de uma secção. */
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
 * As contagens do menu, todas, ao abrir o modal: cada separador enche a sua quando carrega,
 * mas só carrega quando se abre, e o menu ficaria com um número e nada nos outros.
 *
 * Falha em silêncio: um número que não se sabe não aparece, e a contagem do separador
 * enche-a quando ele abrir.
 */
export async function loadSettingsNavCounts() {
    const asks = [
        ["Models", apiGetModels],
        ["ApiUsers", apiGetApiUsers],
    ];
    await Promise.all([
        ...asks.map(async ([key, ask]) => {
            const response = await ask({ page: 1, limit: 1 });
            if (response?.error) return;
            const total = response?.pagination?.total;
            if (Number.isFinite(Number(total))) setSettingsNavCount(key, total);
        }),
        // O separador chama-se "Licenças" e conta licenças, não as empresas que as detêm.
        ensureLicensesLoaded().then((licenses) => {
            if (licenses !== null) setSettingsNavCount("Company", licenses.length);
        }),
    ]);
}

/**
 * A contagem de uma secção no menu. Cada carregador chama isto com o seu total, porque só
 * quem foi buscar a lista é que sabe quantos são.
 */
export function setSettingsNavCount(key, total) {
    // `els?.` e não `els.`: quem desenha uma secção não tem de saber se o modal já foi
    // inicializado.
    const element = els?.[`settings${key}Count`];
    if (!element) return;
    const known = Number.isFinite(Number(total));
    element.textContent = known ? String(total) : "";
    element.classList.toggle("d-none", !known);
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
        }[paginationKey] || ""
    );
}

/** Abre ou fecha um `collapse` do Bootstrap sem depender do botão que o comanda. */
export function toggleCollapse(element, show) {
    if (!element || typeof bootstrap === "undefined") return;
    const instance = bootstrap.Collapse.getOrCreateInstance(element, { toggle: false });
    if (show) {
        instance.show();
    } else {
        instance.hide();
    }
}
