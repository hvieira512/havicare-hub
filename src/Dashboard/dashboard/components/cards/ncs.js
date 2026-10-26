import { capabilityLabel } from "../../core/capability-catalog.js";
import { valueLabel } from "../../core/i18n.js";

export function helpCallContent(data) {
    const base = ncsPagerContent("help_call", data);
    const pressType = valueLabel("pressType", data?.pressType ?? "");

    return pressType === undefined
        ? base
        : { ...base, value: `${base.value} (${pressType})` };
}

/** O `rowValue` leva o comando, porque o nome da linha já diz «Chamada de ajuda». */
export function ncsPagerContent(type, data) {
    const value = valueLabel("ncsEvent", type) ?? capabilityLabel(type);
    const pagerId = String(data?.pagerId || "");

    return pagerId === "" ? { value } : { value, rowValue: `Pager ${pagerId}` };
}
