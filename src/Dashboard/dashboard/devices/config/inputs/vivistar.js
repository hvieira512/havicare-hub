import { html } from "../../../html.js";
import { field } from "../../../components/form-field.js";
import { boolValue } from "../normalizers.js";
import { numberField } from "./shared.js";
import {
    readCheckbox,
    readNumber,
} from "../readers.js";

/** Os campos que só o Vivistar declara: a sensibilidade de queda e o envio da localização. */

function fallSensitivityInput(desired) {
    const current = parseInt(String(desired.sensitivity ?? 2), 10) || 2;
    const options = [
        {
            value: 1,
            label: "Baixa",
            icon: "fa-feather-pointed",
            className: "btn-outline-success",
        },
        {
            value: 2,
            label: "Normal",
            icon: "fa-shield-heart",
            className: "btn-outline-warning",
        },
        {
            value: 3,
            label: "Alta",
            icon: "fa-triangle-exclamation",
            className: "btn-outline-danger",
        },
    ];

    return html`
        <div>
            <input type="hidden" data-config-field="sensitivity" value="${String(current)}">
            <div class="btn-group w-100" role="group" aria-label="Sensibilidade de queda" data-config-choice-group="sensitivity">
                ${options
                    .map(
                        (option) => html`
                    <button
                        type="button"
                        class="btn ${option.className} ${option.value === current ? "active" : ""}"
                        data-action="selectConfigChoice"
                        data-config-field="sensitivity"
                        data-config-value="${option.value}"
                        aria-pressed="${option.value === current ? "true" : "false"}">
                        <i class="fa-solid ${option.icon} me-2"></i>${option.label}
                    </button>
                `,
                    )}
            </div>
        </div>`;
}

function workingModeInput(desired) {
    const mode = parseInt(String(desired.mode ?? 1), 10) || 1;
    const intervalSeconds = desired.intervalSeconds ?? 60;
    const gpsEnabled = boolValue(desired.gpsEnabled, true);
    const options = [
        {
            value: 1,
            title: "A cada 15 min",
            icon: "fa-clock",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            title: "A cada 60 min",
            icon: "fa-battery-half",
            className: "btn-outline-success",
        },
        {
            value: 3,
            title: "A cada minuto, com GPS",
            icon: "fa-bolt",
            className: "btn-outline-danger",
        },
        {
            value: 8,
            title: "Personalizado",
            description: "Intervalo à escolha, a partir de 30 s, com ou sem GPS",
            icon: "fa-sliders",
            className: "btn-outline-dark",
        },
    ];

    return html`
        <div class="vstack gap-3">
            <div>
                <label class="form-label-sm">Modo</label>
                <div class="row g-2">
                    ${options
                        .map(
                            (option) => html`
                        <div class="col-12 col-md-6">
                            <input
                                class="btn-check"
                                type="radio"
                                name="workingMode"
                                id="workingMode${option.value}"
                                data-config-field="mode"
                                value="${option.value}"
                                ${option.value === mode ? "checked" : ""}>
                            <label class="btn ${option.className} w-100 h-100 text-start d-flex gap-3 align-items-start p-3" for="workingMode${option.value}">
                                <i class="fa-solid ${option.icon} mt-1"></i>
                                <span>
                                    <span class="d-block fw-semibold">${option.title}</span>
                                    ${option.description ? html`<span class="d-block small">${option.description}</span>` : ""}
                                </span>
                            </label>
                        </div>
                    `,
                        )}
                </div>
            </div>
            <div class="${mode === 8 ? "" : "d-none"}" data-working-mode-extra>
                <div class="row g-3">
                    ${field(
                        "Intervalo de envio",
                        numberField("intervalSeconds", intervalSeconds, { min: 30, unit: "s" }),
                        { cls: "col-md-6" },
                    )}
                    <div class="col-md-6">
                        <div class="form-check form-switch mt-4">
                            <input class="form-check-input" type="checkbox" role="switch" data-config-field="gpsEnabled" ${gpsEnabled ? "checked" : ""}>
                            <label class="form-check-label">GPS ativo</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
}

/** O intervalo e o GPS só se escolhem no modo 8, que é o personalizado. */
export function syncWorkingModeExtra(section, mode) {
    const extra = section.querySelector("[data-working-mode-extra]");
    if (!extra) return;

    extra.classList.toggle("d-none", String(mode) !== "8");
}

/** Cada tipo de campo declara as suas faces juntas: desenhar, ler de volta e valor inicial. */
export const INPUTS = {
    fallSensitivity: {
        render: (_entry, desired) => fallSensitivityInput(desired),
        read: (section) => ({ sensitivity: readNumber(section, "sensitivity") }),
        defaults: () => ({ sensitivity: 2 }),
    },
    workingMode: {
        render: (_entry, desired) => workingModeInput(desired),
        read: (section) => {
            const mode = readNumber(section, "mode");
            const payload = { mode };
            if (mode === 8) {
                payload.intervalSeconds = readNumber(section, "intervalSeconds");
                payload.gpsEnabled = readCheckbox(section, "gpsEnabled");
            }
            return payload;
        },
        defaults: () => ({ mode: 1 }),
    },
};
