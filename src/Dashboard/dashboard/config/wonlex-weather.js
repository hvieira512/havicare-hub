import {esc} from "../format.js";

export function wonlexWeatherInput(desired) {
    const weather = normalizeWonlexWeather(desired);
    const types = [[0, "Sol"], [1, "Nublado"], [2, "Vento"], [3, "Chuva"], [4, "Neve"], [5, "Muitas nuvens"], [6, "Nevoeiro"], [7, "Outro"]];
    const measurements = [["temperature", "Temperatura atual", "°C"], ["daytemp", "Máxima diurna", "°C"], ["nighttemp", "Mínima noturna", "°C"], ["humidity", "Humidade", "%"]];
    return `
        <div class="vstack gap-3">
            <div class="small text-secondary">Os dados são enviados para o mostrador meteorológico do relógio.</div>
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label form-label-sm">Condição</label><input class="form-control" type="text" data-weather-field="weather" value="${esc(weather.weather)}" placeholder="Ex.: Nublado" required></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Tipo de tempo</label><select class="form-select" data-weather-field="weatherType">${types.map(([value, label]) => `<option value="${value}" ${weather.weatherType === value ? "selected" : ""}>${esc(label)}</option>`).join("")}</select></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Data da previsão</label><input class="form-control" type="datetime-local" step="1" data-weather-field="reporttime" value="${esc(weatherDateTimeLocal(weather.reporttime))}" required></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Distrito / província</label><input class="form-control" type="text" data-weather-field="province" value="${esc(weather.province)}" required></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Cidade</label><input class="form-control" type="text" data-weather-field="city" value="${esc(weather.city)}" required></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Código da região</label><input class="form-control" type="text" data-weather-field="adcode" value="${esc(weather.adcode)}" placeholder="Ex.: 1106" required></div>
                ${measurements.map(([field, label, suffix]) => `<div class="col-sm-6 col-md-3"><label class="form-label form-label-sm">${esc(label)}</label><div class="input-group"><input class="form-control" type="number" step="0.1" data-weather-field="${field}" value="${esc(weather[field])}" required><span class="input-group-text">${esc(suffix)}</span></div></div>`).join("")}
                <div class="col-md-4"><label class="form-label form-label-sm">Direção do vento</label><input class="form-control" type="text" data-weather-field="winddirection" value="${esc(weather.winddirection)}" placeholder="Ex.: Noroeste" required></div>
                <div class="col-md-4"><label class="form-label form-label-sm">Força do vento</label><input class="form-control" type="text" data-weather-field="windpower" value="${esc(weather.windpower)}" placeholder="Ex.: 3" required></div>
                <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" role="switch" data-weather-field="iIsCDMA" ${weather.iIsCDMA === "1" ? "checked" : ""}><label class="form-check-label" data-switch-label data-switch-on="Rede CDMA" data-switch-off="Rede não CDMA">${weather.iIsCDMA === "1" ? "Rede CDMA" : "Rede não CDMA"}</label></div></div>
            </div>
        </div>`;
}

export function defaultWonlexWeather() {
    return {iIsCDMA: "0", weather: "", weatherType: 0, province: "", city: "", adcode: "", temperature: "", winddirection: "", windpower: "", humidity: "", daytemp: "", nighttemp: "", reporttime: ""};
}

export function readWonlexWeather(section) {
    const value = field => String(section.querySelector(`[data-weather-field="${field}"]`)?.value || "").trim();
    const required = [["weather", "condição"], ["province", "distrito / província"], ["city", "cidade"], ["adcode", "código da região"], ["temperature", "temperatura atual"], ["winddirection", "direção do vento"], ["windpower", "força do vento"], ["humidity", "humidade"], ["daytemp", "temperatura máxima"], ["nighttemp", "temperatura mínima"], ["reporttime", "data da previsão"]];
    for (const [field, label] of required) {
        if (value(field) === "") throw new Error(`Dados meteorológicos: indique ${label}`);
    }
    return {iIsCDMA: section.querySelector('[data-weather-field="iIsCDMA"]')?.checked ? "1" : "0", weather: value("weather"), weatherType: parseInt(value("weatherType"), 10) || 0, province: value("province"), city: value("city"), adcode: value("adcode"), temperature: value("temperature"), winddirection: value("winddirection"), windpower: value("windpower"), humidity: value("humidity"), daytemp: value("daytemp"), nighttemp: value("nighttemp"), reporttime: weatherWireDateTime(value("reporttime"))};
}

function normalizeWonlexWeather(desired) {
    const source = desired?.weather && typeof desired.weather === "object" ? desired.weather : desired || {};
    const fallback = defaultWonlexWeather();
    return {...fallback, ...Object.fromEntries(Object.entries(source).map(([key, value]) => [key, String(value ?? "")])), weatherType: parseInt(String(source.weatherType ?? fallback.weatherType), 10) || 0, iIsCDMA: String(source.iIsCDMA ?? fallback.iIsCDMA) === "1" ? "1" : "0"};
}

function weatherDateTimeLocal(value) {
    return String(value || "").trim().replace(" ", "T").slice(0, 19);
}

function weatherWireDateTime(value) {
    const normalized = String(value || "").trim().replace("T", " ");
    return normalized.length === 16 ? `${normalized}:00` : normalized;
}
