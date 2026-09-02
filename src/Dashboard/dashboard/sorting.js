/**
 * A ordenação das listas que carregam inteiras — os utilizadores da API e a árvore de
 * empresas. As listagens paginadas no servidor não passam por aqui: ordenar no cliente
 * ordenaria só a página visível, e a lista de dispositivos manda `sort` no pedido.
 */

const collator = new Intl.Collator("pt", { sensitivity: "base", numeric: true });

const isMissing = (value) => value === null || value === undefined || value === "";

/** Ausente vai sempre para o fim, nos dois sentidos: não é o menor valor, é a falta dele. */
function compare(left, right) {
    if (isMissing(left) || isMissing(right)) {
        return isMissing(left) && isMissing(right) ? 0 : isMissing(left) ? 1 : -1;
    }
    if (typeof left === "number" && typeof right === "number") {
        return left - right;
    }

    return collator.compare(String(left), String(right));
}

/**
 * O estado seguinte ao carregar num cabeçalho: ascendente, descendente, e depois nenhum.
 * O terceiro estado é a ordem original, e sem ele não há como voltar atrás.
 */
export function nextSort(current, column) {
    if (!current || current.column !== column) {
        return { column, descending: false };
    }

    return current.descending ? null : { column, descending: true };
}

/** Devolve sempre uma cópia: ordenar no sítio mexia na lista que outra vista está a mostrar. */
export function sortRows(rows, sort, value = (row, column) => row[column]) {
    const copy = [...rows];
    if (!sort) {
        return copy;
    }

    // A falta fica no fim mesmo em descendente, e por isso a inversão é do resultado da
    // comparação e não da ordem toda.
    const direction = sort.descending ? -1 : 1;

    return copy.sort((left, right) => {
        const a = value(left, sort.column);
        const b = value(right, sort.column);
        if (isMissing(a) || isMissing(b)) {
            return compare(a, b);
        }

        return direction * compare(a, b);
    });
}
