/**
 * Um `fetch` falso que não responde sozinho: é o teste que decide a ordem das respostas, e só
 * assim uma corrida se reproduz sem depender do relógio.
 */
export function installDeferredFetch() {
    const pending = [];
    globalThis.fetch = (url) => new Promise((resolve) => {
        pending.push({ url: String(url), resolve });
    });

    return {
        pending,
        /**
         * Responde ao pedido em fila cujo URL contenha `match`, e deixa correr o que se segue.
         * O exacto ganha ao que só contém: `/api/devices/x` é prefixo de `/api/devices/x/stream`.
         */
        async respond(match, body) {
            const exact = pending.findIndex((entry) => entry.url === match);
            const index = exact === -1
                ? pending.findIndex((entry) => entry.url.includes(match))
                : exact;
            if (index === -1) {
                throw new Error(`Nenhum pedido pendente para «${match}»`);
            }
            const [entry] = pending.splice(index, 1);
            entry.resolve({
                ok: true,
                status: 200,
                text: async () => JSON.stringify(body),
                headers: { get: () => "application/json" },
            });
            await flush();
        },
    };
}

/** Deixa correr as continuações já agendadas antes de o teste verificar o resultado. */
export const flush = () => new Promise((resolve) => setTimeout(resolve, 0));
