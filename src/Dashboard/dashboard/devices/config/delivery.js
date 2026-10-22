import { esc } from "../../format.js";
import { stateBadge } from "../../components/state-badge.js";

/** O que aconteceu ao valor depois de o hub o guardar, que aplicá-lo pode levar dias. */

const CONFIGURATION_DELIVERY_META = {
    pending_delivery: {
        label: "Em envio",
        tone: "warning",
        message: "O valor está guardado no Hub e aguarda entrega ao dispositivo.",
    },
    awaiting_ack: {
        label: "A aguardar",
        tone: "warning",
        message: "O valor foi enviado e aguarda resposta do dispositivo.",
    },
    confirmation_unavailable: {
        label: "Não verificável",
        tone: "warning",
        message: "O dispositivo confirmou a receção, mas este comando não permite verificar o valor efetivo.",
    },
    confirmed: {
        label: "Aplicado",
        tone: "success",
        message: "",
    },
    waiting_device: {
        label: "A aguardar",
        tone: "warning",
        message: "O valor está guardado no Hub e aguarda confirmação do dispositivo.",
    },
    failed: {
        label: "Falhou",
        tone: "danger",
        message: "O último valor está guardado no Hub, mas não foi aplicado pelo dispositivo.",
    },
    never_reported: {
        label: "Não confirmado",
        tone: "warning",
        message: "O valor está guardado no Hub, mas nunca foi confirmado pelo dispositivo.",
    },
    diverged: {
        label: "Divergente",
        tone: "danger",
        message: "O dispositivo reportou um valor diferente do valor guardado no Hub.",
    },
    applied: {
        label: "Aplicado",
        tone: "success",
        message: "",
    },
};

const CONFIGURATION_FAILURE_LABELS = {
    retry_exhausted: "Foram esgotadas todas as tentativas de envio.",
    response_timeout: "O dispositivo não respondeu dentro do tempo esperado.",
    delivery_failed: "Não foi possível entregar o comando ao dispositivo.",
    dropped: "O comando foi descartado antes de ser entregue.",
    failed: "O dispositivo não confirmou a aplicação do valor.",
};

/** O estado de uma acção pelo do seu comando; o de uma definição calcula-o o hub. */
export function deliveryStatusFromCommand(commandStatus) {
    const status = String(commandStatus || "");
    if (["failed", "dropped"].includes(status)) return "failed";
    if (status === "acked") return "confirmed";
    if (status === "queued") return "pending_delivery";
    if (["waiting", "sent"].includes(status)) return "awaiting_ack";
    return "";
}

export function resolveConfigDelivery(entry, configurationSync) {
    const key = String(entry.capabilityKey || entry.key || "");
    if (key === "") {
        return null;
    }

    for (const section of Object.values(configurationSync?.entries || {})) {
        if (
            section &&
            typeof section === "object" &&
            section[key] &&
            typeof section[key] === "object"
        ) {
            return section[key];
        }
    }

    return null;
}

export function configurationDeliveryMeta(isStored, delivery) {
    if (!isStored) {
        return {
            label: "Padrão",
            tone: "secondary",
            message: "",
        };
    }

    const status = String(delivery?.status || "applied");
    return CONFIGURATION_DELIVERY_META[status] ||
        { label: "Desconhecido", tone: "secondary", message: "" };
}

export function renderConfigurationDeliveryNotice(meta, delivery) {
    if (!meta.message) {
        return "";
    }

    const error = String(delivery?.error || "");
    const errorMessage = CONFIGURATION_FAILURE_LABELS[error] || "";
    return `
        <div class="alert alert-${esc(meta.tone)} small py-2 px-3 mt-3 mb-0" role="status">
            <i class="fa-solid fa-circle-info me-2"></i>${esc(meta.message)}
            ${errorMessage ? `<span class="d-block mt-1">${esc(errorMessage)}</span>` : ""}
        </div>`;
}

/** Acerta no sítio a pastilha e o aviso de cada bloco, sem deitar fora o que se está a escrever. */
export function patchConfigurationDeliveryStates(root, configurationSync) {
    for (const section of root.querySelectorAll("[data-config-section], [data-config-row]")) {
        const key = section.dataset.capabilityKey || section.dataset.configKey || "";
        if (key === "") continue;

        const delivery = resolveConfigDelivery({ capabilityKey: key }, configurationSync);
        const meta = configurationDeliveryMeta(
            section.dataset.configStored === "1",
            delivery,
        );

        const badge = section.querySelector(".state-badge");
        if (badge) {
            badge.outerHTML = stateBadge(meta.label, meta.tone);
        }

        // A linha agrupada é compacta por desenho e não leva aviso.
        if (section.dataset.configRow !== undefined) {
            continue;
        }

        const notice = section.querySelector("[role=\"status\"]");
        const noticeHtml = renderConfigurationDeliveryNotice(meta, delivery);
        if (notice) {
            if (noticeHtml === "") {
                notice.remove();
            } else {
                notice.outerHTML = noticeHtml;
            }
        } else if (noticeHtml !== "") {
            // Onde o desenho o põe de origem.
            const anchor = section.querySelector("[data-config-form], [data-config-feedback-key]");
            if (anchor) {
                anchor.insertAdjacentHTML("beforebegin", noticeHtml);
            } else {
                section.insertAdjacentHTML("beforeend", noticeHtml);
            }
        }

        // Lido pelo painel para reacender o «Enviar» sem se mexer no valor.
        section.dataset.configDelivery = String(delivery?.status || "");
    }
}
