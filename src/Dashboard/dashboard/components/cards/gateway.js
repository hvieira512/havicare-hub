import { capabilityLabel } from "../../capability-catalog.js";
import { titleize } from "../../format.js";

/**
 * Os cartões do gateway: que interfaces tem, e por qual está a falar.
 */

// As interfaces que o `Hub\Ingress\Mqtt\Moko\GatewayNormalizer` emite.
const CONNECTIVITY_INTERFACE_LABELS = {
    wifi: "Wi-Fi",
    ethernet: "Ethernet",
    ethernet_wifi: "Ethernet + Wi-Fi",
    cellular: "Rede móvel",
};

const CONNECTIVITY_INTERFACE_ICONS = {
    wifi: "fa-wifi",
    ethernet: "fa-ethernet",
    ethernet_wifi: "fa-network-wired",
    cellular: "fa-tower-cell",
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
        parts.push(CONNECTIVITY_INTERFACE_LABELS[iface] || titleize(iface));
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
