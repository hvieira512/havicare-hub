# Instruções do projeto

A documentação técnica do hub está em [`docs/README.md`](docs/README.md) —
como os dispositivos falam, como os dados são normalizados, e o contrato da API
e do MQTT. Este ficheiro é só sobre operar o projeto.

## As duas instâncias

O servidor `hub-prod` corre **duas** instâncias do hub, na mesma máquina e
separadas em tudo o que guarda estado. O trabalho passa sempre pela de
desenvolvimento antes de chegar à de produção.

| | desenvolvimento | produção |
|---|---|---|
| Diretório | `/opt/havicare-hub-dev` | `/opt/havicare-hub` |
| Serviço | `hub-dev` | `health-hub` |
| Ramo | `dev` | `main` |
| Dashboard | `:8091` | `:8081` |
| Ingestão TCP | `127.0.0.1:8090` | `0.0.0.0:8080` |
| Base de dados | `hitecosystem_hub_dev` | `hitecosystem_hub` |
| Prefixo do Redis | `dev:` | *(vazio)* |
| Tópicos MQTT | `havicare-hub-dev` | `havicare-hub` |
| Id de cliente MQTT | `health-mqtt-dev` | `health-mqtt` |

Alias SSH: `hub-prod` (configurado localmente em `~/.ssh/config`). O broker MQTT
é outra máquina, com o alias `mqtt-prod`.

A instância de dev **lê** os tópicos de ingestão reais — vê os mesmos
dispositivos que a produção — mas publica, guarda e comanda tudo no seu próprio
espaço. Não consegue tocar em nenhum dispositivo real: os relógios ligam-se por
TCP à porta da produção, e os comandos por MQTT saem para tópicos que nenhum
aparelho ouve.

O que separa as duas nunca pode ser tocado sem intenção. Em especial o
identificador de cliente MQTT: os subscritores de ingestão usam identificador
**estável**, sem PID, e dois iguais expulsam-se do broker em ciclo.

Comandos úteis:

- Entrar no servidor: `ssh hub-prod`
- Estado: `systemctl status hub-dev` / `systemctl status health-hub`
- Logs: `journalctl -u hub-dev` / `journalctl -u health-hub`
- Reiniciar: `systemctl restart hub-dev` / `systemctl restart health-hub`

## Fluxo de trabalho

O trabalho vai primeiro à instância de desenvolvimento, e só depois de estar
confirmado ali é que se leva à de produção.

1. Inspecionar as alterações existentes e preservar trabalho não relacionado.
2. Implementar e testar localmente.
3. Fazer commit em `dev` e `push` quando o utilizador o pedir ou quando fizer
   parte explícita do fluxo pedido.
4. Atualizar a instância de dev: `cd /opt/havicare-hub-dev && git pull --ff-only`,
   `composer install --no-dev --optimize-autoloader`, `php bin/migrate.php`,
   `systemctl restart hub-dev`.
5. Verificar ali: estado do serviço, logs sem erros novos, e a funcionalidade
   com os dispositivos, API, Redis ou MQTT relevantes.
6. Só então promover para produção — `git push origin dev:main` — e correr
   `make prod-update` em `/opt/havicare-hub`.
7. Verificar o estado e os logs do `health-hub`, e repetir em produção as
   verificações que se fizeram em dev.
8. Confirmar que não há regressões e comunicar resultados concretos.

Se o pedido do utilizador for só "testar" ou "experimentar", parar no passo 5:
promover para `main` é publicação, e essa é decisão dele.

## Verificações que valem a pena

- **Isolamento das instâncias.** As chaves do Redis são `hub:*` para produção e
  `dev:hub:*` para dev; contar as duas antes e depois mostra se alguma passou
  para o lado da outra. Note que o `hub:` é partilhado com o reencaminhador
  (`hub:forward:*`, `hub:crm:target:*`), que é outra aplicação.
- **O broker.** O mosquitto do `mqtt-prod` está configurado para escrever em
  `/var/log/mosquitto.log`, ficheiro que não existe; só os avisos chegam ao
  journal. Ainda assim serve de detetor: `journalctl -u mosquitto | grep
  "already connected"` denuncia identificadores repetidos.
- **Sentinelas contra NULL.** Na base de dados, um dispositivo sem dono tem
  `NULL` no `company` e no `license_id`. O texto `'null'` e o `0` são os
  sentinelas que só valem em memória e no ficheiro da whitelist; o
  `WhitelistRepository` converte-os na fronteira e não devem chegar às colunas.

## Segurança operacional

- Não guardar passwords, tokens ou chaves neste ficheiro.
- Um pedido para analisar produção autoriza verificações não destrutivas, mas
  não autoriza automaticamente publicação, reinício do serviço ou alterações de
  dados.
- Só executar `make prod-update`, promover para `main`, reiniciar serviços ou
  alterar dados quando isso estiver incluído no pedido do utilizador. Mexer na
  instância de dev é menos grave, mas continua a ser uma máquina de produção.
- Antes de remover ou alterar dados, resolver e confirmar exatamente os
  registos afetados — e preferir uma condição que inclua o valor antigo, para
  não poder acertar noutra linha.
- Não substituir alterações locais ou remotas que não pertençam à tarefa atual.
- Ao mudar de ramo em produção, fazer `git fetch` antes do `checkout`: a `main`
  local do servidor já esteve centenas de commits atrasada, e o checkout sozinho
  leva a árvore para trás.
