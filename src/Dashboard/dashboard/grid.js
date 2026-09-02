/**
 * Uma grelha construída a partir do descritor que a API devolve.
 *
 * O `GET /api/devices` diz que colunas existem, quais se ordenam, quais se filtram e com
 * que opções. Este módulo traduz isso para a configuração do Tabulator, e é o único sítio
 * do projeto que conhece essa biblioteca -- trocá-la é reescrever este ficheiro.
 *
 * Ordenar, filtrar e paginar acontecem no servidor. Fazê-lo no cliente ordenaria só a
 * página visível, o que parece funcionar e mente.
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

const label = (value) => VALUE_LABELS[value] || String(value ?? "");

/** As opções de um dropdown, com a contagem ao lado quando o servidor a soube apurar. */
function filterOptions(options = []) {
    return options.map((option) => ({
        value: option.value,
        label: option.count === null || option.count === undefined
            ? label(option.value)
            : `${label(option.value)} (${option.count})`,
    }));
}

/**
 * Uma coluna do descritor na forma que o Tabulator entende.
 *
 * O `headerFilter` é escolhido pelo tipo que o servidor declarou, e não adivinhado: uma
 * coluna de conjunto fechado ganha lista, uma de texto livre ganha caixa.
 */
function toTabulatorColumn(column) {
    const definition = {
        field: column.field,
        title: COLUMN_TITLES[column.field] || column.field,
        headerSort: column.sortable === true,
        resizable: true,
        minWidth: 110,
    };

    if (column.field === "online") {
        definition.formatter = (cell) => label(cell.getValue() ? "online" : "offline");
    } else if (column.field === "deviceType") {
        definition.formatter = (cell) => label(cell.getValue());
    }

    if (column.editable === true) {
        definition.editor = "input";
    }

    const filter = column.filter;
    if (filter?.type === "text") {
        definition.headerFilter = "input";
        definition.headerFilterPlaceholder = "Procurar…";
    } else if (filter?.type === "select") {
        definition.headerFilter = "list";
        definition.headerFilterParams = {
            values: filterOptions(filter.options),
            clearable: true,
            multiselect: filter.multiple === true,
        };
    }

    return definition;
}

/**
 * O estado do cabeçalho traduzido em parâmetros do pedido.
 *
 * O `param` de cada filtro vem do descritor, e não de um mapa escrito aqui: é assim que
 * uma coluna nova passa a filtrar sem se tocar neste ficheiro.
 */
function requestParams(columns, page, size, sorters = [], filters = []) {
    const byField = new Map(columns.map((column) => [column.field, column]));
    const params = { page, limit: size };

    // A ordem dos `sorters` é a precedência, e o Tabulator entrega-os já assim.
    const sort = sorters
        .filter((sorter) => byField.get(sorter.field)?.sortable)
        .map((sorter) => (sorter.dir === "desc" ? "-" : "") + sorter.field);
    if (sort.length) {
        params.sort = sort.join(",");
    }

    for (const applied of filters) {
        const descriptor = byField.get(applied.field)?.filter;
        if (!descriptor || applied.value === "" || applied.value === null || applied.value === undefined) {
            continue;
        }
        params[descriptor.param] = Array.isArray(applied.value) ? applied.value : String(applied.value);
    }

    return params;
}

/**
 * Levanta a grelha.
 *
 * `load` vai buscar uma página e devolve a resposta da API tal e qual; `save` recebe a
 * linha inteira depois de uma célula mudar. A linha inteira e não o campo sozinho porque o
 * `PUT` substitui o registo: mandar só o campo apagava os outros.
 */
export function createDeviceGrid({ element, columns, load, save, onPage, onError, pageSize = 20 }) {
    // A paginação é a do projeto -- o `renderPagination` do `pagination.js` --, e não a que
    // o Tabulator traz: um paginador diferente em cada listagem é uma dashboard incoerente.
    // Daí a página viver aqui, e não dentro dele.
    let page = 1;
    let limit = pageSize;

    const table = new Tabulator(element, {
        layout: "fitColumns",
        height: "100%",
        placeholder: "Nenhum dispositivo para este filtro.",
        movableColumns: true,
        columnHeaderSortMulti: true,
        resizableColumnGuide: true,
        columns: columns.map(toTabulatorColumn),
        index: "imei",

        // O servidor manda em tudo o que estreita ou reordena a lista.
        sortMode: "remote",
        filterMode: "remote",
        pagination: false,

        ajaxURL: "/api/devices",
        ajaxRequestFunc: async (_url, _config, params) => {
            const response = await load(requestParams(
                columns,
                page,
                limit,
                params.sort || [],
                params.filter || [],
            ));

            // Ordenar ou filtrar muda o conjunto, e a página 4 do anterior não é a mesma.
            const pagination = response.pagination || {};
            if ((pagination.page ?? 1) !== page) {
                page = pagination.page ?? 1;
            }
            onPage?.(pagination);

            return response.data || [];
        },
    });

    // Reordenar ou filtrar volta à primeira página. Sem isto, quem estivesse na página 3 e
    // filtrasse pedia a página 3 de uma lista que passou a ter duas.
    const backToFirstPage = () => {
        page = 1;
    };
    table.on("dataSorting", backToFirstPage);
    table.on("dataFiltering", backToFirstPage);

    table.on("cellEdited", async (cell) => {
        try {
            await save(cell.getRow().getData(), cell.getField());
        } catch (error) {
            cell.restoreOldValue();
            onError?.(error);
        }
    });

    return {
        table,
        goToPage(next) {
            page = next;
            return table.setData();
        },
        /** Mudar quantas linhas por página volta ao princípio: a página 3 era de outra lista. */
        setPageSize(size) {
            limit = size;
            page = 1;
            return table.setData();
        },
        /** Ordenar e filtrar recomeçam na primeira página, pela mesma razão. */
        resetPage() {
            page = 1;
        },
    };
}

/** O menu de escolher que colunas se vêem, construído do que a grelha tem. */
export function columnToggleItems(table) {
    return table.getColumns()
        .filter((column) => column.getField())
        .map((column) => ({
            field: column.getField(),
            title: column.getDefinition().title,
            visible: column.isVisible(),
            toggle: () => column.toggle(),
        }));
}
