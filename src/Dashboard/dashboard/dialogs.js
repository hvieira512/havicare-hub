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

/** Devolve a promessa do SweetAlert: quem chama tem de esperar pelo `isConfirmed`. */
export function confirmDestructive(title, text = "") {
    return Swal.fire({
        icon: "warning",
        titleText: title,
        text,
        showCancelButton: true,
        confirmButtonText: "Apagar",
        cancelButtonText: "Cancelar",
        confirmButtonColor: "#dc3545",
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
