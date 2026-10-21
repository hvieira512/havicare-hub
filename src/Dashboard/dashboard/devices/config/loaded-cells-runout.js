/** Com o plano e a posição do prato, o «Carregado até ao compartimento» vira a data em que a medicação acaba. */

import { runoutAt, runoutLabel } from "../medication-runout.js";
import { state } from "../../state.js";

/** O modal só tem a configuração: a telemetria vem do selecionado, e só quando é o mesmo aparelho. */
function modalDetail() {
    const detail = state.selectedDetail;
    return detail?.device?.imei === state.deviceModal.imei ? detail : null;
}

/** A posição do carrossel na leitura mais recente; a lista não vem garantidamente ordenada. */
function trayPosition(detail) {
    let position = null;
    let latest = "";

    for (const entry of detail?.recent?.telemetry || []) {
        const current = entry?.data?.current;
        const at = String(entry?.occurredAt || "");
        if (entry?.type === "cells_remaining" && Number.isInteger(current) && at >= latest) {
            position = current;
            latest = at;
        }
    }

    return position;
}

export function loadedCellsRunoutText(loaded, now = new Date()) {
    // O campo vazio chega como `""`, que o `Number` leria como zero.
    const written = typeof loaded === "string" ? loaded.trim() : loaded;
    const value = written === "" || written === null || written === undefined
        ? Number.NaN
        : Number(written);
    const detail = modalDetail();
    const position = trayPosition(detail);
    if (!Number.isFinite(value) || position === null) {
        return "";
    }

    const configurations = detail.effectiveConfigurations;
    const plans = (configurations?.medication_reminders?.plans || [])
        .filter((plan) => plan?.enabled !== false);
    if (plans.length === 0) {
        return "";
    }

    if (value - position < 1) {
        return `Sem doses por dispensar a partir do compartimento ${position}.`;
    }

    const when = runoutAt({
        doses: value - position,
        plans,
        period: configurations?.medication_period,
        now,
    });

    return when === null
        ? ""
        : `Com ${plans.length} ${plans.length === 1 ? "dose" : "doses"} por dia, acaba ${runoutLabel(when, now)}.`;
}

export function syncLoadedCellsRunout(input) {
    const target = input
        ?.closest("[data-config-row], [data-config-section]")
        ?.querySelector("[data-loaded-runout]");
    if (target) {
        target.textContent = loadedCellsRunoutText(input.value);
    }
}
