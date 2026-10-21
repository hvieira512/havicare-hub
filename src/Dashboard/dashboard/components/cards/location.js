import { ago } from "../../format.js";
import { html } from "../../html.js";
import { joinMarkup } from "./shared.js";

/** O par 0,0 é como os protocolos dizem «sem fixo», mesmo marcado com coordenadas. */
export function locationCoordinates(data) {
    const [lat, lon] = [Number(data?.lat), Number(data?.lon)];

    return data?.hasCoordinates === true && (lat !== 0 || lon !== 0) ? { lat, lon } : null;
}

/** Onde está, ou um travessão. Cinco decimais são ~1 m, a precisão do melhor fixo do mapa de rádio. */
export function locationValue(data) {
    const fix = locationCoordinates(data);
    return fix ? `${fix.lat.toFixed(5)}, ${fix.lon.toFixed(5)}` : "—";
}

/** GPS ou rádio: `cell`, `wifi` e `cell_wifi` são todos triangulação. */
function locationFixLabel(data) {
    const source = String(data?.source || "").toLowerCase();
    if (source === "") return "";
    return source === "gps" ? "GPS" : "Rádio";
}

function locationAccuracy(data) {
    const meters = Number(data?.accuracyMeters);
    if (!Number.isFinite(meters) || meters <= 0) return "";
    return `±${Math.max(1, Math.round(meters))} m`;
}

/** A prova de rádio de uma leitura sem posição; sem antenas nem redes, a falha é do aparelho. */
function locationRadioEvidence(data) {
    const cells = Array.isArray(data?.baseStations)
        ? data.baseStations.length
        : 0;
    const wifi = Array.isArray(data?.wifiAccessPoints)
        ? data.wifiAccessPoints.length
        : 0;
    if (cells === 0 && wifi === 0) return "Sem dados de rádio";

    return [
        cells ? `${cells} ${cells === 1 ? "antena" : "antenas"}` : "",
        wifi ? `${wifi} ${wifi === 1 ? "rede WiFi" : "redes WiFi"}` : "",
    ]
        .filter(Boolean)
        .join(" · ");
}

/** Com posição, como e com que precisão; sem ela, com que evidência. A idade só no mosaico. */
export function locationDetails(data, meta = {}) {
    const parts = locationCoordinates(data)
        ? [locationFixLabel(data), locationAccuracy(data)]
        : [locationRadioEvidence(data)];

    return joinMarkup(
        [...parts, meta?.occurredAt ? ago(meta.occurredAt) : ""]
            .filter(Boolean)
            .map((part) => html`${part}`),
    );
}
