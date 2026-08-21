import {
    getDiaperSensitivity as apiGetDiaperSensitivity,
    resetDiaperSensitivity as apiResetDiaperSensitivity,
    saveDiaperSensitivity as apiSaveDiaperSensitivity,
} from "../api/index.js";

/**
 * O selector de sensibilidade do medidor de fraldas, no separador das configuracoes.
 *
 * Grupo de botoes com a mesma forma do da sensibilidade de queda dos relogios -- verde
 * para menos sensivel, ambar para normal, vermelho para mais sensivel -- mas com clique
 * proprio: o `data-config-choice-group` daquele esta ligado ao dispatcher das
 * configuracoes por downlink, e esta linha nao atravessa esse caminho.
 *
 * Os dois inteiros sao a unica fonte de verdade e o nome do perfil e derivado deles,
 * aqui como no servidor: guardar perfil E valores permitia que discordassem. Os tres
 * presets preenchem os campos; "Personalizado" apenas os revela.
 *
 * Nao ha downlink. O sensor e um beacon BLE que so transmite, e o que estes valores
 * mudam e a regra com que o hub deriva o estado da fralda.
 */

let els;

/** Preenchido a partir da API, para nao manter aqui uma segunda copia das fronteiras. */
let presets = {};
let bounds = {};

/**
 * O sensor cujos valores estao no ecra, ou "" quando nao ha nenhum carregado.
 *
 * E isto que decide se ha algo para gravar, e nao a visibilidade da linha: ela vive num
 * separador escondido enquanto o dispositivo nao existe, e uma decisao baseada em
 * visibilidade dependia de duas coisas que se podem dessincronizar.
 */
let loadedImei = "";
let selectedProfile = "normal";

const NORMAL = {pollutionRange: 4, pollutionValue: 12};

export function initDiaperSensitivityUi(context) {
    els = context.els;
    els.deviceDiaperSensitivityGroup?.addEventListener("click", (event) => {
        const button = event.target.closest("[data-diaper-profile]");
        if (!button) return;
        event.preventDefault();
        applyProfile(String(button.dataset.diaperProfile || "normal"));
    });
}

/** Marca o botao activo e, num preset, escreve os valores que ele representa. */
function applyProfile(profile) {
    selectedProfile = profile;
    const group = els.deviceDiaperSensitivityGroup;
    for (const button of group?.querySelectorAll("[data-diaper-profile]") || []) {
        const active = button.dataset.diaperProfile === profile;
        button.classList.toggle("active", active);
        button.setAttribute("aria-pressed", active ? "true" : "false");
    }

    const preset = presets[profile];
    if (preset) {
        setValues(preset.pollutionRange, preset.pollutionValue);
    }
    els.deviceDiaperSensitivityCustom?.classList.toggle(
        "d-none",
        profile !== "custom",
    );
}

function setValues(pollutionRange, pollutionValue) {
    if (els.deviceDiaperPollutionRange) {
        els.deviceDiaperPollutionRange.value = String(pollutionRange ?? "");
    }
    if (els.deviceDiaperPollutionValue) {
        els.deviceDiaperPollutionValue.value = String(pollutionValue ?? "");
    }
}

function applyBounds() {
    for (const [field, element] of [
        ["pollutionRange", els.deviceDiaperPollutionRange],
        ["pollutionValue", els.deviceDiaperPollutionValue],
    ]) {
        const limits = bounds[field];
        if (Array.isArray(limits) && element) {
            element.min = String(limits[0]);
            element.max = String(limits[1]);
        }
    }
}

/** Mostra a linha e carrega os valores em vigor. Um sensor novo ainda nao os tem. */
export async function loadDiaperSensitivity(imei, deviceType) {
    const visible = deviceType === "diaper_sensor";
    els.deviceDiaperSensitivityRow?.classList.toggle("d-none", !visible);
    loadedImei = "";
    if (!visible || !imei) {
        // Um sensor por criar mostra o preset normal, que e o que vai receber.
        presets = {normal: NORMAL};
        applyProfile("normal");
        return;
    }

    const result = await apiGetDiaperSensitivity(imei);
    const data = result?.data;
    if (!data) {
        presets = {normal: NORMAL};
        applyProfile("normal");
        return;
    }
    presets = data.presets || {};
    bounds = data.bounds || {};
    applyBounds();
    setValues(data.pollutionRange, data.pollutionValue);
    // Depois de escrever os valores: num perfil personalizado o applyProfile nao os
    // reescreve, e assim os que vieram da API sobrevivem.
    applyProfile(data.profile || "custom");
    loadedImei = imei;
}

/** Se ha sensibilidade carregada, para o painel de baixo nao se declarar vazio. */
export function hasDiaperSensitivity() {
    return loadedImei !== "";
}

/**
 * O que esta escolhido no ecra, ou null quando nao ha escolha para gravar.
 *
 * Devolve null quando nada foi carregado -- num dispositivo novo nao houve escolha
 * nenhuma, e devolver o preset por omissao mandava um pedido inutil a cada gravacao.
 */
export function selectedDiaperSensitivity(deviceType) {
    if (deviceType !== "diaper_sensor" || loadedImei === "") {
        return null;
    }
    const preset = presets[selectedProfile];
    if (preset) return {...preset, profile: selectedProfile};

    return {
        profile: selectedProfile,
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
