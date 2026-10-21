import { getLicenses as apiGetLicenses } from "./api/index.js";
import { state } from "./state.js";

/** As licenças, uma vez por sessão; quem as muda chama o `invalidateLicenses`. */
let inFlight = null;

/** Devolve `null` quando o pedido falha, para quem precisa distinguir isso de "não há". */
export async function ensureLicensesLoaded() {
    if (state.licenses.length > 0) {
        return state.licenses;
    }

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
