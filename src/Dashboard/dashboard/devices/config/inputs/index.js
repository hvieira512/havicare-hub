import { INPUTS as capability } from "./capability.js";
import { INPUTS as fourPTouch } from "./four-p-touch.js";
import { INPUTS as generic } from "./generic.js";
import { INPUTS as pillDispenser } from "./pill-dispenser.js";
import { INPUTS as radar } from "./radar.js";
import { INPUTS as scale } from "./scale.js";
import { INPUTS as vivistar } from "./vivistar.js";
import { INPUTS as wonlex } from "./wonlex.js";

/** Que tipo cada protocolo declara diz-o `src/Command/Configuration/Definition/`. */
export const CONFIG_INPUTS = {
    ...generic,
    ...capability,
    ...fourPTouch,
    ...pillDispenser,
    ...radar,
    ...scale,
    ...vivistar,
    ...wonlex,
};
