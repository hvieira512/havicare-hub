/**
 * O que a dashboard diz ao utilizador por diálogo: o aviso de canto, a confirmação de
 * apagar, e a mensagem de erro que vem da API.
 *
 * O `Swal` é global, carregado pelo `index.php`. O `title:` do SweetAlert é HTML e o
 * `titleText:` não é: como aqui entram o IMEI, o nome de um modelo e a mensagem de erro do
 * servidor, é sempre o segundo.
 */

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
 * Devolve a promessa do SweetAlert: quem chama tem de esperar pelo `isConfirmed`.
 *
 * O botão diz o verbo da acção -- desligar um relógio não é apagá-lo, e um botão vermelho a
 * dizer «Apagar» sobre uma caixa que pergunta outra coisa faz hesitar quem lê.
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
 * Pede uma password nova.
 *
 * Uma password não é valor que se mostre numa célula, e por isso muda-se aqui e não por
 * edição na grelha. O `inputValidator` tranca o vazio antes de fechar: uma caixa vazia
 * queria dizer "põe esta" e "não lhe toques" ao mesmo tempo.
 */
export function promptPassword(title, text = "") {
    return Swal.fire({
        titleText: title,
        text,
        input: "password",
        inputAttributes: { autocomplete: "new-password" },
        inputValidator: (value) => (value ? undefined : "A password é obrigatória"),
        showCancelButton: true,
        confirmButtonText: "Guardar",
        cancelButtonText: "Cancelar",
        reverseButtons: true,
    });
}

/** A mensagem de um erro da API; o código serve de texto quando não há mensagem. */
export function apiError(result) {
    return (
        result?.error?.message ||
        result?.error?.code ||
        "Não foi possível concluir a operação."
    );
}
