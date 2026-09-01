<?php

namespace Hub\Api\Http;

/**
 * Um erro da API: o código, a mensagem e o estado HTTP que lhe pertence.
 *
 * Cada erro nasce de um construtor com nome, para um engano ser um método que não existe. As
 * mensagens ficam aqui porque vão no fio, e os clientes e a especificação dependem delas
 * palavra por palavra.
 */
final class ApiError
{
    /** O estado de um código que nem sequer está declarado: não é dos nossos, é engano. */
    private const DEFAULT_STATUS = 400;

    /**
     * Cada código que a API sabe devolver, e o estado de cada um. Estão aqui todos, incluindo
     * os 400: é o único sítio onde se responde «que estado tem este código?».
     *
     * @var array<string, int>
     */
    private const STATUS_BY_CODE = [
        'invalid_request' => 400,
        'invalid_config' => 400,
        'invalid_link' => 400,
        'invalid_state' => 400,
        'unsupported_feature' => 400,
        'unknown_protocol' => 400,
        'device_already_associated' => 400,
        'invalid_association' => 400,
        'invalid_role' => 400,
        'invalid_license' => 400,
        'feature_not_requestable' => 400,
        'unsupported_capability' => 400,
        'invalid_requestable_capability' => 400,
        'upload_failed' => 400,
        'image_too_large' => 400,
        'gd_missing' => 400,
        'gd_jpeg_missing' => 400,
        'invalid_image' => 400,
        'image_save_failed' => 400,
        // 401 e não 400: o pedido está bem formado, o que falha é quem o faz.
        'invalid_credentials' => 401,
        'invalid_refresh_token' => 401,
        // Os dois que o `ApiKernel` devolve antes de haver rota. Sem estarem aqui, nenhuma
        // rota os podia declarar na especificação.
        'unauthorized' => 401,
        'forbidden' => 403,
        'association_not_found' => 404,
        'capability_not_found' => 404,
        'company_not_found' => 404,
        'discovery_not_found' => 404,
        'license_not_found' => 404,
        'model_not_found' => 404,
        'not_found' => 404,
        'notification_not_found' => 404,
        'protocol_not_found' => 404,
        'supplier_not_found' => 404,
        'user_not_found' => 404,
        'device_exists' => 409,
        'model_exists' => 409,
        'user_exists' => 409,
        // O nome de empresa repetido, que o `TenancyPaths` documenta como 409.
        'duplicate' => 409,
        'server_error' => 500,
    ];

    /**
     * @param array<string, list<string>>|null $fields o erro campo a campo, quando o há
     */
    private function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?array $fields = null,
    ) {
    }

    /** O estado de um erro que já viaja como array, para quem ainda não tem o objecto. */
    public static function statusForCode(string $code): int
    {
        return self::STATUS_BY_CODE[$code] ?? self::DEFAULT_STATUS;
    }

    /**
     * Sem chamador em produção de propósito: existe para o teste confrontar este mapa com os
     * construtores que o alimentam.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::STATUS_BY_CODE);
    }

    /**
     * Como o `statusForCode()`, mas recusa um código que não existe em vez de lhe dar 400. É
     * o que a especificação usa: um engano rebenta a montar o documento, não num cliente.
     */
    public static function declaredStatus(string $code): int
    {
        return self::STATUS_BY_CODE[$code]
            ?? throw new \InvalidArgumentException("Unknown API error code: {$code}");
    }

    /**
     * A forma que o `JsonResponder` serializa. O `fields` só aparece quando há detalhe por
     * campo, para as outras respostas não ganharem uma chave vazia.
     *
     * @return array{error: array{code: string, message: string, fields?: array<string, list<string>>}}
     */
    public function toArray(): array
    {
        $error = ['code' => $this->code, 'message' => $this->message];
        if ($this->fields !== null) {
            $error['fields'] = $this->fields;
        }

        return ['error' => $error];
    }

    /**
     * Um pedido recusado por um ou mais campos, cada um com as suas razões.
     *
     * @param array<string, list<string>> $fields
     */
    public static function invalidFields(array $fields): self
    {
        ksort($fields);

        return new self('invalid_request', 'The request contains invalid fields', $fields);
    }

    /**
     * Um erro com código próprio que também traz o detalhe por campo. Com um campo só a
     * falhar, o código e a mensagem são os de sempre e o `fields` vem por acréscimo.
     *
     * @param array<string, list<string>> $fields
     */
    public static function withFields(string $code, string $message, array $fields): self
    {
        ksort($fields);

        return new self($code, $message, $fields);
    }

    // Pedidos mal formados: a mensagem muda com o campo em falta, o código não.

    public static function invalidRequest(string $message): self
    {
        return new self('invalid_request', $message);
    }

    /** O corpo não é sequer JSON -- a rejeição mais comum da API, e sempre com este texto. */
    public static function invalidJson(): self
    {
        return new self('invalid_request', 'Invalid JSON');
    }

    public static function invalidConfig(string $message): self
    {
        return new self('invalid_config', $message);
    }

    public static function invalidLink(string $message): self
    {
        return new self('invalid_link', $message);
    }

    public static function unsupportedFeature(string $message): self
    {
        return new self('unsupported_feature', $message);
    }

    public static function unknownProtocol(string $message): self
    {
        return new self('unknown_protocol', $message);
    }

    // Não encontrado. O `not_found` serve dispositivos e comandos, e a mensagem diz qual.

    public static function deviceNotFound(): self
    {
        return new self('not_found', 'Device was not found');
    }

    public static function commandNotFound(): self
    {
        return new self('not_found', 'Command was not found');
    }

    public static function modelNotFound(): self
    {
        return new self('model_not_found', 'Model not found');
    }

    /** O modelo existe no catálogo, mas não para o fornecedor que o pedido indicou. */
    public static function modelNotFoundForSupplier(): self
    {
        return new self('model_not_found', 'Model does not exist for this supplier');
    }

    public static function companyNotFound(): self
    {
        return new self('company_not_found', 'Company not found');
    }

    public static function licenseNotFound(): self
    {
        return new self('license_not_found', 'License not found');
    }

    public static function capabilityNotFound(): self
    {
        return new self('capability_not_found', 'Capability not found');
    }

    public static function discoveryNotFound(): self
    {
        return new self('discovery_not_found', 'Discovery run not found');
    }

    public static function notificationNotFound(): self
    {
        return new self('notification_not_found', 'Notification not found');
    }

    public static function supplierNotFound(): self
    {
        return new self('supplier_not_found', 'Supplier does not exist');
    }

    public static function protocolNotFound(): self
    {
        return new self('protocol_not_found', 'Unsupported protocol');
    }

    public static function userNotFound(): self
    {
        return new self('user_not_found', 'API user not found');
    }

    public static function associationNotFound(): self
    {
        return new self('association_not_found', 'Device association was not found');
    }

    // Conflitos com o que já está registado.

    public static function deviceExists(): self
    {
        return new self('device_exists', 'Device with this IMEI already exists');
    }

    public static function modelExists(): self
    {
        return new self('model_exists', 'Another model with this supplier and model name already exists');
    }

    public static function userExists(): self
    {
        return new self('user_exists', 'Username already exists');
    }

    public static function duplicateCompany(): self
    {
        return new self('duplicate', 'Company with this name already exists');
    }

    // Regras de negócio recusadas.

    public static function forbidden(): self
    {
        return new self('forbidden', 'Forbidden');
    }

    /** Falta a credencial, ou não vale. Antes de qualquer rota, e por isso sem detalhe. */
    public static function unauthorized(): self
    {
        return new self('unauthorized', 'Unauthorized');
    }

    /** Não há rota para este caminho. Distinto do `deviceNotFound()`, que é sobre a coisa. */
    public static function routeNotFound(): self
    {
        return new self('not_found', 'Not found');
    }

    /** Quem a devolve junta-lhe o `requestId`, que liga a resposta à linha do registo. */
    public static function serverError(): self
    {
        return new self('server_error', 'Internal server error');
    }

    public static function deviceAlreadyAssociated(): self
    {
        return new self('device_already_associated', 'Device is already associated');
    }

    public static function invalidAssociation(): self
    {
        return new self('invalid_association', 'company and licenseId do not match a registered license');
    }

    public static function invalidCredentials(): self
    {
        return new self('invalid_credentials', 'Invalid credentials');
    }

    public static function invalidRefreshToken(): self
    {
        return new self('invalid_refresh_token', 'Invalid refresh token');
    }

    public static function invalidRole(): self
    {
        return new self('invalid_role', 'role must be hub_admin or license_client');
    }

    public static function invalidLicense(): self
    {
        return new self('invalid_license', 'A valid company license is required for license clients');
    }

    public static function discoveryMissingModelId(): self
    {
        return new self('invalid_state', 'Discovery run is missing the model id');
    }

    public static function featureNotRequestable(): self
    {
        return new self('feature_not_requestable', 'Feature cannot be requested for this device');
    }

    public static function unsupportedCapability(): self
    {
        return new self('unsupported_capability', 'One or more capabilities are not allowed for this device type');
    }

    public static function invalidRequestableCapability(): self
    {
        return new self(
            'invalid_requestable_capability',
            'Requestable telemetry must also be supported and requestable in the capability catalog'
        );
    }

    // Imagens de modelo: o upload em si, e o que o GD precisa para as comprimir.

    public static function uploadFailed(): self
    {
        return new self('upload_failed', 'Image upload failed');
    }

    public static function imageTooLarge(): self
    {
        return new self('image_too_large', 'Model image must be 5 MB or smaller');
    }

    public static function gdMissing(): self
    {
        return new self('gd_missing', 'PHP GD extension is required to compress model images');
    }

    public static function gdJpegMissing(): self
    {
        return new self('gd_jpeg_missing', 'PHP GD JPEG support is required to save compressed model images');
    }

    public static function invalidImage(): self
    {
        return new self('invalid_image', 'Model image must be a valid image file');
    }

    public static function imageSaveFailed(): self
    {
        return new self('image_save_failed', 'Could not save model image');
    }
}
