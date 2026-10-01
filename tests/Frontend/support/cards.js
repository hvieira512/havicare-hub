import { uplinkCardContent } from "../../../src/Dashboard/dashboard/components/cards/telemetry.js";

/** O conteúdo de um cartão com os campos de marcação em texto: o `assert.match` só aceita texto. */
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
