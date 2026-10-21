/**
 * Os diálogos da dashboard: o aviso de canto, a confirmação e o erro da API. Usa-se sempre o
 * `titleText:` do SweetAlert, e não o `title:`, que é HTML.
 */

import { errorText } from "./api/errors.js";
import { markApiFields } from "./validation.js";

/** O "danger" é o nome do bootstrap para o que o SweetAlert chama "error". */
export function toast(type, title, text = "") {
    void Swal.fire({
        toast: true,
        position: "top-end",
        icon: type === "danger" ? "error" : type,
        titleText: title,
        text,
        showConfirmButton: false,
        showCloseButton: true,
        timer: 1800,
        timerProgressBar: true,
    });
}

/**
 * Devolve a promessa do SweetAlert: quem chama espera pelo `isConfirmed`. O botão diz o verbo
 * da acção, porque desligar um relógio não é apagá-lo.
 */
export function confirmDestructive(title, text = "", confirmText = "Apagar") {
    return Swal.fire({
        icon: "warning",
        titleText: title,
        text,
        showCancelButton: true,
        confirmButtonText: confirmText,
        cancelButtonText: "Cancelar",
        confirmButtonColor: "#dc3545",
        reverseButtons: true,
    });
}

/**
 * Pede uma password nova, fora da grelha porque não é valor que se mostre numa célula. O
 * `inputValidator` recusa o vazio, que seria ambíguo.
 */
export function promptPassword(title, text = "") {
    return Swal.fire({
        titleText: title,
        text,
        input: "password",
        inputAttributes: { autocomplete: "new-password" },
        inputValidator: (value) => (value ? undefined : "A palavra-passe é obrigatória"),
        showCancelButton: true,
        confirmButtonText: "Guardar",
        cancelButtonText: "Cancelar",
        reverseButtons: true,
    });
}

/** O texto de um erro da API, em português, pelo `code`. */
export const apiError = errorText;

/** Um erro da API num formulário: marca os campos que o `fields` aponta, ou avisa no canto. */
export function reportApiError(result, fieldFor) {
    if (!markApiFields(result, fieldFor)) toast("error", apiError(result));
}
