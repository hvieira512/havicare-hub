/**
 * O que a dashboard diz ao utilizador por diálogo: o aviso de canto, a confirmação de
 * apagar, e a mensagem de erro que vem da API.
 *
 * O `Swal` é global, carregado pelo `index.php`. O `text:` do SweetAlert é escapado por ele,
 * e é por isso que nada aqui monta HTML -- uma mensagem de erro é dado vindo do servidor.
 */

/** O "danger" é o nome do bootstrap para o que o SweetAlert chama "error". */
export function toast(type, title, text = "") {
    void Swal.fire({
        toast: true,
        position: "top-end",
        icon: type === "danger" ? "error" : type,
        title,
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
        title,
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
        result?.error?.message
        || result?.error?.code
        || "Não foi possível concluir a operação."
    );
}
