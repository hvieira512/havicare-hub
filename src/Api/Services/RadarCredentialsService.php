<?php

declare(strict_types=1);

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Infrastructure\Persistence\Repository\ApiDataAccess;
use Hub\Api\Request\RadarCredentialsWriteRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * As credenciais da cloud do fabricante dos radares, que são de cada licença: com a conta de
 * outra, os radares respondem `777` mesmo a publicar, e nem o endereço base é comum.
 */
class RadarCredentialsService
{
    /** Quantos radares se experimentam: a primeira dezena já diz se a conta é desta licença. */
    private const SAMPLE = 10;

    private RequestBinder $binder;

    public function __construct(
        private ApiDataAccess $db,
        ?RequestBinder $binder = null,
        private ?RadarLayoutSync $sync = null,
    ) {
        $this->binder = $binder ?? new RequestBinder();
    }

    /**
     * A palavra-passe e o segredo não entram na resposta: são reversíveis por necessidade, e o
     * ecrã só precisa de saber se já lá estão.
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

    /**
     * O botão de experimentar, antes de gravar. Autenticar não prova nada -- a conta de outra
     * licença autentica à mesma --, e por isso o que volta é quantos destes radares ela conhece.
     *
     * @param array<string, mixed> $payload o que está no ecrã, com os segredos em branco
     *                                      quando ninguém lhes tocou
     * @return PromiseInterface<array<string, mixed>>
     */
    public function check(int $licenseRefId, array $payload): PromiseInterface
    {
        $license = $this->db->licenses->findById($licenseRefId);
        if ($this->sync === null || $license === null) {
            return resolve(ApiError::licenseNotFound()->toArray());
        }

        $stored = $this->db->radarCredentials->findByLicenseRefId($licenseRefId);
        $credentials = [
            'base_url' => $this->orStored($payload['baseUrl'] ?? null, $stored['base_url'] ?? ''),
            'username' => $this->orStored($payload['username'] ?? null, $stored['username'] ?? ''),
            'password' => $this->orStored($payload['password'] ?? null, $stored['password'] ?? ''),
            'app_id' => $this->orStored($payload['appId'] ?? null, $stored['app_id'] ?? ''),
            'app_secret' => $this->orStored($payload['appSecret'] ?? null, $stored['app_secret'] ?? ''),
        ];

        $radars = $this->licenseRadars($license);

        return $this->sync->syncLicense($credentials, $radars)->then(
            static fn(array $tally): array => ['data' => [
                'radars' => count($radars),
                // O `200` é o fabricante a dizer que conhece o aparelho; o `777`, que não.
                'responding' => (int)($tally['codes']['200'] ?? 0),
                'error' => $tally['error'],
            ]],
        );
    }

    /**
     * Os radares desta licença, pelo `device_id` do fabricante e não pelo IMEI do hub. Pelo par
     * número + empresa, porque o mesmo número existe em empresas diferentes.
     *
     * @param array<string, mixed> $license
     * @return list<array{imei: string, uid: string}>
     */
    private function licenseRadars(array $license): array
    {
        $company = $this->db->companies->findById((int)($license['company_id'] ?? 0));
        $radars = $this->db->whitelist->listByDeviceType(
            'radar',
            (int)($license['license_id'] ?? 0),
            (string)($company['name'] ?? ''),
            self::SAMPLE,
        );

        return array_map(static function (array $device): array {
            $imei = (string)$device['imei'];
            $uid = trim((string)($device['device_id'] ?? ''));

            return ['imei' => $imei, 'uid' => $uid !== '' ? $uid : $imei];
        }, $radars);
    }

    private function orStored(mixed $typed, string $stored): string
    {
        $typed = is_string($typed) ? trim($typed) : '';

        return $typed !== '' ? $typed : $stored;
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
