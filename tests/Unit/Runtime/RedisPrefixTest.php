<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Runtime\HubServices;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

/**
 * O prefixo das chaves do Redis, que é o que permite dois hubs no mesmo servidor.
 *
 * As chaves do hub vivem sob `hub:`, e essa raiz já é partilhada com o reencaminhador -- que
 * lá tem as suas `hub:forward:*` e `hub:crm:target:*`. Uma segunda instância a escrever nas
 * mesmas chaves não daria erro nenhum: escreveria por cima do estado dos dispositivos da
 * primeira, e as sessões das duas dashboards misturavam-se.
 *
 * O prefixo vai no cliente e não em cada store de propósito. São seis espaços de chaves hoje,
 * e o sétimo que aparecesse nascia sem ele -- em silêncio, que é como estas coisas doem.
 */
final class RedisPrefixTest extends TestCase
{
    /** Vazio é produção: as chaves têm de ficar exactamente onde sempre estiveram. */
    public function testAnEmptyPrefixDeclaresNoOptionAtAll(): void
    {
        self::assertSame([], HubServices::redisOptions(['prefix' => '']));
        self::assertSame([], HubServices::redisOptions([]), 'ausente é o mesmo que vazio');
        self::assertSame([], HubServices::redisOptions(['prefix' => '   ']), 'só espaços também');
    }

    public function testAPrefixBecomesAPredisOption(): void
    {
        self::assertSame(['prefix' => 'dev:'], HubServices::redisOptions(['prefix' => 'dev:']));
    }

    /**
     * O prefixo apanha as chaves de todos os stores, e não só as do primeiro.
     *
     * Não fala com nenhum Redis: constrói o comando pelo cliente, que é onde o Predis aplica
     * o prefixo, e olha para o argumento que sairia no fio.
     *
     * @dataProvider hubKeyspaces
     */
    public function testEveryHubKeyspaceGetsThePrefix(string $key): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => 'dev:']));

        $command = $client->createCommand('get', [$key]);

        self::assertSame('dev:' . $key, $command->getArgument(0));
    }

    /**
     * As raízes que o hub usa hoje, uma por store.
     *
     * Não se conta o número aqui: o `testTheKeyspaceListCoversEveryRootDeclaredInTheCode`
     * compara esta lista com o que o código declara, e é ele que avisa quando nasce outra.
     */
    public static function hubKeyspaces(): array
    {
        return [
            'dashboard' => ['hub:dashboard:861265061009822:telemetry'],
            'api tokens' => ['hub:api-tokens:abc123'],
            'downlink' => ['hub:downlink:861265061009822'],
            'moko' => ['hub:moko:d48c49f7909c'],
            'location circuit' => ['hub:location:circuit:beacondb'],
            'location resolution' => ['hub:location:resolution:861265061009822'],
            'login throttle' => ['hub:login-throttle:ip:203.0.113.9:29123456'],
        ];
    }

    /**
     * Sem prefixo, a chave sai intacta.
     *
     * É o que garante que ligar isto não mexe em produção: o cliente sem a opção não tem
     * processador nenhum para aplicar.
     */
    public function testWithoutAPrefixTheKeyIsUntouched(): void
    {
        $client = new RedisClient([], HubServices::redisOptions(['prefix' => '']));

        self::assertNull($client->getOptions()->prefix);
    }

    /**
     * O que faltava prender, e o que deixou passar duas fugas para a produção.
     *
     * Os testes acima provam o mecanismo do Predis com chaves escritas à mão. O que nenhum
     * prendia era o passo anterior: **quem constrói o cliente tem de lhe dar as opções.** Um
     * `new RedisClient($parametros)` sem o segundo argumento fica sem processador de prefixo e
     * escreve na raiz da produção, e nada nesta suite dava por isso -- foi assim que o
     * `benchmark-qinglanst.php` passou meses a escrever em `hub:dashboard:*`.
     *
     * Os testes ficam de fora de propósito: um teste pode querer o seu próprio espaço de
     * chaves, fora de `hub:`, e aí o cliente cru é a escolha certa.
     */
    public function testEveryClientBuiltByTheApplicationReceivesTheOptions(): void
    {
        $offenders = [];
        foreach (self::phpFilesIn(['src', 'bin', 'simulator']) as $file) {
            foreach (file($file) ?: [] as $number => $line) {
                if (!str_contains($line, 'new RedisClient(') && !str_contains($line, 'new Client(')) {
                    continue;
                }
                // A construção pode ocupar várias linhas: o que interessa é haver uma segunda
                // expressão antes do fecho, e o `redisOptions` é a única fonte legítima dela.
                $tail = implode('', array_slice(file($file) ?: [], $number, 12));
                if (!str_contains($tail, 'redisOptions') && !str_contains($line, 'redisOptions')) {
                    $offenders[] = self::relative($file) . ':' . ($number + 1);
                }
            }
        }

        self::assertSame([], $offenders, 'cliente Redis construído sem as opções do prefixo');
    }

    /**
     * A lista acima é escrita à mão, e uma lista à mão envelhece: quando este teste foi
     * escrito havia seis raízes, e a `hub:login-throttle` nasceu depois sem lá entrar. Isto
     * compara-a com o que o código realmente declara.
     */
    public function testTheKeyspaceListCoversEveryRootDeclaredInTheCode(): void
    {
        $declared = [];
        foreach (self::phpFilesIn(['src']) as $file) {
            preg_match_all("/'(hub:[a-z-]+)/", (string)file_get_contents($file), $matches);
            foreach ($matches[1] as $root) {
                $declared[$root] = true;
            }
        }

        $covered = [];
        foreach (self::hubKeyspaces() as [$key]) {
            preg_match("/^(hub:[a-z-]+)/", $key, $match);
            $covered[$match[1] ?? $key] = true;
        }

        self::assertSame(
            [],
            array_keys(array_diff_key($declared, $covered)),
            'raízes de chaves que o código usa e o teste não cobre'
        );
    }

    /** @return list<string> */
    private static function phpFilesIn(array $directories): array
    {
        $root = dirname(__DIR__, 3);
        $files = [];
        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private static function relative(string $path): string
    {
        return str_replace(dirname(__DIR__, 3) . '/', '', $path);
    }
}
