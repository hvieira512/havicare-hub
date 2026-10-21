import { capabilityLabel } from "../../capability-catalog.js";
import { PRESS_TYPE_LABEL } from "../../domain.js";

const NCS_PAGER_EVENT_VALUE = {
    help_call: "Chamada de ajuda",
    reset: "Cancelado",
};

export function helpCallContent(data) {
    const base = ncsPagerContent("help_call", data);
    const pressType = PRESS_TYPE_LABEL[String(data?.pressType || "")];

    return pressType === undefined
        ? base
        : { ...base, value: `${base.value} (${pressType})` };
}

/** O `rowValue` leva o comando, porque o nome da linha já diz «Chamada de ajuda». */
export function ncsPagerContent(type, data) {
    const value = NCS_PAGER_EVENT_VALUE[type] || capabilityLabel(type);
    const pagerId = String(data?.pagerId || "");

    return pagerId === "" ? { value } : { value, rowValue: `Pager ${pagerId}` };
}
