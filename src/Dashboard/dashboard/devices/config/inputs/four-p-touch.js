import { esc } from "../../../format.js";
import { field } from "../../../components/form-field.js";
import { renderPhoneControl } from "../../../phone.js";
import { boolValue } from "../normalizers.js";
import { readTakePills, takePillsInput } from "../four-p-touch-take-pills.js";
import { enabledSwitch, numberField } from "./shared.js";
import {
    readCheckbox,
    readNumber,
    readPhone,
    readText,
    readTextArray,
} from "../readers.js";

/**
 * Os campos que só o 4P Touch declara: o perfil de som, os alarmes do relógio, as janelas
 * horárias, o idioma com fuso, a chamada e a escuta.
 */

function makeCallInput(entry, desired) {
    return `
        <div>
            <label class="form-label-sm">Número de telefone</label>
            <div class="d-flex gap-2">
                <div class="flex-grow-1">
                    ${renderPhoneControl({
                        value: String(desired.phone || ""),
                        configField: "phone",
                    })}
                </div>
            </div>
            <div class="form-text">Envia um comando para o relógio fazer uma chamada para o número indicado.</div>
        </div>`;
}

function voiceMonitorInput(entry, desired) {
    return `
        <div>
            <div class="alert alert-warning small py-2 px-3 mb-3">
                <i class="fa-solid fa-triangle-exclamation me-2"></i>
                O relógio liga de imediato para este número e abre o microfone, sem mostrar
                nada a quem o traz no pulso. Não fica guardado como contacto.
            </div>
            <label class="form-label-sm">Número de telefone</label>
            ${renderPhoneControl({
                value: String(desired.phone || ""),
                configField: "phone",
            })}
        </div>`;
}

function soundProfileInput(desired) {
    const current = parseInt(String(desired.mode ?? 1), 10) || 1;
    const options = [
        {
            value: 1,
            label: "Vibração e toque",
            icon: "fa-volume-high",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            label: "Só toque",
            icon: "fa-bell",
            className: "btn-outline-secondary",
        },
        {
            value: 3,
            label: "Só vibração",
            icon: "fa-mobile-screen-button",
            className: "btn-outline-warning",
        },
        {
            value: 4,
            label: "Silêncio",
            icon: "fa-volume-xmark",
            className: "btn-outline-danger",
        },
    ];

    return `
        <div class="vstack gap-2">
            <div class="small text-secondary">Escolha o perfil de som do dispositivo.</div>
            <div class="row row-cols-2 g-2" role="radiogroup" aria-label="Perfil de som">
                ${options
                    .map(
                        (option) => `
                    <div class="col">
                        <input
                            class="btn-check"
                            type="radio"
                            name="soundProfile"
                            id="soundProfile${option.value}"
                            data-config-field="mode"
                            value="${option.value}"
                            ${option.value === current ? "checked" : ""}>
                        <label class="btn ${option.className} w-100 h-100 text-start d-flex align-items-center gap-2 py-3 px-3" for="soundProfile${option.value}">
                            <i class="fa-solid ${option.icon}"></i>
                            <span class="small fw-semibold">${esc(option.label)}</span>
                        </label>
                    </div>
                `,
                    )
                    .join("")}
            </div>
        </div>`;
}

function intervalHoursToggleInput(desired) {
    return `
        <div class="row g-3">
            <div class="col-md-4">${enabledSwitch(boolValue(desired.enabled, true), "mt-4")}</div>
            ${field(
                "Intervalo (horas)",
                numberField("intervalHours", desired.intervalHours ?? 2, { min: 1, max: 12 }),
                { cls: "col-md-8" },
            )}
        </div>`;
}

/**
 * A janela horária em que o aparelho mede.
 *
 * As horas são dois `input type="time"`, e não texto: o navegador já não deixa escrever uma
 * hora que não existe, e o par volta a juntar-se em `HH:MM-HH:MM` na leitura.
 */

const languageTimezonePresetOptions = [
    { language: 0, timeZone: "0", label: "English (UTC+0)" },
    { language: 1, timeZone: "8", label: "简体中文 (UTC+8)" },
    { language: 3, timeZone: "1", label: "Português (UTC+1)" },
    { language: 4, timeZone: "1", label: "Español (UTC+1)" },
    { language: 5, timeZone: "1", label: "Deutsch (UTC+1)" },
    { language: 10, timeZone: "1", label: "Français (UTC+1)" },
];

function languageTimezoneInput(desired) {
    const preset = languageTimezonePresetOptions.find(
        (option) =>
            String(desired.language ?? 3) === String(option.language) &&
            String(desired.timeZone ?? "0") === String(option.timeZone),
    ) || languageTimezonePresetOptions[0];

    return `
        <div class="vstack gap-2">
            <label class="form-label-sm">Idioma e fuso horário</label>
            <select class="form-select" data-config-field="preset">
                ${languageTimezonePresetOptions
                    .map(
                        (option) => `
                        <option value="${option.language}|${esc(String(option.timeZone))}" ${
                            option.language === preset.language &&
                            String(option.timeZone) === String(preset.timeZone)
                                ? "selected"
                                : ""
                        }>${esc(option.label)}</option>
                    `,
                    )
                    .join("")}
            </select>
            <div class="form-text">Escolha a combinação suportada pelo dispositivo.</div>
        </div>`;
}

function dualToggleInput(desired) {
    const enabled = boolValue(desired.enabled, true);
    const callCenterOnFall = boolValue(desired.callCenterOnFall, false);
    return `
        <div class="vstack gap-3">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="enabled" ${enabled ? "checked" : ""}>
                <label class="form-check-label" data-switch-label>${enabled ? "Ligado" : "Desligado"}</label>
            </div>
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" data-config-field="callCenterOnFall" ${callCenterOnFall ? "checked" : ""}>
                <label class="form-check-label" data-switch-label data-switch-on="Liga para o centro" data-switch-off="Não liga para o centro">${callCenterOnFall ? "Liga para o centro" : "Não liga para o centro"}</label>
            </div>
        </div>`;
}

function fallSensitivityLevelsInput(desired) {
    const sensitivityLevel =
        parseInt(String(desired.sensitivity ?? 5), 10) || 5;
    const parsedTotalLevels = parseInt(String(desired.levels ?? ""), 10);
    const totalLevels = [6, 8].includes(parsedTotalLevels)
        ? parsedTotalLevels
        : 8;

    const levels = [
        { label: "Máxima", icon: "fa-bolt", btnClass: "btn-outline-danger" },
        { label: "Muito Alta", icon: "fa-circle-exclamation", btnClass: "btn-outline-danger" },
        { label: "Alta", icon: "fa-triangle-exclamation", btnClass: "btn-outline-warning" },
        { label: "Moderada", icon: "fa-equals", btnClass: "btn-outline-warning" },
        { label: "Baixa", icon: "fa-arrow-down", btnClass: "btn-outline-primary" },
        { label: "Muito Baixa", icon: "fa-angles-down", btnClass: "btn-outline-primary" },
        { label: "Quase Mínima", icon: "fa-feather", btnClass: "btn-outline-secondary" },
        { label: "Mínima", icon: "fa-snowflake", btnClass: "btn-outline-secondary" },
    ];

    return `
        <div class="row g-3">
            <div class="col-12 col-md-9">
                <label class="form-label-sm">Nível de sensibilidade</label>
                <input type="hidden" data-config-field="sensitivity" value="${esc(String(sensitivityLevel))}">
                <div class="d-flex flex-wrap gap-1 w-100 sens-level-group" role="group" aria-label="Nível de sensibilidade" data-config-choice-group="sensitivity">
                    ${levels
                        .map(
                            ({ label, icon, btnClass }, i) => {
                                const level = i + 1;
                                return `
                        <button
                            type="button"
                            class="btn ${btnClass} sens-level-btn d-flex flex-column align-items-center justify-content-center ${level === sensitivityLevel ? "active" : ""} ${level > totalLevels ? "d-none" : ""}"
                            style="flex: 1 0 0; min-width: 4rem; min-height: 4rem"
                            data-action="selectConfigChoice"
                            data-config-field="sensitivity"
                            data-config-value="${level}"
                            aria-pressed="${level === sensitivityLevel ? "true" : "false"}"
                            ${level > totalLevels ? "disabled" : ""}>
                            <div class="d-flex align-items-center gap-1 fw-medium">
                                <i class="fa-solid ${icon}"></i>
                                <span>${level}</span>
                            </div>
                            <div class="small opacity-75">${label}</div>
                        </button>
                    `;
                            },
                        )
                        .join("")}
                </div>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label-sm">Escala do firmware</label>
                <select class="form-select" data-config-field="levels" data-action="fallTotalLevels" required>
                    <option value="" ${totalLevels === null ? "selected" : ""} disabled>Selecione…</option>
                    <option value="6" ${totalLevels === 6 ? "selected" : ""}>6 níveis</option>
                    <option value="8" ${totalLevels === 8 ? "selected" : ""}>8 níveis</option>
                </select>
                <div class="form-text">Escolha a escala indicada para o firmware deste dispositivo.</div>
            </div>
        </div>`;
}

/**
 * A escala do firmware manda nos botões: os níveis acima dela saem da vista, e um valor que
 * ficasse fora da escala volta a um nível que ainda existe.
 */
export function syncFallSensitivityLevels(section, levels) {
    const totalLevels = parseInt(levels, 10);
    const buttons = section.querySelectorAll(
        "[data-config-choice-group=\"sensitivity\"] .sens-level-btn",
    );
    const currentInput = section.querySelector(
        "[data-config-field=\"sensitivity\"]",
    );
    buttons.forEach((button, index) => {
        const visible = index + 1 <= totalLevels;
        button.classList.toggle("d-none", !visible);
        button.disabled = !visible;
    });

    // Com a escala por escolher o `totalLevels` é `NaN`, e nenhuma comparação é verdadeira:
    // os botões ficam todos escondidos e o valor fica como está.
    if (!(parseInt(currentInput?.value, 10) > totalLevels)) {
        return;
    }

    // O maior que ainda existe, e não o primeiro à vista: o nível 1 é a sensibilidade
    // **máxima**, e quem estava no 7 ou no 8 queria o oposto disso.
    const lastEnabled = Array.from(buttons).findLast(
        (button) => !button.classList.contains("d-none") && !button.disabled,
    );
    if (!lastEnabled) return;

    currentInput.value = String(
        parseInt(lastEnabled.dataset.configValue || "1", 10) || 1,
    );
    buttons.forEach((button) => {
        const selected = button.dataset.configValue === currentInput.value;
        button.classList.toggle("active", selected);
        button.setAttribute("aria-pressed", selected ? "true" : "false");
    });
}

function timeRangesInput(entry, desired) {
    const limit = Math.max(1, parseInt(String(entry.limit ?? 3), 10) || 3);
    const ranges = Array.isArray(desired.ranges) ? desired.ranges : [];
    const values = Array.from(
        { length: limit },
        (_, index) => ranges[index] ?? "",
    );
    return `
        <div class="vstack gap-2">
            <div class="small text-secondary">Formato HH:MM-HH:MM. Envie pelo menos um intervalo.</div>
            ${values
                .map(
                    (value, index) => `
                <div>
                    <label class="form-label-sm">Intervalo ${index + 1}</label>
                    <input class="form-control" type="text" data-config-field="ranges" value="${esc(String(value))}" placeholder="08:10-09:30">
                </div>
            `,
                )
                .join("")}
        </div>`;
}

function timeRangeInput(desired) {
    return field(
        "Intervalo",
        `<input class="form-control" type="text" data-config-field="range" value="${esc(String(desired.range ?? "21:10-07:30"))}" placeholder="21:10-07:30">`,
    );
}

/**
 * Os descritores dos campos do 4P Touch.
 *
 * Cada tipo de campo declara aqui as suas quatro faces juntas -- desenhar, ler de volta, o
 * valor inicial e a legenda. Eram quatro mapas separados indexados pela mesma chave, e nada
 * garantia que ficassem alinhados: uma entrada em falta não dava erro, dava um campo genérico.
 */
export const INPUTS = {
    makeCall: {
        render: makeCallInput,
        read: (section) => ({ phone: readPhone(section, "phone") }),
    },
    voiceMonitor: {
        render: voiceMonitorInput,
        read: (section) => ({ phone: readPhone(section, "phone") }),
    },
    soundProfile: {
        render: (_entry, desired) => soundProfileInput(desired),
        read: (section) => ({ mode: readNumber(section, "mode") }),
        defaults: () => ({ mode: 1 }),
        help: () => "4 modos",
    },
    intervalHoursToggle: {
        render: (_entry, desired) => intervalHoursToggleInput(desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            intervalHours: readNumber(section, "intervalHours"),
        }),
        defaults: () => ({ enabled: true, intervalHours: 2 }),
    },
    languageTimezone: {
        render: (_entry, desired) => languageTimezoneInput(desired),
        read: (section) => {
            const value = readText(section, "preset");
            const [language, timeZone] = value.split("|", 2);
            return {
                language: parseInt(language, 10),
                timeZone: String(timeZone || "0"),
            };
        },
        defaults: () => ({ preset: "0|0" }),
    },
    dualToggle: {
        render: (_entry, desired) => dualToggleInput(desired),
        read: (section) => ({
            enabled: readCheckbox(section, "enabled"),
            callCenterOnFall: readCheckbox(section, "callCenterOnFall"),
        }),
        defaults: () => ({ enabled: true, callCenterOnFall: false }),
    },
    fallSensitivityLevels: {
        render: (_entry, desired) => fallSensitivityLevelsInput(desired),
        read: (section) => {
            const levels = readNumber(section, "levels");
            if (![6, 8].includes(levels)) {
                throw new Error("Selecione a escala de sensibilidade suportada pelo firmware (6 ou 8 níveis).");
            }
            return {
                sensitivity: readNumber(section, "sensitivity"),
                levels,
            };
        },
        defaults: () => ({ sensitivity: 5, levels: 8 }),
    },
    timeRanges: {
        render: timeRangesInput,
        read: (section) => ({ ranges: readTextArray(section, "ranges") }),
        defaults: () => ({ ranges: ["08:10-09:30"] }),
    },
    timeRange: {
        render: (_entry, desired) => timeRangeInput(desired),
        read: (section) => ({ range: readText(section, "range") }),
        // As horas voltam a juntar-se no formato que o construtor valida.,
        defaults: () => ({ range: "21:10-07:30" }),
    },
    takePills: {
        render: (_entry, desired, meta) => takePillsInput(desired, meta),
        read: (section) => readTakePills(section),
        defaults: () => ({
            reminderSettings: [],
            number: 0,
            reminderText: "",
            voiceData: "",
            voiceMimeType: "audio/webm",
        }),
    },
};
