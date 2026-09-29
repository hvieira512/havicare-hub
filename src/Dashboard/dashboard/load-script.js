/**
 * Carrega um script de terceiros à primeira vez que alguém precisa dele: o Konva e o amCharts
 * são megabytes que a maioria das sessões nunca abre.
 *
 * A promessa fica guardada por `src`, e um erro apaga-a para a chamada seguinte tentar de novo.
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
