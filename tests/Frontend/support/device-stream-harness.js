/**
 * Um `fetch` conduzível para o stream de dispositivo: empurram-se frames para um corpo que fica
 * aberto, porque é o corte do corpo em frames que está sob teste.
 */

/** Um stream aberto, com uma torneira para lhe empurrar frames. */
export class FakeStream {
    constructor(url, options) {
        this.url = String(url);
        this.options = options || {};
        this.closed = false;
        this.encoder = new TextEncoder();
        this.body = new ReadableStream({
            start: (controller) => {
                this.controller = controller;
            },
            cancel: () => {
                this.closed = true;
            },
        });
    }

    /**
     * Escreve texto em cru, para se poder cortar um frame onde apetecer; num stream já fechado
     * não faz nada, porque largar o dispositivo antes de o servidor desligar não é erro.
     */
    write(text) {
        if (this.closed) {
            return;
        }
        this.controller.enqueue(this.encoder.encode(text));
    }

    /** Um frame SSE inteiro: o nome do evento e o corpo em JSON. */
    emit(type, data) {
        this.write(data === undefined
            ? `event: ${type}\n\n`
            : `event: ${type}\ndata: ${JSON.stringify(data)}\n\n`);
    }

    /** Como o servidor desliga: o corpo acaba e o cliente tem de religar. */
    end() {
        if (this.closed) {
            return;
        }
        this.closed = true;
        this.controller.close();
    }
}

/**
 * Instala o `fetch` falso e substitui o `window.setTimeout`, com que o módulo agenda as
 * religações.
 */
export function installStreamHarness() {
    const streams = [];
    const requests = [];
    let nextResponse = { ok: true, status: 200 };
    const scheduled = [];

    window.setTimeout = (callback, delay) => {
        scheduled.push({ callback, delay });
        return scheduled.length;
    };
    window.clearTimeout = (handle) => {
        const entry = scheduled[handle - 1];
        if (entry) entry.cancelled = true;
    };

    globalThis.fetch = async (url, options) => {
        requests.push({ url: String(url), options: options || {} });

        if (!nextResponse.ok) {
            return {
                ok: false,
                status: nextResponse.status,
                headers: { get: () => "application/json" },
                text: async () => JSON.stringify({ error: { code: "recusado" } }),
            };
        }

        const stream = new FakeStream(url, options);
        streams.push(stream);

        // O `fetch` a sério liga o `signal` ao corpo: um `abort()` cancela-o, e o stream largado
        // ao trocar de dispositivo não fica a servir.
        options?.signal?.addEventListener("abort", () => {
            if (!stream.closed) {
                stream.closed = true;
                stream.controller.error(new DOMException("Aborted", "AbortError"));
            }
        });

        return {
            ok: true,
            status: 200,
            headers: { get: () => "text/event-stream" },
            body: stream.body,
        };
    };

    /** Deixa correr as leituras do corpo, que são várias microtarefas por chunk. */
    const settle = async () => {
        for (let i = 0; i < 12; i++) {
            await new Promise((resolve) => {
                setTimeout(resolve, 0);
            });
        }
    };

    return {
        streams,
        requests,
        scheduled,
        settle,
        refuseWith(status) {
            nextResponse = { ok: false, status };
        },
        accept() {
            nextResponse = { ok: true, status: 200 };
        },
        reset() {
            streams.length = 0;
            requests.length = 0;
            scheduled.length = 0;
            nextResponse = { ok: true, status: 200 };
        },
        /** Corre o último temporizador por correr, como o relógio faria. */
        async runPendingTimer() {
            const pending = scheduled.filter((entry) => !entry.cancelled && !entry.done);
            const entry = pending[pending.length - 1];
            if (!entry) {
                return null;
            }
            entry.done = true;
            entry.callback();
            await settle();
            return entry;
        },
    };
}
