/**
 * Observabilidade mínima do cliente. Erros por apanhar e promessas rejeitadas não deixavam
 * rasto nenhum -- um catch deliberado a engolir, um render a rebentar sobre um payload
 * estranho -- e o operador ficava sem nada para ver. Isto dá-lhes um rasto.
 *
 * Por agora vai para a consola, com prefixo, para quem abre as ferramentas de programador. O
 * `report` é injetável para um dia POST a um endpoint do hub sem mexer nos chamadores.
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
