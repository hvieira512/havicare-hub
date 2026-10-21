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
    // O foco vai para o primeiro problema e fica lá.
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

/** Mexer num campo marcado limpa-o. */
export function bindInvalidClearing(root) {
    const clear = (event) => {
        const marked = event.target.closest?.(".is-invalid");
        // Clicar no próprio campo não o corrige; num grupo de botões, escolher lá dentro corrige.
        if (marked && (event.type !== "click" || marked !== event.target)) {
            clearInvalid(marked);
        }
    };
    for (const eventName of ["input", "change", "click"]) {
        root.addEventListener(eventName, clear);
    }
}

/**
 * Marca os campos do `error.fields` de uma resposta da API; `fieldFor` dá o controlo de cada um.
 * Devolve se marcou algum.
 */
export function markApiFields(result, fieldFor) {
    let marked = false;
    for (const name of Object.keys(result?.error?.fields ?? {})) {
        const field = fieldFor(name);
        if (!field) continue;
        markInvalid(field, String(field.value ?? "").trim() === "" ? "Campo obrigatório" : "Valor inválido");
        marked = true;
    }
    return marked;
}
