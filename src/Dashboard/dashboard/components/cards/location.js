import { ago } from "../../format.js";
import { html } from "../../html.js";

/**
 * Os cartões de localização: coordenadas, tipo de fix, precisão e a evidência rádio.
 */

/**
 * Lê o `lat`/`lon` e não o `hasCoordinates`, que falta nos eventos antigos do Redis. O par
 * 0,0 é como os protocolos dizem "sem fixo".
 */
export function locationCoordinates(data) {
    const lat = Number(data?.lat);
    const lon = Number(data?.lon);
    if (!Number.isFinite(lat) || !Number.isFinite(lon)) return null;
    if (lat === 0 && lon === 0) return null;
    return { lat, lon };
}

/**
 * Onde está, ou um travessão: o slot grande responde a uma pergunta só, e sem posição não
 * há resposta. Cinco decimais são ~1 m, a precisão do melhor fixo do mapa de rádio.
 */
export function locationValue(data) {
    const fix = locationCoordinates(data);
    return fix ? `${fix.lat.toFixed(5)}, ${fix.lon.toFixed(5)}` : "—";
}

/**
 * Como se obteve a posição: GPS, ou rádio, e não a origem crua. `cell`, `wifi` e
 * `cell_wifi` são todos triangulação, e o que os distingue do GPS é a proveniência.
 */
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

/**
 * A prova de rádio de uma leitura que não deu posição. Sem antenas nem redes é outra falha:
 * o aparelho reportou e não viu nada, o que aponta para ele e não para a cobertura.
 */
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

/**
 * Com posição, como e com que precisão; sem posição, com que evidência se tentou. A idade só
 * aparece no mosaico -- na lista cronológica a hora já tem coluna.
 */
export function locationDetails(data, meta = {}) {
    const parts = locationCoordinates(data)
        ? [locationFixLabel(data), locationAccuracy(data)]
        : [locationRadioEvidence(data)];

    return [...parts, meta?.occurredAt ? ago(meta.occurredAt) : ""]
        .filter(Boolean)
        .map((part) => html`${part}`)
        .join(" · ");
}
