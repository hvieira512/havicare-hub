/**
 * Carrega um script de terceiros à primeira vez que alguém precisa dele.
 *
 * As bibliotecas grandes -- o AG Grid, o Konva, o amCharts -- não vêm no `<head>`: são
 * megabytes que a maioria das sessões nunca abre. A promessa fica guardada por `src`, e por
 * isso duas chamadas partilham uma carga só. Um erro apaga-a, para quem voltar ao ecrã poder
 * tentar de novo em vez de ficar preso à primeira falha.
 */
const loading = new Map();

export function loadScript(src) {
    const pending = loading.get(src);
    if (pending) {
        return pending;
    }

    const promise = new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = src;
        script.addEventListener("load", () => resolve());
        script.addEventListener("error", () => {
            loading.delete(src);
            reject(new Error(`Não foi possível carregar ${src}`));
        });
        document.head.appendChild(script);
    });

    loading.set(src, promise);
    return promise;
}
