<?php

namespace Hub\Api\Http;

/**
 * Um erro da API: o código, a mensagem e o estado HTTP que lhe pertence.
 *
 * O estado era antes deduzido da forma do código -- um sufixo `_not_found`, um prefixo
 * `duplicate_` --, e por isso um código escrito à mão com um engano de uma letra respondia
 * 400 sem ninguém dar por ela. Aqui cada erro nasce de um construtor com nome: um engano
 * passa a ser um método que não existe, apanhado a compilar em vez de em produção.
 *
 * As mensagens ficam aqui e não nos serviços quando são sempre a mesma: é o texto que vai no
 * fio, e os clientes e a especificação OpenAPI dependem dele palavra por palavra.
 */
final class ApiError
{
    /** Um erro sem estado declarado é um pedido mal formado. */
    private const DEFAULT_STATUS = 400;

    /**
     * Só os códigos que fogem ao 400. Um código novo que responda 404 ou 409 tem de entrar
     * aqui -- é a lista que o `ErrorStatusMapper` consulta, e o silêncio dela é o 400.
     *
     * @var array<string, int>
     */
    private const STATUS_BY_CODE = [
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
        // O nome de empresa repetido respondia 400 porque a regra antiga procurava o prefixo
        // `duplicate_` e este código é `duplicate` seco, sem sufixo -- nunca casou. O 409 que
        // o `TenancyPaths` documenta para o criar e o actualizar da empresa nunca chegou a
        // sair. Aqui o estado passa a ser o que a especificação promete; o `code` na resposta
        // não muda, porque é por ele que um cliente distingue o caso.
        'duplicate' => 409,
    ];

    /**
     * Os códigos que respondem 400, que é o estado por omissão do mapa acima.
     *
     * Não é o mapa quem os serve -- o `statusForCode()` já lhes dá 400 sem os conhecer. Estão
     * aqui para o conjunto de códigos ser *enumerável*: a especificação OpenAPI declara cada
     * rota pelos códigos que ela devolve, e sem esta lista um código mal escrito caía no 400
     * por omissão e a rota passava a prometer um estado que nunca envia -- exactamente o
     * engano silencioso que o mapa acima veio acabar.
     *
     * @var list<string>
     */
    private const BAD_REQUEST_CODES = [
        'invalid_request',
        'invalid_config',
        'invalid_link',
        'invalid_state',
        'unsupported_feature',
        'unknown_protocol',
        'device_already_associated',
        'invalid_association',
        'invalid_credentials',
        'invalid_refresh_token',
        'invalid_role',
        'invalid_license',
        'feature_not_requestable',
        'unsupported_capability',
        'invalid_requestable_capability',
        'upload_failed',
        'image_too_large',
        'gd_missing',
        'gd_jpeg_missing',
        'invalid_image',
        'image_save_failed',
    ];

    private function __construct(
        public readonly string $code,
        public readonly string $message,
    ) {
    }

    public function status(): int
    {
        return self::statusForCode($this->code);
    }

    /** O estado de um erro que já viaja como array, para quem ainda não tem o objecto. */
    public static function statusForCode(string $code): int
    {
        return self::STATUS_BY_CODE[$code] ?? self::DEFAULT_STATUS;
    }

    /**
     * Todos os códigos que a API sabe devolver.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return [...array_keys(self::STATUS_BY_CODE), ...self::BAD_REQUEST_CODES];
    }

    /**
     * Como o `statusForCode()`, mas recusa um código que não existe em vez de lhe dar 400.
     *
     * É o que a especificação usa. Ali um código enganado não pode cair no estado por
     * omissão: ninguém o executa, e por isso o engano só apareceria num cliente gerado a
     * partir de um documento que promete o estado errado. Aqui rebenta a montar o documento,
     * que é a única altura em que alguém está a olhar.
     */
    public static function declaredStatus(string $code): int
    {
        if (!in_array($code, self::codes(), true)) {
            throw new \InvalidArgumentException("Unknown API error code: {$code}");
        }

        return self::statusForCode($code);
    }

    /**
     * A forma exacta que os serviços devolvem e que o `JsonResponder` serializa.
     *
     * @return array{error: array{code: string, message: string}}
     */
    public function toArray(): array
    {
        return ['error' => ['code' => $this->code, 'message' => $this->message]];
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

    /**
     * O nome de empresa repetido responde 409, como o `STATUS_BY_CODE` declara. Este bloco
     * dizia 400, que era o estado que a inferência antiga lhe dava por engano; ficou para
     * trás quando o mapa passou a decidir.
     */
    public static function duplicateCompany(): self
    {
        return new self('duplicate', 'Company with this name already exists');
    }

    // Regras de negócio recusadas.

    public static function forbidden(): self
    {
        return new self('forbidden', 'Forbidden');
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
