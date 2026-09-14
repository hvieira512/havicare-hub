import { INPUTS as capability } from "./capability.js";
import { INPUTS as fourPTouch } from "./four-p-touch.js";
import { INPUTS as generic } from "./generic.js";
import { INPUTS as vivistar } from "./vivistar.js";
import { INPUTS as wonlex } from "./wonlex.js";

/**
 * Todos os tipos de campo de configuração, por chave.
 *
 * Cada entrada traz as suas quatro faces juntas -- `render`, `read`, `defaults` e `help`.
 * Antes eram quatro mapas paralelos no `config/index.js`, indexados pelo mesmo espaço de
 * chaves e mantidos alinhados à mão: acrescentar um tipo obrigava a tocar em quatro sítios, e
 * esquecer um não dava erro nenhum -- dava um campo genérico ou um payload vazio.
 *
 * Os grupos não se sobrepõem: a partição sai das definições em
 * `src/Command/Configuration/Definition/`, que dizem que tipo de campo cada protocolo declara.
 */
export const CONFIG_INPUTS = {
    ...generic,
    ...capability,
    ...fourPTouch,
    ...vivistar,
    ...wonlex,
};
