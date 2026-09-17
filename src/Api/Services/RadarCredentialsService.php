<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\RadarCredentialsWriteRequest;
use Hub\Api\Request\RequestBinder;

/**
 * As credenciais da cloud do fabricante dos radares, que são de cada licença.
 *
 * Não existe conta que veja a frota toda: com a conta de uma licença, os radares das outras
 * respondem `777` -- "dispositivo offline" -- mesmo a publicar telemetria nesse minuto. Nem o
 * endereço base é comum entre elas.
 */
class RadarCredentialsService
{
    private RequestBinder $binder;

    public function __construct(
        private ApiDataAccess $db,
        ?RequestBinder $binder = null,
    ) {
        $this->binder = $binder ?? new RequestBinder();
    }

    /**
     * A palavra-passe e o segredo não entram na resposta. São reversíveis por necessidade --
     * servem para fazer login no fornecedor --, e não saírem é o que resta como defesa; quem
     * desenha o ecrã só precisa de saber se já lá estão.
     *
     * @return array<string, mixed>
     */
    public function show(int $licenseRefId): array
    {
        if ($this->db->licenses->findById($licenseRefId) === null) {
            return ApiError::licenseNotFound()->toArray();
        }

        $row = $this->db->radarCredentials->findByLicenseRefId($licenseRefId);

        return ['data' => [
            'configured' => $row !== null,
            'baseUrl' => (string)($row['base_url'] ?? ''),
            'username' => (string)($row['username'] ?? ''),
            'appId' => (string)($row['app_id'] ?? ''),
            'hasPassword' => ($row['password'] ?? '') !== '',
            'hasAppSecret' => ($row['app_secret'] ?? '') !== '',
            'updatedAt' => $row['updated_at'] ?? null,
        ]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function save(int $licenseRefId, array $payload): array
    {
        if ($this->db->licenses->findById($licenseRefId) === null) {
            return ApiError::licenseNotFound()->toArray();
        }

        $existing = $this->db->radarCredentials->findByLicenseRefId($licenseRefId);
        $request = $this->binder->bind(
            $payload,
            RadarCredentialsWriteRequest::class,
            // Só a primeira gravação exige os segredos: metade das credenciais nunca autentica.
            $existing === null ? [RadarCredentialsWriteRequest::GROUP_CREATE] : [],
        );
        if (is_array($request)) {
            return $request;
        }

        $this->db->radarCredentials->upsert(
            $licenseRefId,
            trim($request->baseUrl ?? ''),
            trim($request->username ?? ''),
            $this->kept($request->password, $existing['password'] ?? ''),
            trim($request->appId ?? ''),
            $this->kept($request->appSecret, $existing['app_secret'] ?? ''),
        );

        return ['status' => 'ok'];
    }

    /** @return array<string, mixed> */
    public function delete(int $licenseRefId): array
    {
        if ($this->db->licenses->findById($licenseRefId) === null) {
            return ApiError::licenseNotFound()->toArray();
        }
        $this->db->radarCredentials->delete($licenseRefId);

        return ['status' => 'ok'];
    }

    /**
     * Vazio é "fica como está" e não "apaga": o ecrã nunca recebeu o segredo, logo também não
     * o pode reenviar, e corrigir uma gralha no endereço deixava a licença sem autenticar.
     */
    private function kept(?string $submitted, string $stored): string
    {
        $submitted = trim($submitted ?? '');

        return $submitted === '' ? $stored : $submitted;
    }
}
