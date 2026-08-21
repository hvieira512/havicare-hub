import {
    getDiaperSensitivity as apiGetDiaperSensitivity,
    resetDiaperSensitivity as apiResetDiaperSensitivity,
    saveDiaperSensitivity as apiSaveDiaperSensitivity,
} from "../api/index.js";

/**
 * O selector de sensibilidade do medidor de fraldas, no modal do dispositivo.
 *
 * Os dois inteiros sao a unica fonte de verdade e o nome do perfil e derivado deles,
 * aqui como no servidor: guardar perfil E valores permitia que discordassem. O select
 * escolhe um preset, e "Personalizado" apenas revela os dois campos.
 *
 * Nao ha downlink. O sensor e um beacon BLE que so transmite, e o que estes valores
 * mudam e a regra com que o hub deriva o estado da fralda.
 *
 * Segue a forma dos outros modulos de vista -- recebe o mapa de elementos pelo
 * initDiaperSensitivityUi em vez de ir buscar um.
 */

let els;

/** Preenchido a partir da API, para nao manter aqui uma segunda copia das fronteiras. */
let presets = {};
let bounds = {};

const NORMAL = {pollutionRange: 4, pollutionValue: 12};

export function initDiaperSensitivityUi(context) {
    els = context.els;
    els.deviceDiaperSensitivityProfile?.addEventListener("change", () => {
        applyProfile(els.deviceDiaperSensitivityProfile.value);
    });
}

function applyProfile(profile) {
    const preset = presets[profile];
    if (preset) {
        els.deviceDiaperPollutionRange.value = String(preset.pollutionRange);
        els.deviceDiaperPollutionValue.value = String(preset.pollutionValue);
    }
    els.deviceDiaperSensitivityCustom?.classList.toggle(
        "d-none",
        profile !== "custom",
    );
}

function applyBounds() {
    const range = bounds.pollutionRange;
    const value = bounds.pollutionValue;
    if (Array.isArray(range) && els.deviceDiaperPollutionRange) {
        els.deviceDiaperPollutionRange.min = String(range[0]);
        els.deviceDiaperPollutionRange.max = String(range[1]);
    }
    if (Array.isArray(value) && els.deviceDiaperPollutionValue) {
        els.deviceDiaperPollutionValue.min = String(value[0]);
        els.deviceDiaperPollutionValue.max = String(value[1]);
    }
}

/** Mostra a linha e carrega os valores em vigor. Um sensor novo ainda nao os tem. */
export async function loadDiaperSensitivity(imei, deviceType) {
    const visible = deviceType === "diaper_sensor";
    els.deviceDiaperSensitivityRow?.classList.toggle("d-none", !visible);
    if (!visible || !imei) {
        // Um sensor por criar mostra o preset normal, que e o que vai receber.
        presets = presets || {};
        setFields("normal", NORMAL);
        return;
    }

    const result = await apiGetDiaperSensitivity(imei);
    const data = result?.data;
    if (!data) {
        setFields("normal", NORMAL);
        return;
    }
    presets = data.presets || {};
    bounds = data.bounds || {};
    applyBounds();
    setFields(data.profile || "custom", data);
}

function setFields(profile, values) {
    if (els.deviceDiaperSensitivityProfile) {
        els.deviceDiaperSensitivityProfile.value = profile;
    }
    if (els.deviceDiaperPollutionRange) {
        els.deviceDiaperPollutionRange.value = String(values.pollutionRange ?? "");
    }
    if (els.deviceDiaperPollutionValue) {
        els.deviceDiaperPollutionValue.value = String(values.pollutionValue ?? "");
    }
    els.deviceDiaperSensitivityCustom?.classList.toggle(
        "d-none",
        profile !== "custom",
    );
}

/** O que esta escolhido no ecra, ou null quando a linha nao se aplica. */
export function selectedDiaperSensitivity(deviceType) {
    if (deviceType !== "diaper_sensor" || !els.deviceDiaperSensitivityProfile) {
        return null;
    }
    const profile = els.deviceDiaperSensitivityProfile.value;
    const preset = presets[profile];
    if (preset) return {...preset, profile};

    return {
        profile,
        pollutionRange: Number(els.deviceDiaperPollutionRange?.value),
        pollutionValue: Number(els.deviceDiaperPollutionValue?.value),
    };
}

/**
 * Grava, devolvendo a mensagem de erro ou null.
 *
 * O preset normal e gravado por remocao e nao por escrita: a ausencia de linha JA
 * significa normal, e uma linha a dizer o mesmo era estado a manter sem necessidade.
 */
export async function saveDiaperSensitivity(imei, selection) {
    if (!selection) return null;
    const {pollutionRange, pollutionValue} = selection;
    const result = selection.profile === "normal"
        ? await apiResetDiaperSensitivity(imei)
        : await apiSaveDiaperSensitivity(imei, {pollutionRange, pollutionValue});

    return result?.error ? (result.error.message || result.error.code) : null;
}
