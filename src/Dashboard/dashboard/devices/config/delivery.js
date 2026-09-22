import { esc } from "../../format.js";
import { stateBadge } from "../../components/state-badge.js";

/**
 * O estado de entrega de uma configuração: o que aconteceu ao valor depois de o hub o
 * guardar.
 *
 * Guardar no hub e aplicar no aparelho são dois momentos distintos, e podem estar separados
 * por dias -- um relógio desligado recebe em fila quando voltar. O estado de entrega é o que
 * conta essa segunda metade: em envio, à espera de resposta, aplicado, divergente, falhado.
 *
 * O vocabulário e a tradução de um comando para ele vivem juntos porque são a mesma coisa
 * vista de dois lados: o que o ecrã diz e o que a fila de comandos reporta. Separá-los deixava
 * o `acked` de um lado e o «Aplicado» do outro, e um estado novo passava a obrigar a acertar
 * dois ficheiros sem nada a prender que ficassem a par.
 */

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

/**
 * O estado de entrega correspondente ao estado de um comando.
 *
 * É a mesma tradução para configurações e para acções: ambas viajam pela mesma fila e o
 * operador não tem por que ler dois vocabulários para a mesma coisa.
 */
export function deliveryStatusFromCommand(commandStatus, confirmationMode = "") {
    const status = String(commandStatus || "");
    if (["failed", "dropped"].includes(status)) return "failed";
    if (status === "acked") {
        return String(confirmationMode) === "ack_only" ? "confirmation_unavailable" : "confirmed";
    }
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
        CONFIGURATION_DELIVERY_META.failed;
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

/**
 * Acerta no sítio a pastilha de estado e o aviso de entrega de cada bloco.
 *
 * Uma mudança de estado de entrega chega pelo stream a qualquer momento, e redesenhar a raiz
 * por causa dela deitava fora o número de telefone, o nome ou a hora que estivessem a meio de
 * ser escritos noutro bloco -- precisamente enquanto se espera pelo envio de um.
 */
export function patchConfigurationDeliveryStates(root, configurationSync) {
    for (const section of root.querySelectorAll("[data-config-section]")) {
        const key = section.dataset.capabilityKey || section.dataset.configKey || "";
        if (key === "") continue;

        const delivery = resolveConfigDelivery({ capabilityKey: key }, configurationSync);
        const meta = configurationDeliveryMeta(
            section.dataset.configStored === "1",
            delivery,
        );

        // Trocada inteira pela do componente, e não remendada classe a classe: eram duas
        // cópias da mesma marcação a ter de andar a par.
        const badge = section.querySelector(".state-badge");
        if (badge) {
            badge.outerHTML = stateBadge(meta.label, meta.tone);
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
            // Antes do formulário, ou -- num cartão de acção, que não tem campos nem
            // formulário -- antes da caixa de falha. É onde o desenho o põe de origem, e as
            // duas ordens têm de coincidir.
            const anchor = section.querySelector("[data-config-form], [data-config-feedback-key]");
            if (anchor) {
                anchor.insertAdjacentHTML("beforebegin", noticeHtml);
            } else {
                section.insertAdjacentHTML("beforeend", noticeHtml);
            }
        }

        // O estado de entrega decide se o «Enviar» pode voltar a acender: uma configuração
        // que falhe enquanto o ecrã está aberto tem de ficar reenviável sem se lhe mexer no
        // valor, tal como uma que já lá estivesse falhada ao desenhar. Quem reacende o botão
        // é o painel -- ele importa daqui, e importá-lo de volta fechava um ciclo.
        section.dataset.configDelivery = String(delivery?.status || "");
    }
}
