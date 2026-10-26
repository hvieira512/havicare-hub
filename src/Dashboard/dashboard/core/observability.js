/** Dá rasto na consola aos erros por apanhar e às promessas rejeitadas. */
export function installErrorReporting() {
    window.addEventListener("error", (event) => {
        report("uncaught", event.error ?? event.message);
    });
    window.addEventListener("unhandledrejection", (event) => {
        report("unhandledrejection", event.reason);
    });
}

// O único `console` do frontend: é a rede do handler global, e não logging de rotina.
function report(kind, detail) {
    console.error(`[hub] ${kind}`, detail);
}
