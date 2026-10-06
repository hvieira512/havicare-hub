/**
 * Observabilidade mínima do cliente: dá rasto na consola aos erros por apanhar e às promessas
 * rejeitadas. O `report` é injetável para um dia ir a um endpoint do hub.
 */
export function installErrorReporting(report = reportToConsole) {
    window.addEventListener("error", (event) => {
        report("uncaught", event.error ?? event.message);
    });
    window.addEventListener("unhandledrejection", (event) => {
        report("unhandledrejection", event.reason);
    });
}

// O único uso de `console` no frontend, e de propósito: é a rede de segurança do handler
// global, não logging de rotina espalhado pelo código.
function reportToConsole(kind, detail) {
    console.error(`[hub] ${kind}`, detail);
}
