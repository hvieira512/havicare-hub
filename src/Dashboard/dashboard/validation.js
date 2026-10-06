/**
 * O erro de validação escrito no campo, com o `.invalid-feedback` do Bootstrap; nasce ao lado
 * do campo na primeira vez que é preciso.
 */

function feedbackFor(field) {
    const next = field.nextElementSibling;
    if (next?.classList.contains("invalid-feedback")) return next;
    const feedback = document.createElement("div");
    feedback.className = "invalid-feedback";
    field.insertAdjacentElement("afterend", feedback);
    return feedback;
}

/** Marca o campo e escreve a mensagem por baixo dele; o primeiro marcado leva o foco. */
export function markInvalid(field, message) {
    if (!field) return;
    // O foco vai para o primeiro problema e fica lá: o segundo campo a ser marcado já
    // encontra o primeiro em foco e não lho tira.
    const first = !document.activeElement?.classList?.contains("is-invalid");
    field.classList.add("is-invalid");
    feedbackFor(field).textContent = message;
    if (first) field.focus();
}

/** Limpa a marca de um campo, ou a de todos os campos de um formulário. */
export function clearInvalid(root) {
    if (!root) return;
    root.classList?.remove("is-invalid");
    for (const field of root.querySelectorAll?.(".is-invalid") ?? []) {
        field.classList.remove("is-invalid");
    }
}

/** Mexer num campo marcado limpa-o: um erro que nunca sai é pior do que o aviso que substituiu. */
export function bindInvalidClearing(root) {
    const clear = (event) => {
        const marked = event.target.closest?.(".is-invalid");
        // Clicar no próprio campo não é corrigi-lo; num grupo de botões a marca está no
        // grupo, e escolher lá dentro é.
        if (marked && (event.type !== "click" || marked !== event.target)) {
            clearInvalid(marked);
        }
    };
    for (const eventName of ["input", "change", "click"]) {
        root.addEventListener(eventName, clear);
    }
}
