<?php

declare(strict_types=1);

namespace Tests\Unit\Api\Http;

use Hub\Api\Http\ApiError;
use Hub\Api\Http\JsonResponder;
use PHPUnit\Framework\TestCase;

final class ApiErrorTest extends TestCase
{
    /**
     * O contrato que vai no fio, construtor a construtor. É um detector de mudanças: estes
     * códigos e mensagens estão na especificação e nos clientes, e alterar um é alterar a API
     * pública.
     *
     * @return array<string, array{callable(): ApiError, string, string, int}>
     */
    public static function errors(): array
    {
        return [
            'invalidJson' => [fn(): ApiError => ApiError::invalidJson(), 'invalid_request', 'Invalid JSON', 400],
            'invalidRequest' => [fn(): ApiError => ApiError::invalidRequest('name is required'), 'invalid_request', 'name is required', 400],
            'invalidConfig' => [fn(): ApiError => ApiError::invalidConfig('bad value'), 'invalid_config', 'bad value', 400],
            'invalidLink' => [fn(): ApiError => ApiError::invalidLink('A gateway can only link to a diaper sensor or a bracelet'), 'invalid_link', 'A gateway can only link to a diaper sensor or a bracelet', 400],
            'unsupportedFeature' => [fn(): ApiError => ApiError::unsupportedFeature('Feature is not supported for this device'), 'unsupported_feature', 'Feature is not supported for this device', 400],
            'unknownProtocol' => [fn(): ApiError => ApiError::unknownProtocol('Device protocol could not be resolved'), 'unknown_protocol', 'Device protocol could not be resolved', 400],
            'deviceNotFound' => [fn(): ApiError => ApiError::deviceNotFound(), 'not_found', 'Device was not found', 404],
            'commandNotFound' => [fn(): ApiError => ApiError::commandNotFound(), 'not_found', 'Command was not found', 404],
            'modelNotFound' => [fn(): ApiError => ApiError::modelNotFound(), 'model_not_found', 'Model not found', 404],
            'modelNotFoundForSupplier' => [fn(): ApiError => ApiError::modelNotFoundForSupplier(), 'model_not_found', 'Model does not exist for this supplier', 404],
            'companyNotFound' => [fn(): ApiError => ApiError::companyNotFound(), 'company_not_found', 'Company not found', 404],
            'licenseNotFound' => [fn(): ApiError => ApiError::licenseNotFound(), 'license_not_found', 'License not found', 404],
            'capabilityNotFound' => [fn(): ApiError => ApiError::capabilityNotFound(), 'capability_not_found', 'Capability not found', 404],
            'discoveryNotFound' => [fn(): ApiError => ApiError::discoveryNotFound(), 'discovery_not_found', 'Discovery run not found', 404],
            'notificationNotFound' => [fn(): ApiError => ApiError::notificationNotFound(), 'notification_not_found', 'Notification not found', 404],
            'supplierNotFound' => [fn(): ApiError => ApiError::supplierNotFound(), 'supplier_not_found', 'Supplier does not exist', 404],
            'protocolNotFound' => [fn(): ApiError => ApiError::protocolNotFound(), 'protocol_not_found', 'Unsupported protocol', 404],
            'userNotFound' => [fn(): ApiError => ApiError::userNotFound(), 'user_not_found', 'API user not found', 404],
            'associationNotFound' => [fn(): ApiError => ApiError::associationNotFound(), 'association_not_found', 'Device association was not found', 404],
            'deviceExists' => [fn(): ApiError => ApiError::deviceExists(), 'device_exists', 'Device with this IMEI already exists', 409],
            'modelExists' => [fn(): ApiError => ApiError::modelExists(), 'model_exists', 'Another model with this supplier and model name already exists', 409],
            'userExists' => [fn(): ApiError => ApiError::userExists(), 'user_exists', 'Username already exists', 409],
            'duplicateCompany' => [fn(): ApiError => ApiError::duplicateCompany(), 'duplicate', 'Company with this name already exists', 409],
            'forbidden' => [fn(): ApiError => ApiError::forbidden(), 'forbidden', 'Forbidden', 403],
            'unauthorized' => [fn(): ApiError => ApiError::unauthorized(), 'unauthorized', 'Unauthorized', 401],
            'routeNotFound' => [fn(): ApiError => ApiError::routeNotFound(), 'not_found', 'Not found', 404],
            'serverError' => [fn(): ApiError => ApiError::serverError(), 'server_error', 'Internal server error', 500],
            'tooManyStreams' => [fn(): ApiError => ApiError::tooManyStreams(), 'too_many_streams', 'Too many open streams; close one and retry', 503],
            'tooManyAttempts' => [fn(): ApiError => ApiError::tooManyAttempts(), 'too_many_attempts', 'Too many authentication attempts; slow down and retry', 429],
            'deviceAlreadyAssociated' => [fn(): ApiError => ApiError::deviceAlreadyAssociated(), 'device_already_associated', 'Device is already associated', 400],
            'invalidAssociation' => [fn(): ApiError => ApiError::invalidAssociation(), 'invalid_association', 'company and licenseId do not match a registered license', 400],
            'invalidCredentials' => [fn(): ApiError => ApiError::invalidCredentials(), 'invalid_credentials', 'Invalid credentials', 401],
            'invalidRefreshToken' => [fn(): ApiError => ApiError::invalidRefreshToken(), 'invalid_refresh_token', 'Invalid refresh token', 401],
            'invalidRole' => [fn(): ApiError => ApiError::invalidRole(), 'invalid_role', 'role must be hub_admin or license_client', 400],
            'invalidLicense' => [fn(): ApiError => ApiError::invalidLicense(), 'invalid_license', 'A valid company license is required for license clients', 400],
            'discoveryMissingModelId' => [fn(): ApiError => ApiError::discoveryMissingModelId(), 'invalid_state', 'Discovery run is missing the model id', 400],
            'featureNotRequestable' => [fn(): ApiError => ApiError::featureNotRequestable(), 'feature_not_requestable', 'Feature cannot be requested for this device', 400],
            'unsupportedCapability' => [fn(): ApiError => ApiError::unsupportedCapability(), 'unsupported_capability', 'One or more capabilities are not allowed for this device type', 400],
            'invalidRequestableCapability' => [fn(): ApiError => ApiError::invalidRequestableCapability(), 'invalid_requestable_capability', 'Requestable telemetry must also be supported and requestable in the capability catalog', 400],
            'uploadFailed' => [fn(): ApiError => ApiError::uploadFailed(), 'upload_failed', 'Image upload failed', 400],
            'imageTooLarge' => [fn(): ApiError => ApiError::imageTooLarge(), 'image_too_large', 'Model image must be 5 MB or smaller', 400],
            'gdMissing' => [fn(): ApiError => ApiError::gdMissing(), 'gd_missing', 'PHP GD extension is required to compress model images', 400],
            'gdJpegMissing' => [fn(): ApiError => ApiError::gdJpegMissing(), 'gd_jpeg_missing', 'PHP GD JPEG support is required to save compressed model images', 400],
            'invalidImage' => [fn(): ApiError => ApiError::invalidImage(), 'invalid_image', 'Model image must be a valid image file', 400],
            'imageSaveFailed' => [fn(): ApiError => ApiError::imageSaveFailed(), 'image_save_failed', 'Could not save model image', 400],
        ];
    }

    /**
     * @param callable(): ApiError $build
     * @dataProvider errors
     */
    public function testEachErrorKeepsItsCodeMessageAndStatus(
        callable $build,
        string $code,
        string $message,
        int $status
    ): void {
        $error = $build();

        self::assertSame(['error' => ['code' => $code, 'message' => $message]], $error->toArray());
        self::assertSame($status, ApiError::declaredStatus($code));
        self::assertSame($status, (new JsonResponder())->result($error->toArray())->getStatusCode());
    }

    /**
     * O `codes()` é o que a especificação usa para declarar cada rota. Um construtor fora do
     * mapa respondia 400 por omissão sem nenhuma rota o poder declarar, e um código no mapa
     * sem construtor prometia um estado que ninguém envia -- os dois são silenciosos.
     */
    public function testTheDeclaredCodesAreExactlyTheOnesTheConstructorsProduce(): void
    {
        $fromConstructors = array_unique(array_map(
            static fn(array $case): string => $case[1],
            array_values(self::errors()),
        ));
        sort($fromConstructors);

        $declared = ApiError::codes();
        sort($declared);

        self::assertSame($declared, array_values($fromConstructors));
    }

    public function testAResultWithoutAnErrorKeepsTheSuccessStatus(): void
    {
        $json = new JsonResponder();

        self::assertSame(200, $json->result(['status' => 'ok'])->getStatusCode());
        self::assertSame(201, $json->result(['status' => 'ok'], 201)->getStatusCode());
    }

    /**
     * O estado não se infere da forma do nome. O `result()` é leniente de propósito -- a um
     * engano em execução responde o 400 que ele quase de certeza queria dizer; quem não
     * perdoa é o `declaredStatus()`, que rebenta a montar a especificação.
     */
    public function testAnUndeclaredCodeIsFourHundredInsteadOfInferredFromItsShape(): void
    {
        $json = new JsonResponder();

        self::assertSame(404, $json->result(['error' => ['code' => 'company_not_found']], 201)->getStatusCode());
        self::assertSame(400, $json->result(['error' => ['code' => 'widget_not_found']])->getStatusCode());
        self::assertSame(400, $json->result(['error' => ['code' => 'widget_exists']])->getStatusCode());
        self::assertSame(400, $json->result(['error' => ['code' => '']])->getStatusCode());
    }

    public function testTheSpecRefusesACodeItDoesNotKnow(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ApiError::declaredStatus('widget_not_found');
    }
}
