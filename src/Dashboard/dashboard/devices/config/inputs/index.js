import { INPUTS as capability } from "./capability.js";
import { INPUTS as fourPTouch } from "./four-p-touch.js";
import { INPUTS as generic } from "./generic.js";
import { INPUTS as pillDispenser } from "./pill-dispenser.js";
import { INPUTS as vivistar } from "./vivistar.js";
import { INPUTS as wonlex } from "./wonlex.js";

/**
 * Todos os tipos de campo, cada um com `render`, `read`, `defaults` e `help` juntos. Que tipo
 * cada protocolo declara diz-o `src/Command/Configuration/Definition/`.
 */
export const CONFIG_INPUTS = {
    ...generic,
    ...capability,
    ...fourPTouch,
    ...pillDispenser,
    ...vivistar,
    ...wonlex,
};
