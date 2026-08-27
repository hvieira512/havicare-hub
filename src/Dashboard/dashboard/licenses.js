import {getLicenses as apiGetLicenses} from "./api/index.js";
import {state} from "./state.js";

/**
 * As licenças, uma vez por sessão. Vive na raiz e não numa funcionalidade porque são seis os
 * ecrãs que as pedem: a árvore do filtro da listagem, o assistente de criar, o modal de um
 * dispositivo, as contagens do menu de definições, as empresas e os utilizadores da API.
 *
 * Quem cria, muda ou apaga uma licença chama o `invalidateLicenses`.
 */
let inFlight = null;

/** Devolve `null` quando o pedido falha, para quem precisa distinguir isso de "não há". */
export async function ensureLicensesLoaded() {
    if (state.settingsModal.licenses.length > 0) {
        return state.settingsModal.licenses;
    }

    // Uma promessa partilhada: duas colunas a pedir ao mesmo tempo pediam duas vezes o mesmo.
    inFlight ??= apiGetLicenses({limit: 1000})
        .then((response) => {
            if (response?.error) return null;
            state.settingsModal.licenses = response.data || [];
            return state.settingsModal.licenses;
        })
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

export function invalidateLicenses() {
    state.settingsModal.licenses = [];
    inFlight = null;
}
