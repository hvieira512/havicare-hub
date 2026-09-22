import { esc } from "../../../format.js";
import { field } from "../../../components/form-field.js";
import { boolValue } from "../normalizers.js";
import { numberField } from "./shared.js";
import {
    readCheckbox,
    readNumber,
} from "../readers.js";

/**
 * Os campos que só o Vivistar declara: a sensibilidade de queda e o modo de funcionamento,
 * que é o que decide o que o relógio mede e de quanto em quanto tempo.
 */

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

    return `
        <div>
            <label class="form-label-sm">Sensibilidade</label>
            <input type="hidden" data-config-field="sensitivity" value="${esc(String(current))}">
            <div class="btn-group w-100" role="group" aria-label="Sensibilidade de queda" data-config-choice-group="sensitivity">
                ${options
                    .map(
                        (option) => `
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
                    )
                    .join("")}
            </div>
        </div>`;
}

/**
 * A sensibilidade dos alertas de um medidor de fraldas: dois inteiros e três atalhos. Os
 * números estão sempre à vista e os presets são botões que os preenchem, sem um quarto botão
 * "Personalizado" -- nenhum preset activo já diz que os valores não são de nenhum deles.
 *
 * Os presets e as gamas vêm no `_meta` da capacidade, servidos pelo hub, para não haver aqui
 * uma segunda cópia destas fronteiras.
 */

function workingModeInput(desired) {
    const mode = parseInt(String(desired.mode ?? 1), 10) || 1;
    const intervalSeconds = desired.intervalSeconds ?? 60;
    const gpsEnabled = boolValue(desired.gpsEnabled, true);
    const options = [
        {
            value: 1,
            title: "Normal",
            description: "Envia localização a cada 15 minutos com Wi-Fi e LBS.",
            icon: "fa-clock",
            className: "btn-outline-primary",
        },
        {
            value: 2,
            title: "Poupança",
            description: "Envia localização a cada 60 minutos com Wi-Fi e LBS.",
            icon: "fa-battery-half",
            className: "btn-outline-success",
        },
        {
            value: 3,
            title: "Emergência",
            description:
                "Envia localização a cada 1 minuto com GPS, Wi-Fi e LBS.",
            icon: "fa-bolt",
            className: "btn-outline-danger",
        },
        {
            value: 8,
            title: "Personalizado",
            description:
                "Permite definir intervalo em segundos e ligar ou desligar GPS.",
            icon: "fa-sliders",
            className: "btn-outline-dark",
        },
    ];

    return `
        <div class="vstack gap-3">
            <div>
                <label class="form-label-sm">Modo</label>
                <div class="row g-2">
                    ${options
                        .map(
                            (option) => `
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
                                    <span class="d-block small">${option.description}</span>
                                </span>
                            </label>
                        </div>
                    `,
                        )
                        .join("")}
                </div>
            </div>
            <div class="${mode === 8 ? "" : "d-none"}" data-working-mode-extra>
                <div class="row g-3">
                    ${field(
                        "Intervalo de envio (segundos)",
                        numberField("intervalSeconds", intervalSeconds, { min: 30 }),
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

/**
 * Os descritores dos campos do Vivistar.
 *
 * Cada tipo de campo declara aqui as suas quatro faces juntas -- desenhar, ler de volta, o
 * valor inicial e a legenda. Eram quatro mapas separados indexados pela mesma chave, e nada
 * garantia que ficassem alinhados: uma entrada em falta não dava erro, dava um campo genérico.
 */
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
