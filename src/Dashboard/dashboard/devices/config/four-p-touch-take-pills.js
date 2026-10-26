import { html } from "../../core/html.js";
import { addAlarmButton, alarmClockRow, readAlarmRows } from "./alarm-fields.js";
import { nextUid, switchField } from "./inputs/shared.js";
import { boolValue } from "./normalizers.js";
import { readCheckbox, readText } from "./readers.js";

/** Os lembretes são linhas de alarme, com um texto e uma gravação comuns a todos. */
export function takePillsInput(entry, desired, meta = {}) {
    const reminderText = String(desired.reminderText || "");
    const voiceData = String(desired.voiceData || "");
    const voiceMimeType = String(desired.voiceMimeType || "");
    const hasVoiceData = voiceData.trim() !== "";
    const voiceEnabled = boolValue(desired.voiceEnabled, hasVoiceData);
    const limit = Math.max(1, Number(meta.limit ?? entry.limit) || 1);
    const reminders = (Array.isArray(desired.plans) ? desired.plans : [])
        .map((plan) => plan?.times?.[0])
        .filter(Boolean)
        .slice(0, limit);
    const shown = reminders.length > 0 ? reminders : [{}];
    const options = { group: nextUid("take-pills-group"), emptyTitle: reminderText || "Lembrete por preencher" };
    const previewSrc = voiceData === "" || voiceData.startsWith("data:") ? voiceData : `data:${voiceMimeType};base64,${voiceData}`;

    return html`<div class="vstack gap-3">
        <div class="vstack gap-2" data-repeat-list="alarm_clock" data-repeat-limit="${limit}">${shown.map((item) => alarmClockRow(item, options))}</div>
        ${addAlarmButton("alarm_clock", "Acrescentar lembrete", shown.length, limit)}
        <div class="border rounded-3 p-3 vstack gap-3">
            <div><label class="form-label-sm">Texto do lembrete</label><input class="form-control" type="text" data-config-field="reminderText" value="${reminderText}"><div class="form-text">O mesmo texto em todos os lembretes.</div></div>
            <div class="vstack gap-2">
                <div class="form-label-sm mb-0">Voz dos lembretes <span class="text-secondary">· uma gravação para todos</span></div>
                ${switchField("voiceEnabled", voiceEnabled, { on: "Áudio ligado", off: "Áudio desligado" })}
                <fieldset class="vstack gap-2" data-takepills-audio-controls ${voiceEnabled ? "" : "disabled"}>
                    <input type="hidden" data-config-field="voiceData" value="${voiceData}">
                    <input type="hidden" data-config-field="voiceMimeType" value="${voiceMimeType}">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm" data-action="takePillsRecord"><i class="fa-solid fa-microphone me-2"></i>${hasVoiceData ? "Regravar" : "Gravar"}</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" data-action="takePillsStop"><i class="fa-solid fa-stop me-2"></i>Parar</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-action="takePillsClear"><i class="fa-solid fa-trash-can me-2"></i>Limpar</button>
                        <label class="btn btn-outline-secondary btn-sm mb-0"><i class="fa-solid fa-file-audio me-2"></i>Carregar<input type="file" class="d-none" accept="audio/*" data-action="takePillsFile"></label>
                        <span class="small text-secondary" data-takepills-status>${voiceEnabled ? (hasVoiceData ? "Áudio carregado" : "Sem áudio") : "Áudio desligado"}</span>
                    </div>
                    <audio class="w-100" controls preload="none" data-takepills-preview ${hasVoiceData ? `src="${previewSrc}"` : ""}></audio>
                </fieldset>
            </div>
        </div>
    </div>`;
}

export function readTakePills(section) {
    const payload = {
        plans: readAlarmRows(section).map((item) => ({ times: [item] })),
        reminderText: readText(section, "reminderText"),
    };
    const voiceData = readText(section, "voiceData");

    if (!readCheckbox(section, "voiceEnabled")) {
        payload.voiceData = "";
    } else if (voiceData !== "") {
        payload.voiceData = voiceData;
        const voiceMimeType = readText(section, "voiceMimeType");
        if (voiceMimeType !== "") payload.voiceMimeType = voiceMimeType;
    }

    return payload;
}
