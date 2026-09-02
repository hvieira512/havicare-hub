/**
 * Uma grelha construída a partir do descritor que a API devolve.
 *
 * O `GET /api/devices` diz que colunas existem, quais se ordenam, quais se editam e como se
 * filtram -- caixa de texto ou lista de opções, com o parâmetro e os valores já contados.
 * Este módulo traduz isso para o AG Grid, e é o único sítio do projeto que o conhece.
 *
 * Ordenar, filtrar e paginar acontecem no servidor. Fazê-lo no cliente ordenaria só a página
 * visível, o que parece funcionar e mente. A paginação é a do projeto -- o `pagination.js` --
 * e não a que a biblioteca traz: um paginador diferente por listagem é uma dashboard
 * incoerente.
 */

/** As etiquetas são daqui e não da API: o descritor descreve estrutura, não apresentação. */
const COLUMN_TITLES = {
    imei: "IMEI",
    supplier: "Fornecedor",
    model: "Modelo",
    deviceType: "Tipo",
    licenseId: "Licença",
    licenseName: "Nome da licença",
    company: "Empresa",
    simNumber: "SIM",
    deviceId: "Id do fabricante",
    online: "Estado",
};

const VALUE_LABELS = {
    watch: "Relógio",
    radar: "Radar",
    gateway: "Gateway",
    bracelet: "Pulseira",
    ncs: "NCS",
    diaper_sensor: "Medidor de fraldas",
    online: "Ligado",
    offline: "Desligado",
};

const label = (value) => VALUE_LABELS[value] ?? String(value ?? "");

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
class ServerSelectFloatingFilter {
    init(params) {
        const options = params.options ?? [];
        this.select = document.createElement("select");
        this.select.className = "ag-floating-filter-input";
        this.select.setAttribute("aria-label", `Filtrar ${params.colDef?.headerName ?? ""}`);
        this.select.innerHTML = ["<option value=\"\">Todos</option>"]
            .concat(options.map((option) => {
                const count = option.count === null || option.count === undefined ? "" : ` (${option.count})`;
                const value = String(option.value ?? "");
                return `<option value="${value.replace(/"/g, "&quot;")}">${label(value)}${count}</option>`;
            }))
            .join("");

        this.select.addEventListener("change", () => {
            params.parentFilterInstance((instance) => {
                instance.setModel(this.select.value === "" ? null : { value: this.select.value });
                params.api.onFilterChanged();
            });
        });
    }

    getGui() {
        return this.select;
    }

    onParentModelChanged(model) {
        this.select.value = model?.value ?? "";
    }
}

/** Uma coluna do descritor na forma que o AG Grid entende. */
function toColumnDef(column) {
    const definition = {
        field: column.field,
        headerName: COLUMN_TITLES[column.field] ?? column.field,
        sortable: column.sortable === true,
        editable: column.editable === true,
        resizable: true,
        minWidth: 120,
        flex: 1,
        // Quem ordena é o servidor, e a grelha ordenaria por cima a página que recebeu. Com
        // vinte linhas o resultado até coincidia, mas escondia as regras que só o servidor
        // tem -- o desempate por IMEI e a falta de valor a ir para o fim.
        comparator: () => 0,
    };

    if (column.field === "online") {
        // `cellDataType: "text"` porque o AG Grid desenha um booleano como caixa de marcar e
        // ignora o formatador: aqui quer-se a palavra, e o estado não se edita.
        definition.cellDataType = "text";
        definition.valueFormatter = (params) => label(params.value ? "online" : "offline");
    } else if (column.field === "deviceType") {
        definition.valueFormatter = (params) => label(params.value);
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
        definition.floatingFilterComponentParams = { options: filter.options ?? [] };
    }

    return definition;
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

export const themeFor = (dark) => agGrid.themeQuartz.withParams(dark ? THEME_PARAMS.dark : THEME_PARAMS.light);

/**
 * O estado do cabeçalho traduzido em parâmetros do pedido.
 *
 * O `param` de cada filtro vem do descritor, e não de um mapa escrito aqui: é assim que uma
 * coluna nova passa a filtrar sem se tocar neste ficheiro.
 */
function requestParams(columns, page, limit, columnState, filterModel) {
    const byField = new Map(columns.map((column) => [column.field, column]));
    const params = { page, limit };

    // O `sortIndex` é a precedência quando se ordena por mais do que uma coluna.
    const sort = columnState
        .filter((state) => state.sort && byField.get(state.colId)?.sortable)
        .sort((left, right) => (left.sortIndex ?? 0) - (right.sortIndex ?? 0))
        .map((state) => (state.sort === "desc" ? "-" : "") + state.colId);
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

export function createDeviceGrid({ element, columns, load, save, onPage, onError, pageSize = 20, dark = true }) {
    agGrid.ModuleRegistry.registerModules([agGrid.AllCommunityModule]);

    let page = 1;
    let limit = pageSize;
    let ready = false;

    async function refresh() {
        const state = api.getColumnState();
        const response = await load(requestParams(columns, page, limit, state, api.getFilterModel()));
        page = response.pagination?.page ?? page;
        api.setGridOption("rowData", response.data || []);
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

    const api = agGrid.createGrid(element, {
        theme: themeFor(dark),
        columnDefs: columns.map(toColumnDef),
        rowData: [],
        // O servidor manda em tudo o que estreita ou reordena a lista.
        pagination: false,
        // Sem `multiSortKey` o AG Grid usa Shift+clique para a segunda coluna. Com `"ctrl"`
        // seria Ctrl ou Cmd, e no macOS o Ctrl+clique é o clique-direito do sistema.
        suppressMovableColumns: false,
        overlayNoRowsTemplate: "<span class=\"p-3\">Nenhum dispositivo para este filtro.</span>",
        defaultColDef: { resizable: true },
        onSortChanged: restart,
        onFilterChanged: restart,
        onCellValueChanged: async (event) => {
            try {
                await save(event.data, event.colDef.field);
            } catch (error) {
                event.node.setDataValue(event.colDef.field, event.oldValue);
                onError?.(error);
            }
        },
        onGridReady: () => {
            ready = true;
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

/** O que alimenta um menu de escolher colunas, construído do que a grelha tem. */
export function columnToggleItems(api) {
    return api.getColumns()
        .filter((column) => column.getColDef().field)
        .map((column) => ({
            field: column.getColId(),
            title: column.getColDef().headerName,
            visible: column.isVisible(),
            toggle: () => api.setColumnsVisible([column.getColId()], !column.isVisible()),
        }));
}
