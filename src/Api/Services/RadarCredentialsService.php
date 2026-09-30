<?php

namespace Hub\Api\Services;

use Hub\Api\Http\ApiError;
use Hub\Api\Repository\ApiDataAccess;
use Hub\Api\Request\RadarCredentialsWriteRequest;
use Hub\Api\Request\RequestBinder;
use Hub\Ingress\Http\Qinglanst\RadarLayoutSync;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * As credenciais da cloud do fabricante dos radares, que são de cada licença.
 *
 * Não existe conta que veja a frota toda: com a conta de uma licença, os radares das outras
 * respondem `777` -- "dispositivo offline" -- mesmo a publicar telemetria nesse minuto. Nem o
 * endereço base é comum entre elas.
 */
class RadarCredentialsService
{
    /**
     * Quantos radares se experimentam. O que se quer saber é se a conta é desta licença, e a
     * primeira dezena já o diz -- percorrer a frota era uma espera de botão a crescer com ela.
     */
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
     * Os radares desta licença, pelo identificador com que o fabricante os conhece -- que é o
     * `device_id` e não o IMEI canónico do hub.
     *
     * Um aparelho aponta para a licença pelo par número + empresa: o mesmo número existe em
     * empresas diferentes, e a amostra tem de ser desta.
     *
     * @param array<string, mixed> $license
     * @return list<array{imei: string, uid: string}>
     */
    private function licenseRadars(array $license): array
    {
        $company = $this->db->companies->findById((int)($license['company_id'] ?? 0));
        $page = $this->db->whitelist->listPage(
            ['deviceType' => 'radar'],
            1,
            self::SAMPLE,
            (int)($license['license_id'] ?? 0),
            (string)($company['name'] ?? ''),
        );

        return array_map(static function (array $device): array {
            $imei = (string)$device['imei'];
            $uid = trim((string)($device['device_id'] ?? ''));

            return ['imei' => $imei, 'uid' => $uid !== '' ? $uid : $imei];
        }, $page['items']);
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
