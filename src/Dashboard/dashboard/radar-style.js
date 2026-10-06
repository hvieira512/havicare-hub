/**
 * Como se pinta o que um radar vê: a postura de uma pessoa e o tipo de área da divisão.
 * Apresentação de um fornecedor só, e por isso fora do `domain.js`.
 */

/** Os tons em hexadecimal, para a tela da planta; são os mesmos das pastilhas. */
const TONE_HEX = {
    success: "#198754",
    info: "#0dcaf0",
    warning: "#ffc107",
    danger: "#dc3545",
    secondary: "#6c757d",
};

/**
 * A postura de uma pessoa vista por um radar. O `glyph` é o `icon` em ponto de código, para a
 * tela, escrito como escape porque é da área de uso privado.
 */
const POSTURE_STYLE = {
    standing: { icon: "fa-person", glyph: "", tone: "success" },
    walking: { icon: "fa-person-walking", glyph: "", tone: "success" },
    confirmed_sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "success" },
    lying_down: { icon: "fa-bed", glyph: "", tone: "info" },
    sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "info" },
    suspected_sitting_up_bed: { icon: "fa-bed", glyph: "", tone: "warning" },
    squatting: { icon: "fa-chair", glyph: "", tone: "warning" },
    suspected_sitting_on_ground: { icon: "fa-chair", glyph: "", tone: "warning" },
    suspected_fall: { icon: "fa-triangle-exclamation", glyph: "", tone: "warning" },
    confirmed_sitting_on_ground: { icon: "fa-chair", glyph: "", tone: "danger" },
    fall_confirmation: { icon: "fa-triangle-exclamation", glyph: "", tone: "danger" },
    initialization: { icon: "fa-question", glyph: "?", tone: "secondary" },
    unknown: { icon: "fa-question", glyph: "?", tone: "secondary" },
};

/** Uma postura que o firmware invente cai na desconhecida em vez de deixar o ecrã sem nada. */
export function postureStyle(posture) {
    const style = POSTURE_STYLE[String(posture)] || POSTURE_STYLE.unknown;

    return { ...style, color: TONE_HEX[style.tone] };
}

/** As cores do fabricante para cada tipo de área declarada no aparelho. */
const AREA_TYPE_STYLE = {
    1: { label: "Personalizada", color: "#a9a9a9" },
    2: { label: "Cama", color: "#20c997" },
    3: { label: "Interferência", color: "#808080" },
    4: { label: "Porta", color: "#ffa500" },
    5: { label: "Cama de monitorização", color: "#32cd32" },
    6: { label: "Região de alarme", color: "#ff4500" },
    7: { label: "Mobília", color: "#adb9ca" },
};

/** Um tipo que o fabricante acrescente fica cinzento e com o número à vista, em vez de sumir. */
export function areaTypeStyle(type) {
    return AREA_TYPE_STYLE[Number(type)] || { label: `Tipo ${type}`, color: "#a9a9a9" };
}
