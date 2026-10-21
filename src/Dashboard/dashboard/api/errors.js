/** O texto de cada `code` da API, e o nome de cada campo do `fields`. O `message` é inglês e não se mostra. */

const GENERIC = "Não foi possível concluir a operação.";

const ERROR_TEXT = {
    invalid_request: "O pedido tem valores em falta ou inválidos.",
    invalid_config: "A configuração tem valores inválidos.",
    invalid_link: "Um gateway só se liga a uma fralda ou pulseira da mesma empresa e licença.",
    unsupported_feature: "O aparelho não suporta este pedido.",
    unknown_protocol: "Não se conhece o protocolo deste fornecedor.",
    device_already_associated: "O aparelho já está associado a uma licença.",
    invalid_association: "A empresa e a licença não correspondem a uma licença registada.",
    invalid_role: "Perfil inválido.",
    invalid_license: "Escolha a licença do cliente.",
    feature_not_requestable: "Este pedido não se pode fazer a este aparelho.",
    unsupported_capability: "Uma ou mais capacidades não são deste tipo de aparelho.",
    invalid_requestable_capability: "Só se pede o que o modelo suporta e o catálogo deixa pedir.",
    upload_failed: "O envio da imagem falhou.",
    image_too_large: "A imagem tem de ter 5 MB ou menos.",
    image_dimensions_too_large: "A imagem tem de ter 25 megapíxeis ou menos.",
    gd_missing: "O servidor não consegue comprimir imagens.",
    gd_jpeg_missing: "O servidor não consegue comprimir imagens.",
    invalid_image: "O ficheiro não é uma imagem válida.",
    image_save_failed: "Não foi possível guardar a imagem.",
    device_is_not_a_radar: "Este aparelho não é um radar.",
    license_has_no_radar_credentials: "A licença deste radar não tem credenciais da cloud do fabricante.",
    invalid_credentials: "Utilizador ou palavra-passe errados.",
    invalid_refresh_token: "A sessão expirou. Volte a entrar.",
    unauthorized: "A sessão expirou. Volte a entrar.",
    forbidden: "Não tem permissão para isto.",
    association_not_found: "O aparelho não está associado.",
    capability_not_found: "Capacidade não encontrada.",
    company_not_found: "Empresa não encontrada.",
    denylist_not_found: "Este aparelho não está bloqueado.",
    license_not_found: "Licença não encontrada.",
    model_not_found: "Modelo não encontrado.",
    not_found: "Não encontrado.",
    notification_not_found: "Notificação não encontrada.",
    protocol_not_found: "Protocolo não encontrado.",
    supplier_not_found: "Fornecedor não encontrado.",
    user_not_found: "Utilizador não encontrado.",
    device_exists: "Já existe um aparelho com este IMEI.",
    model_exists: "Já existe um modelo com este nome neste fornecedor.",
    user_exists: "Já existe um utilizador com este nome.",
    duplicate: "Já existe uma empresa com este nome.",
    incomplete_owner: "A empresa e a licença vão juntas: escolha as duas, ou nenhuma.",
    too_many_attempts: "Demasiadas tentativas. Espere um pouco e volte a tentar.",
    server_error: "Erro no servidor. Volte a tentar.",
    too_many_streams: "Há demasiadas ligações abertas. Feche um separador e volte a tentar.",
    login_failed: "Não foi possível autenticar na cloud do fabricante.",
    network_error: "Não foi possível falar com o servidor. Verifique a ligação.",
    empty_body: "O servidor deu uma resposta inválida.",
    invalid_json: "O servidor deu uma resposta inválida.",
};

const FIELD_LABELS = {
    imei: "IMEI",
    supplier: "Fornecedor",
    supplierId: "Fornecedor",
    model: "Modelo",
    deviceType: "Tipo",
    licenseId: "Licença",
    licenseRefId: "Licença",
    company: "Empresa",
    companyId: "Empresa",
    simNumber: "Número do SIM",
    deviceId: "ID do dispositivo",
    name: "Nome",
    username: "Utilizador",
    password: "Palavra-passe",
    role: "Perfil",
    internalModel: "Modelo interno",
    commercialName: "Nome comercial",
    identity: "Identidade",
    baseUrl: "Endereço da API",
    appId: "ID da aplicação",
    appSecret: "Segredo da aplicação",
    feature: "Pedido",
    configurations: "Configurações",
};

/** O texto de um código, ou `null` quando o código não é conhecido. */
export function errorCodeText(code) {
    return ERROR_TEXT[code] ?? null;
}

/** O que mostrar de um resultado com `error`: o texto do código, e os campos de um pedido inválido. */
export function errorText(result) {
    const error = result?.error ?? {};
    const fields = Object.keys(error.fields ?? {});
    if (error.code === "invalid_request" && fields.length > 0) {
        return `Campos em falta ou inválidos: ${fields.map((field) => FIELD_LABELS[field] ?? field).join(", ")}.`;
    }

    return errorCodeText(error.code) ?? GENERIC;
}
