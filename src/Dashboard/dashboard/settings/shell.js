import {
    getApiUsers as apiGetApiUsers,
    getModels as apiGetModels,
} from "../api/index.js";
import { ensureLicensesLoaded } from "../licenses.js";
import { resolvePaginationPage } from "../pagination.js";
import { els } from "../dom.js";

/** A casca do modal de definições; não conhece nenhuma secção, e por isso todas a importam. */
let ui;

export function initSettingsShell(context) {
    ui = context.ui;
}

export function showSettingsModal() {
    ui.settingsModal.show();
}

/** As contagens do menu ao abrir o modal; falha em silêncio, e cada separador enche a sua. */
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
        // «Licenças» conta licenças, e não as empresas que as detêm.
        ensureLicensesLoaded().then((licenses) => {
            if (licenses !== null) setSettingsNavCount("Company", licenses.length);
        }),
    ]);
}

/** A contagem de uma secção no menu, dada por quem foi buscar a lista. */
export function setSettingsNavCount(key, total) {
    const element = els?.[`settings${key}Count`];
    if (!element) return;
    // A zero esconde-se: um «0» ao lado do nome não diz nada que a lista vazia não diga.
    const count = Number(total);
    const shows = Number.isFinite(count) && count > 0;
    element.textContent = shows ? String(count) : "";
    element.classList.toggle("d-none", !shows);
}

/** O clique no paginador de uma secção; o ouvinte está no contentor, e o clique borbulha uma vez. */
export function handleSettingsPaginationClick(event, pagination, actionPrefix, load) {
    const nextPage = resolvePaginationPage(event, pagination, actionPrefix);
    if (nextPage !== null) void load(nextPage);
}
