import { getLicenses as apiGetLicenses } from "./api/index.js";
import { state } from "./state.js";

/**
 * As licenças, uma vez por sessão, na raiz porque seis ecrãs as pedem. Quem cria, muda ou
 * apaga uma licença chama o `invalidateLicenses`.
 */
let inFlight = null;

/** Devolve `null` quando o pedido falha, para quem precisa distinguir isso de "não há". */
export async function ensureLicensesLoaded() {
    if (state.licenses.length > 0) {
        return state.licenses;
    }

    // Uma promessa partilhada, para dois pedidos ao mesmo tempo serem um só.
    inFlight ??= apiGetLicenses({ limit: 1000 })
        .then((response) => {
            if (response?.error) return null;
            state.licenses = response.data || [];
            return state.licenses;
        })
        .finally(() => {
            inFlight = null;
        });

    return inFlight;
}

export function invalidateLicenses() {
    state.licenses = [];
    inFlight = null;
}
