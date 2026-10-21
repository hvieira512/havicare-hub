import { capabilityLabel } from "../../capability-catalog.js";
import { UNTRANSLATED, valueLabel } from "../../i18n.js";

const CONNECTIVITY_INTERFACE_ICONS = {
    wifi: "fa-wifi",
    ethernet: "fa-ethernet",
    ethernet_wifi: "fa-network-wired",
    cellular: "fa-tower-cell",
    "4g": "fa-tower-cell",
};

export function connectivityIcon(data) {
    return (
        CONNECTIVITY_INTERFACE_ICONS[String(data?.interface || "").trim()] ||
        "fa-wifi"
    );
}

export function connectivityValue(data) {
    const parts = [];
    const iface = String(data?.interface || "").trim();
    if (iface !== "") {
        parts.push(valueLabel("interface", iface) ?? UNTRANSLATED);
    }

    const networkType = String(data?.networkType || "").trim();
    if (networkType !== "") {
        parts.push(networkType);
    }

    // Um gateway com fios não reporta RSSI e 0 dBm é leitura legítima: testar contra null.
    const dbm = data?.signalStrengthDbm;
    if (
        dbm !== null &&
        dbm !== undefined &&
        dbm !== "" &&
        Number.isFinite(Number(dbm))
    ) {
        parts.push(`${Number(dbm)} dBm`);
    }

    return parts.length > 0
        ? parts.join(" · ")
        : capabilityLabel("connectivity");
}
