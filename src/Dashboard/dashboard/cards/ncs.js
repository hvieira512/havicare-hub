import { capabilityLabel } from "../capability-catalog.js";
import { PRESS_TYPE_LABEL } from "../domain.js";

/**
 * Os cartões da chamada de enfermagem Voerka: o pedido de ajuda e o pager.
 */

const NCS_PAGER_EVENT_VALUE = {
    help_call: "Chamada de ajuda",
    reset: "Cancelado",
};

const NCS_PAGER_EVENT_ICON = {
    help_call: "fa-triangle-exclamation",
    reset: "fa-bell-slash",
};

export function helpCallContent(data) {
    const base = ncsPagerContent("help_call", data);
    const pressType = PRESS_TYPE_LABEL[String(data?.pressType || "")];

    return pressType === undefined
        ? base
        : { ...base, value: `${base.value} (${pressType})` };
}

/**
 * O `rowValue` leva o comando porque o nome da linha já diz o que aconteceu, e sem ele a
 * coluna do valor repetia "Chamada de ajuda" ao lado de "Chamada de ajuda".
 */
export function ncsPagerContent(type, data) {
    const value = NCS_PAGER_EVENT_VALUE[type] || capabilityLabel(type);
    const icon = NCS_PAGER_EVENT_ICON[type] || "fa-bell";
    const pagerId = String(data?.pagerId || "");

    return pagerId === ""
        ? { icon, value }
        : { icon, value, rowValue: `Pager ${pagerId}` };
}
