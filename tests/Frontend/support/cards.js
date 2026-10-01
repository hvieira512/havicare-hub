import { uplinkCardContent } from "../../../src/Dashboard/dashboard/components/cards/telemetry.js";

/**
 * O conteúdo de um cartão, com os campos de marcação em texto.
 *
 * O `html` devolve um fragmento -- é isso que faz com que texto entregue a uma fronteira saia
 * escapado -- e o `assert.match` só aceita texto. Aqui num sítio só, em vez de um `String()`
 * por assertiva.
 */
export function cardContent(type, data, meta) {
    const content = uplinkCardContent(type, data, meta);
    if (!content) return content;

    return {
        ...content,
        value: String(content.value ?? ""),
        details: String(content.details ?? ""),
        detailsTitle: String(content.detailsTitle ?? ""),
        body: String(content.body ?? ""),
    };
}
