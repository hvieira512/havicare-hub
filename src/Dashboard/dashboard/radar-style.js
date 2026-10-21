/**
 * Como se pinta o que um radar vê: a postura de uma pessoa e o tipo de área da divisão.
 * Apresentação de um fornecedor só, e por isso fora do `domain.js`.
 */

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
    sitting: { icon: "fa-chair", glyph: "", tone: "warning" },
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

    return { ...style, color: `var(--bs-${style.tone}-text-emphasis)` };
}

/** A cor de cada tipo de área declarada no aparelho, em tokens do tema. */
const AREA_TYPE_STYLE = {
    1: { label: "Personalizada", color: "var(--bs-secondary-color)" },
    2: { label: "Cama", color: "var(--brand)" },
    3: { label: "Interferência", color: "var(--bs-secondary)" },
    4: { label: "Porta", color: "var(--bs-warning)" },
    5: { label: "Cama de monitorização", color: "var(--bs-info)" },
    6: { label: "Região de alarme", color: "var(--bs-danger)" },
    7: { label: "Mobília", color: "var(--bs-tertiary-color)" },
};

/** Um tipo que o fabricante acrescente fica cinzento e com o número à vista, em vez de sumir. */
export function areaTypeStyle(type) {
    return AREA_TYPE_STYLE[Number(type)] || { label: `Tipo ${type}`, color: "var(--bs-secondary-color)" };
}

/**
 * Uma cor CSS resolvida para a tela, que não lê variáveis: o tema do momento, com a opacidade
 * multiplicada pela que o token já traga.
 */
export function canvasColor(cssColor, alpha = 1) {
    const probe = document.createElement("span");
    probe.style.color = cssColor;
    document.body.append(probe);
    const [r, g, b, a = 1] = getComputedStyle(probe).color.match(/[\d.]+/g).map(Number);
    probe.remove();

    return `rgba(${r}, ${g}, ${b}, ${a * alpha})`;
}
