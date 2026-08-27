import {toast} from "../../dialogs.js";

const recordingState = new WeakMap();

export async function startTakePillsRecording(section) {
    const current = recordingState.get(section);
    if (current?.recorder?.state === "recording") return;
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === "undefined") {
        toast("error", "Este navegador não suporta gravação de áudio.");
        return;
    }

    try {
        const stream = await navigator.mediaDevices.getUserMedia({audio: true});
        const mimeType = pickMimeType();
        const recorder = new MediaRecorder(stream, mimeType ? {mimeType} : undefined);
        const next = {stream, recorder, chunks: [], mimeType: recorder.mimeType || mimeType || "audio/webm", cancelled: false};
        recordingState.set(section, next);
        updateRecorderUi(section, "A gravar...", true);
        recorder.addEventListener("dataavailable", event => {
            if (event.data && event.data.size > 0) next.chunks.push(event.data);
        });
        recorder.addEventListener("stop", async () => {
            try {
                if (!next.cancelled) {
                    await setAudioFromBlob(section, new Blob(next.chunks, {type: next.mimeType}), next.mimeType);
                }
            } finally {
                stopStream(next.stream);
                recordingState.delete(section);
                updateRecorderUi(section, next.cancelled ? "Sem áudio" : "Gravação concluída", false);
            }
        });
        recorder.start();
    } catch (error) {
        stopStream(current?.stream || null);
        recordingState.delete(section);
        toast("error", error instanceof Error ? error.message : "Não foi possível iniciar a gravação.");
        updateRecorderUi(section, "Sem áudio", false);
    }
}

export async function stopTakePillsRecording(section) {
    const current = recordingState.get(section);
    if (current?.recorder?.state === "recording") current.recorder.stop();
}

export function clearTakePillsRecording(section) {
    const current = recordingState.get(section);
    if (current?.recorder?.state === "recording") {
        current.cancelled = true;
        current.recorder.stop();
    }
    stopStream(current?.stream || null);
    recordingState.delete(section);
    const input = section.querySelector('[data-config-field="voiceData"]');
    const mimeInput = section.querySelector('[data-config-field="voiceMimeType"]');
    const fileInput = section.querySelector('[data-action="takePillsFile"]');
    const preview = section.querySelector("[data-takepills-preview]");
    if (input) input.value = "";
    if (mimeInput) mimeInput.value = "";
    if (fileInput) fileInput.value = "";
    if (preview) preview.removeAttribute("src");
    preview?.load?.();
    syncTakePillsVoiceVisibility(section);
    updateRecorderUi(section, "Sem áudio", false);
}

export async function loadTakePillsAudio(section, file) {
    if (!file) return;
    try {
        await setAudioFromBlob(section, file, file.type || "audio/*");
        const fileInput = section.querySelector('[data-action="takePillsFile"]');
        if (fileInput) fileInput.value = "";
        syncTakePillsVoiceVisibility(section);
        updateRecorderUi(section, "Áudio carregado", false);
    } catch (error) {
        toast("error", error instanceof Error ? error.message : "Não foi possível carregar o áudio.");
    }
}

export function syncTakePillsCustomVisibility(section) {
    section.querySelectorAll("[data-takepills-reminder-group]").forEach(group => {
        const index = group.dataset.takepillsReminderGroup;
        const frequency = parseInt(group.querySelector('[data-takepills-field="reminderFrequency"]')?.value ?? "1", 10) || 1;
        section.querySelector(`[data-takepills-custom-wrapper="${index}"]`)?.classList.toggle("d-none", frequency !== 3);
    });
}

export function syncTakePillsVoiceVisibility(section) {
    const enabled = section.querySelector('[data-config-field="voiceEnabled"]')?.checked || false;
    section.querySelector("[data-takepills-audio-controls]")?.toggleAttribute("disabled", !enabled);
    const status = section.querySelector("[data-takepills-status]");
    if (status) {
        const hasAudio = String(section.querySelector('[data-config-field="voiceData"]')?.value || "").trim() !== "";
        status.textContent = enabled ? (hasAudio ? "Áudio carregado" : "Sem áudio") : "Áudio desligado";
    }
}

async function setAudioFromBlob(section, blob, mimeType) {
    const base64 = await blobToBase64(blob);
    const normalizedMimeType = mimeType || blob.type || "audio/webm";
    const input = section.querySelector('[data-config-field="voiceData"]');
    const mimeInput = section.querySelector('[data-config-field="voiceMimeType"]');
    const preview = section.querySelector("[data-takepills-preview]");
    if (input) input.value = base64;
    if (mimeInput) mimeInput.value = normalizedMimeType;
    if (preview) {
        preview.src = `data:${normalizedMimeType};base64,${base64}`;
        preview.type = normalizedMimeType;
    }
}

function updateRecorderUi(section, status, recording) {
    const enabled = section.querySelector('[data-config-field="voiceEnabled"]')?.checked || false;
    section.querySelector('[data-action="takePillsRecord"]')?.classList.toggle("d-none", !!recording);
    section.querySelector('[data-action="takePillsStop"]')?.classList.toggle("d-none", !recording);
    section.querySelector("[data-takepills-audio-controls]")?.toggleAttribute("disabled", !enabled);
    const statusElement = section.querySelector("[data-takepills-status]");
    if (statusElement) statusElement.textContent = enabled ? status : "Áudio desligado";
}

function stopStream(stream) {
    if (!stream) return;
    for (const track of stream.getTracks()) track.stop();
}

function pickMimeType() {
    if (typeof MediaRecorder === "undefined") return "";
    return ["audio/webm;codecs=opus", "audio/webm", "audio/ogg;codecs=opus", "audio/ogg"]
        .find(mimeType => MediaRecorder.isTypeSupported(mimeType)) || "";
}

function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => {
            const result = String(reader.result || "");
            const commaIndex = result.indexOf(",");
            resolve(commaIndex >= 0 ? result.slice(commaIndex + 1) : result);
        };
        reader.onerror = () => reject(reader.error || new Error("Failed to read audio"));
        reader.readAsDataURL(blob);
    });
}
