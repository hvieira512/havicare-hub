/**
 * Uma grelha construída a partir do descritor que a API devolve.
 *
 * A resposta da listagem diz que colunas existem, quais se ordenam, quais se editam e como
 * se filtram -- caixa de texto ou lista de opções, com o parâmetro e os valores já contados.
 * Este módulo traduz isso para o AG Grid, e é o único sítio do projeto que o conhece.
 *
 * Ordenar, filtrar e paginar acontecem no servidor. Fazê-lo no cliente ordenaria só a página
 * visível, o que parece funcionar e mente. A paginação é a do projeto -- o `pagination.js` --
 * e não a que a biblioteca traz: um paginador diferente por listagem é uma dashboard
 * incoerente.
 */

/**
 * As etiquetas são de quem monta a grelha e não da API: o descritor descreve estrutura, e
 * traduzir é trabalho de quem desenha a interface. Viajam por grelha e não num estado do
 * módulo, para duas grelhas vivas ao mesmo tempo não se trocarem as etiquetas.
 */
const labelFor = (labels, value) => labels[value] ?? String(value ?? "");

/**
 * Os módulos do AG Grid, registados uma vez só.
 *
 * Registá-los a cada grelha nova parece inofensivo e não é: as linhas e as colunas aparecem
 * na mesma, mas os `cellRenderer` passam a ser ignorados em silêncio -- sem erro e sem
 * aviso, com a célula simplesmente vazia.
 */
let registered = false;

function registerOnce() {
    if (registered) {
        return;
    }
    agGrid.ModuleRegistry.registerModules([agGrid.AllCommunityModule]);
    registered = true;
}

/**
 * O filtro de uma coluna de opções.
 *
 * Não decide nada: quem filtra é o servidor, e por isso o `doesFilterPass` deixa passar
 * tudo. Existe só para guardar a escolha e a dar a quem monta o pedido.
 */
class ServerSelectFilter {
    init() {
        this.value = "";
        this.gui = document.createElement("div");
    }

    getGui() {
        return this.gui;
    }

    isFilterActive() {
        return this.value !== "";
    }

    doesFilterPass() {
        return true;
    }

    getModel() {
        return this.value === "" ? null : { value: this.value };
    }

    setModel(model) {
        this.value = model?.value ?? "";
    }
}

/** O `<select>` que aparece na linha de filtros, alimentado pelas opções do descritor. */
export class ServerSelectFloatingFilter {
    init(params) {
        this.labels = params.labels ?? {};
        this.select = document.createElement("select");
        // As classes do Bootstrap, e não as do AG Grid: é o mesmo `form-select` do resto da
        // plataforma, e assim o filtro parece-se com os outros campos da dashboard.
        this.select.className = "form-select form-select-sm";
        this.select.setAttribute("aria-label", `Filtrar ${params.colDef?.headerName ?? ""}`);
        this.setOptions(params.options ?? []);

        this.select.addEventListener("change", () => {
            params.parentFilterInstance((instance) => {
                instance.setModel(this.select.value === "" ? null : { value: this.select.value });
                params.api.onFilterChanged();
            });
        });

        params.register?.(this);
    }

    /**
     * As opções da última resposta. O valor escolhido entra a zero quando não vem nelas: a
     * faceta conta-se sem o filtro da própria coluna, e sem isto o `<select>` voltava a
     * "Todos" com o filtro ainda a estreitar a tabela.
     */
    setOptions(options) {
        const chosen = this.select.value;
        const listed = options.map((option) => ({ value: String(option.value ?? ""), count: option.count }));
        if (chosen !== "" && !listed.some((option) => option.value === chosen)) {
            listed.push({ value: chosen, count: 0 });
            listed.sort((left, right) => left.value.localeCompare(right.value));
        }

        this.select.innerHTML = ["<option value=\"\">Todos</option>"]
            .concat(listed.map(({ value, count }) => {
                const tally = count === null || count === undefined ? "" : ` (${count})`;
                return `<option value="${value.replace(/"/g, "&quot;")}">${labelFor(this.labels, value)}${tally}</option>`;
            }))
            .join("");
        this.select.value = chosen;
    }

    getGui() {
        return this.select;
    }

    onParentModelChanged(model) {
        this.select.value = model?.value ?? "";
    }
}

/** Uma coluna do descritor na forma que o AG Grid entende. */
function toColumnDef(column, { titles, labels, renderers, register }) {
    const definition = {
        field: column.field,
        headerName: titles[column.field] ?? column.field,
        sortable: column.sortable === true,
        editable: column.editable === true,
        resizable: true,
        minWidth: 120,
        flex: 1,
        // Quem ordena é o servidor, e sem isto a grelha reordenava por cima a página que
        // recebeu -- escondendo as regras que só o servidor tem, como a falta de valor ir
        // para o fim nos dois sentidos.
        comparator: () => 0,
    };

    // Um desenho próprio para a coluna, quando a listagem o traz -- a pastilha de estado,
    // os botões de acção. `cellDataType: "text"` porque o AG Grid desenha um booleano como
    // caixa de marcar e ignoraria o que se lhe dê.
    const custom = renderers[column.field];
    if (custom) {
        definition.cellDataType = "text";
        Object.assign(definition, typeof custom === "function" ? { cellRenderer: custom } : custom);
    } else if (column.filter?.type === "select") {
        definition.valueFormatter = (params) => labelFor(labels, params.value);
    }

    const filter = column.filter;
    if (filter?.type === "text") {
        definition.filter = "agTextColumnFilter";
        definition.floatingFilter = true;
        definition.suppressHeaderMenuButton = true;
        definition.filterParams = { filterOptions: ["contains"], maxNumConditions: 1, buttons: [] };
    } else if (filter?.type === "select") {
        definition.filter = ServerSelectFilter;
        definition.floatingFilterComponent = ServerSelectFloatingFilter;
        definition.floatingFilter = true;
        definition.suppressHeaderMenuButton = true;
        // Pelo canal do AG Grid e não por uma chave inventada no `colDef`: o que se põe
        // fora deste objecto não chega ao componente.
        definition.floatingFilterComponentParams = { options: filter.options ?? [], labels, register };
    }

    return definition;
}

/**
 * A engrenagem que vive no cabeçalho da tabela.
 *
 * É uma coluna fixa à direita, sem conteúdo nas células, porque o menu que o AG Grid põe em
 * cada cabeçalho é Enterprise. Assim o botão acompanha a tabela quando ela rola de lado, em
 * vez de flutuar por cima dela.
 */
class SettingsHeader {
    init(params) {
        this.button = document.createElement("button");
        this.button.type = "button";
        this.button.className = "btn btn-link p-0 border-0 grid-settings-button";
        this.button.title = "Opções da tabela";
        this.button.setAttribute("aria-label", "Opções da tabela");
        this.button.setAttribute("aria-haspopup", "true");
        this.button.innerHTML = "<i class=\"fa-solid fa-gear\" aria-hidden=\"true\"></i>";
        this.button.addEventListener("click", (event) => {
            event.stopPropagation();
            params.onOpen(this.button);
        });
    }

    getGui() {
        return this.button;
    }

    refresh() {
        return true;
    }
}

/** O painel que a engrenagem abre: as colunas, e o que mais se pode fazer à tabela. */
export function buildSettingsPanel(api, host, defaultState) {
    const panel = document.createElement("div");
    panel.className = "grid-settings dropdown-menu d-none";
    panel.setAttribute("role", "dialog");
    panel.setAttribute("aria-label", "Opções da tabela");

    const heading = (text) => {
        const element = document.createElement("h6");
        element.className = "dropdown-header section-label";
        element.textContent = text;
        return element;
    };

    const action = (icon, text, run) => {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "dropdown-item d-flex align-items-center gap-2";
        button.innerHTML = `<i class="fa-solid ${icon} fa-fw text-secondary"></i><span></span>`;
        button.querySelector("span").textContent = text;
        button.addEventListener("click", run);
        return button;
    };

    function render() {
        panel.innerHTML = "";
        panel.appendChild(heading("Colunas visíveis"));

        for (const item of columnToggleItems(api)) {
            const row = document.createElement("label");
            row.className = "dropdown-item form-check d-flex align-items-center gap-2 mb-0";
            row.innerHTML = "<input type=\"checkbox\" class=\"form-check-input m-0\"><span></span>";
            row.querySelector("input").checked = item.visible;
            row.querySelector("span").textContent = item.title;
            row.querySelector("input").addEventListener("change", item.toggle);
            panel.appendChild(row);
        }

        panel.appendChild(Object.assign(document.createElement("hr"), { className: "dropdown-divider" }));
        panel.appendChild(heading("Tabela"));

        panel.append(
            action("fa-arrows-left-right-to-line", "Ajustar larguras", () => api.autoSizeAllColumns()),
            action("fa-filter-circle-xmark", "Limpar filtros", () => {
                api.setFilterModel(null);
                // O modelo do AG Grid sozinho não repõe os `<select>`, que ficavam a mostrar
                // uma escolha que já não estava aplicada.
                for (const select of host.querySelectorAll(".ag-header-cell select")) {
                    select.value = "";
                }
            }),
            action("fa-arrow-down-a-z", "Limpar ordenação", () => {
                api.applyColumnState({ defaultState: { sort: null } });
            }),
            action("fa-rotate-left", "Repor colunas", () => {
                api.applyColumnState({ state: defaultState, applyOrder: true });
                render();
            }),
        );
    }

    /** Aberto pelo botão, fechado por um clique fora ou pelo Escape. */
    const close = () => panel.classList.remove("show");
    // O clique pára aqui em vez de se perguntar lá em baixo se veio de dentro: "Repor
    // colunas" refaz a lista e tira do documento o próprio botão carregado, e a partir daí
    // o `contains` respondia que não e o painel fechava-se por se lhe mexer.
    panel.addEventListener("click", (event) => event.stopPropagation());
    document.addEventListener("click", (event) => {
        if (!panel.contains(event.target)) {
            close();
        }
    });
    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape") {
            close();
        }
    });

    return {
        element: panel,
        toggle(anchor) {
            if (panel.classList.contains("show")) {
                close();
                return;
            }
            render();
            panel.classList.remove("d-none");
            panel.classList.add("show");
            // Alinhado pela direita do botão, e dentro da grelha para rolar com ela.
            const host_ = anchor.closest(".ag-root-wrapper") || host;
            const box = anchor.getBoundingClientRect();
            const frame = host_.getBoundingClientRect();
            panel.style.top = `${box.bottom - frame.top + 4}px`;
            panel.style.right = `${frame.right - box.right}px`;
        },
    };
}

/** As cores do tema, que o AG Grid não lê dos tokens do Bootstrap por si. */
const THEME_PARAMS = {
    dark: {
        backgroundColor: "#0f1729",
        foregroundColor: "#dfe6f2",
        borderColor: "#26324a",
        headerBackgroundColor: "#16203a",
        oddRowBackgroundColor: "#131c31",
        accentColor: "#5b93f8",
    },
    light: {
        backgroundColor: "#ffffff",
        foregroundColor: "#16202e",
        borderColor: "#d8e0ea",
        headerBackgroundColor: "#eef2f7",
        oddRowBackgroundColor: "#f8fafc",
        accentColor: "#2563eb",
    },
};

const themeFor = (dark) => agGrid.themeQuartz.withParams(dark ? THEME_PARAMS.dark : THEME_PARAMS.light);

/**
 * O estado do cabeçalho traduzido em parâmetros do pedido.
 *
 * O `param` de cada filtro vem do descritor, e não de um mapa escrito aqui: é assim que uma
 * coluna nova passa a filtrar sem se tocar neste ficheiro.
 */
export function requestParams(columns, page, limit, columnState, filterModel) {
    const byField = new Map(columns.map((column) => [column.field, column]));
    const params = { page, limit };

    // O `sortIndex` é a precedência quando se ordena por mais do que uma coluna.
    const sort = columnState
        .filter((state) => state.sort && byField.get(state.colId)?.sortable)
        .sort((left, right) => (left.sortIndex ?? 0) - (right.sortIndex ?? 0))
        .map((state) => `${state.colId}:${state.sort}`);
    if (sort.length) {
        params.sort = sort.join(",");
    }

    for (const [field, model] of Object.entries(filterModel || {})) {
        const descriptor = byField.get(field)?.filter;
        // O filtro de texto do AG Grid guarda o valor em `filter`; o de opções em `value`.
        const value = model?.value ?? model?.filter ?? "";
        if (descriptor && value !== "") {
            params[descriptor.param] = value;
        }
    }

    return params;
}

/**
 * O que grava uma célula editada, e repõe o valor antigo quando o servidor recusa.
 *
 * O recuo mexe nos dados e repinta em vez de passar pelo `setDataValue`: esse conta como
 * outra alteração de célula e volta a disparar este evento, com que uma recusa fica a
 * alternar entre os dois valores e cada volta é mais uma escrita.
 */
export function cellSaver(save, onError) {
    return async (event) => {
        try {
            await save(event.data, event.colDef.field);
        } catch (error) {
            const field = event.colDef.field;
            event.data[field] = event.oldValue;
            event.api.refreshCells({ rowNodes: [event.node], columns: [field], force: true });
            onError?.(error);
        }
    };
}

export function createGrid({
    element,
    columns,
    load,
    save,
    onPage,
    onError,
    pageSize = 20,
    dark = true,
    columnTitles = {},
    valueLabels = {},
    cellRenderers = {},
    // Colunas que não vêm do descritor porque não são campos da linha -- as acções sobre
    // ela, por exemplo. Entram à direita, antes da engrenagem.
    extraColumns = [],
    emptyMessage = "Nada para este filtro.",
}) {
    registerOnce();

    let page = 1;
    let limit = pageSize;
    let ready = false;

    // Os `<select>` vivos do cabeçalho, por campo, para lhes dar as facetas de cada resposta.
    const selectFilters = new Map();

    async function refresh() {
        const state = api.getColumnState();
        const response = await load(requestParams(columns, page, limit, state, api.getFilterModel()));
        page = response.pagination?.page ?? page;
        api.setGridOption("rowData", response.data || []);
        for (const column of response.columns ?? []) {
            if (column.filter?.type === "select") {
                selectFilters.get(column.field)?.setOptions(column.filter.options ?? []);
            }
        }
        onPage?.(response.pagination || {});
    }

    /** Ordenar ou filtrar volta à primeira página: a 3 da lista nova não tem as mesmas linhas. */
    const restart = () => {
        if (!ready) {
            return;
        }
        page = 1;
        void refresh().catch((error) => onError?.(error));
    };

    let settings = null;

    // A coluna da engrenagem: fixa à direita, estreita e sem conteúdo. Não vem do descritor
    // porque não é um campo do dispositivo -- é o menu da própria tabela.
    const settingsColumn = {
        colId: "__settings",
        headerComponent: SettingsHeader,
        headerComponentParams: { onOpen: (anchor) => settings?.toggle(anchor) },
        pinned: "right",
        width: 46,
        minWidth: 46,
        maxWidth: 46,
        resizable: false,
        sortable: false,
        suppressMovable: true,
        lockPosition: "right",
        valueGetter: () => "",
        cellClass: "grid-settings-cell",
    };

    const api = agGrid.createGrid(element, {
        theme: themeFor(dark),
        columnDefs: [
            ...columns.map((column) => toColumnDef(column, {
                titles: columnTitles,
                labels: valueLabels,
                renderers: cellRenderers,
                register: (instance) => selectFilters.set(column.field, instance),
            })),
            ...extraColumns,
            settingsColumn,
        ],
        rowData: [],
        // A grelha pede a altura das linhas que tem, em vez de lhe darmos uma medida fixa
        // que sobra numa página curta e corta numa cheia. Quem limita é a paginação.
        domLayout: "autoHeight",
        // O servidor manda em tudo o que estreita ou reordena a lista.
        pagination: false,
        // Sem `multiSortKey` o AG Grid usa Shift+clique para a segunda coluna. Com `"ctrl"`
        // seria Ctrl ou Cmd, e no macOS o Ctrl+clique é o clique-direito do sistema.
        suppressMovableColumns: false,
        overlayNoRowsTemplate: `<span class="p-3">${emptyMessage}</span>`,
        defaultColDef: { resizable: true },
        onSortChanged: restart,
        onFilterChanged: restart,
        onCellValueChanged: cellSaver(save, onError),
        onGridReady: () => {
            ready = true;
            settings = buildSettingsPanel(api, element, api.getColumnState());
            element.querySelector(".ag-root-wrapper")?.appendChild(settings.element);
        },
    });

    return {
        api,
        start: () => refresh(),
        goToPage(next) {
            page = next;
            return refresh();
        },
        /** Mudar quantas linhas por página volta ao princípio, pela mesma razão. */
        setPageSize(size) {
            limit = size;
            page = 1;
            return refresh();
        },
        setDark(next) {
            api.setGridOption("theme", themeFor(next));
        },
    };
}

/** O que alimenta o menu de escolher colunas, construído do que a grelha tem. */
function columnToggleItems(api) {
    return api.getColumns()
        .filter((column) => column.getColDef().field)
        .map((column) => ({
            field: column.getColId(),
            title: column.getColDef().headerName,
            visible: column.isVisible(),
            toggle: () => api.setColumnsVisible([column.getColId()], !column.isVisible()),
        }));
}
