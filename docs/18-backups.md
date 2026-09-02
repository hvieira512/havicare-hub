# 18 — Cópias de segurança

## Âmbito

As cópias de segurança cobrem a **base de dados MySQL** de cada instância, que é
onde reside o estado durável: o inventário de dispositivos, a atribuição a
clientes, as capacidades de cada modelo e a configuração. O procedimento é
automático, roda sozinho, e tem um teto de espaço fixo.

Em MySQL e MariaDB, `SCHEMA` e `DATABASE` são sinónimos: não existe o nível
intermédio do PostgreSQL, em que uma base contém vários schemas. A `havicare_hub`
e a `havicare_hub_dev` são **duas bases distintas** no mesmo servidor, e um dump
cobre exactamente a que lhe for nomeada — a cópia da produção não contém uma
linha da base de desenvolvimento.

O Redis fica de fora por não guardar nada que se perca: presença, mensagens
recentes, comandos pendentes e sessões constituem estado corrente, reconstruído
pelos dispositivos em minutos. O esquema relacional também está fora, por estar
versionado em `database/schema.sql` e nas migrações — ver a
[persistência](14-persistencia.md).

## 1. O que se perde sem cópia

Da base de dados de produção, o que não se recupera de mais lado nenhum:

| Tabela | Porque não se reconstrói |
|---|---|
| `whitelist` | O registo de dispositivos. Cada IMEI foi introduzido à mão, com fornecedor, modelo e dono |
| `companies` · `licenses` | Os clientes e as respetivas licenças |
| `api_users` | As contas da API, com o resumo da password |
| `device_configurations` e as duas tabelas de alterações | O estado desejado e reportado de cada dispositivo |
| `gateway_device_links` | Que dispositivos BLE cada gateway retransmite |
| `private_radio_map_access_points` | O mapa de rádio, acumulado por observação |

O catálogo — `suppliers`, `models`, `capabilities`, `model_capabilities` — é
semeado pelo código e recupera-se com o `ReferenceCatalogSeeder`. Está incluído
nas cópias por ser mais barato copiá-lo do que distingui-lo.

**Não existe tabela de telemetria.** A base muda ao ritmo a que uma pessoa
regista um dispositivo ou pede uma alteração de configuração, e não ao ritmo das
mensagens que entram. É esse facto que fixa a periodicidade em **diária**: o
pior caso de perda é refazer os registos de um dia.

Em setembro de 2026 a base de produção ocupa 13,8 MB em cerca de 1130 linhas, e
o dump comprimido de um dia ocupa 7,1 MB.

## 2. O procedimento

```
bin/backup-db.sh            copia e roda -- é o que o temporizador chama
bin/backup-db.sh rotate     só a rotação
bin/backup-db.sh install    escreve as unidades de systemd e liga o temporizador
```

A instância vem do **diretório em que o script está**, e não de um argumento,
pela mesma razão que nos alvos do `Makefile`: em `/opt/havicare-hub` copia a
produção e em `/opt/havicare-hub-dev` a de desenvolvimento. Não há escolha que se
possa fazer mal. As credenciais e o nome da base saem do `.env` ao lado, pelo que
a cópia corre com os mesmos privilégios que a aplicação e não exige `root` nem
autenticação por socket.

| | |
|---|---|
| Destino | `/var/backups/{nome da base}`, com permissões `700` |
| Nome | `{nome da base}-AAAA-MM-DD.sql.gz` |
| Hora | 03:30, com `Persistent=true` para recuperar o dia se a máquina estiver desligada |
| Unidades | `havicare-hub-backup` e `havicare-hub-dev-backup`, uma por instância, geradas pelo `install` |

O dump corre com `--single-transaction`, o que lhe dá uma vista coerente sem
bloquear escritas: a ingestão continua a gravar durante a cópia. O ficheiro é
escrito como `.part` e **só ganha o nome definitivo depois de estar completo** —
um dump interrompido a meio deixaria um `.gz` truncado com aparência de cópia
boa, que é pior do que cópia nenhuma.

> As cópias contêm os resumos das passwords das contas da API, os IMEI dos
> dispositivos e os nomes dos clientes. O diretório e os ficheiros são criados
> sem permissões para terceiros, e qualquer cópia que saia da máquina fica
> sujeita ao regime aplicável a estes dados.

## 3. Rotação e teto de espaço

| | Quantas | Alcance |
|---|---|---|
| Diárias | 15 | as últimas duas semanas |
| Mensais — a cópia do dia 1 | 12 | os últimos doze meses |
| **Total** | **27 ficheiros** | **um ano** |

A rotação corre a seguir a cada cópia e não depende de ninguém se lembrar dela. O
teto de espaço é 27 vezes o tamanho de um dump, **por instância** — cerca de
192 MB para a produção aos valores de setembro de 2026, e uma fração disso para a
de desenvolvimento — e não cresce com o tempo nem com o número de vezes que a
rotação corre.

Guardar o dia 1 de cada mês dá um ano de alcance pelo preço de duas semanas de
diárias, e é o que cobre o caso que só se descobre tarde: um apagamento ou uma
corrupção notados seis semanas depois já teriam entrado em todas as cópias de um
esquema de retenção puramente diário.

**O critério é o nome do ficheiro e não a data de modificação.** A data está no
nome, e por isso a ordem alfabética é a cronológica. A escolha é deliberada:
copiar o diretório para outra máquina reescreve as datas dos ficheiros, e uma
rotação assente nelas passaria a poupar tudo em silêncio.

A rotação apaga ficheiros, e um erro nela não se manifesta como avaria. Está
presa pelo cenário `tests/scenarios/scenario_backup_rotation.sh`, que a exercita
com 401 cópias e verifica o número que sobra, a sobrevivência da mais recente, a
idempotência de uma segunda passagem e o isolamento entre as cópias das duas
instâncias.

## 4. Instalar numa máquina

O script e as unidades vivem no repositório, e não apenas na máquina: uma
migração é clonar e correr um comando.

```bash
cd /opt/havicare-hub     && bin/backup-db.sh install
cd /opt/havicare-hub-dev && bin/backup-db.sh install
```

O `install` escreve as duas unidades apontadas ao diretório em que corre, recarrega
o systemd e liga o temporizador. O nome das unidades sai do nome do diretório —
`havicare-hub-backup` e `havicare-hub-dev-backup` — pelo que cada instância tem o
seu temporizador sem colidir com o da outra.

```bash
systemctl list-timers 'havicare-hub*backup.timer'   # quando correm a seguir
systemctl status havicare-hub-backup                # como correu da última vez
journalctl -u havicare-hub-backup                   # o registo de cada corrida
ls -lh /var/backups/havicare_hub                    # o que está guardado
```

## 5. Restaurar

```bash
gunzip -c /var/backups/havicare_hub/havicare_hub-2026-09-02.sql.gz \
    | mariadb havicare_hub
```

O dump inclui `DROP TABLE IF EXISTS` antes de cada `CREATE TABLE`, pelo que o
restauro substitui o conteúdo da base indicada.

> **O ficheiro não sabe de que base veio.** Um dump de uma só base não traz
> `CREATE DATABASE` nem `USE`: é uma lista de tabelas, e o destino é
> inteiramente decidido pelo nome que se escreve na linha de comandos. O nome do
> ficheiro é a única indicação da origem, e nada impede que uma cópia da
> produção seja restaurada por cima da base de desenvolvimento, ou o contrário.

> **O serviço deve estar parado durante o restauro.** Com o `havicare-hub` a
> correr, a ingestão escreve por cima do restauro enquanto ele decorre.

Um ensaio de restauro não destrutivo, para confirmar que uma cópia serve sem
tocar na base em serviço:

```bash
mariadb -e 'CREATE DATABASE ensaio_restauro'
gunzip -c /var/backups/havicare_hub/havicare_hub-2026-09-02.sql.gz \
    | mariadb ensaio_restauro
mariadb -N -e 'SELECT COUNT(*) FROM ensaio_restauro.whitelist'
mariadb -e 'DROP DATABASE ensaio_restauro'
```

Uma cópia que nunca foi restaurada não está verificada. O ensaio é o que a
verifica.

## 6. Limites conhecidos

**As cópias ficam na mesma máquina e no mesmo disco que a base.** Cobrem o
apagamento da base de dados e o erro humano, que são os casos prováveis, e não
cobrem a perda do disco nem da máquina. Uma cópia fora da máquina resolveria
esse caso e não está montada.

**Uma falha do temporizador é silenciosa.** Fica registada no journal e visível
em `systemctl status`, mas ninguém é avisado. Uma unidade `OnFailure=` a
publicar em `dashboard_notifications` faria o aviso chegar ao sino da dashboard.

**As duas instâncias são copiadas em separado.** Cada uma tem o seu temporizador,
o seu diretório e a sua rotação, e nenhuma cópia contém dados da outra.

## Implementação

| Ficheiro | Responsabilidade |
|---|---|
| `bin/backup-db.sh` | A cópia, a rotação e a instalação das unidades de systemd |
| `tests/scenarios/scenario_backup_rotation.sh` | O cenário que prende a rotação |
| `database/schema.sql` | O esquema, que por estar versionado não precisa de cópia |
| [`14 — Persistência`](14-persistencia.md) | O que cada tabela guarda |
| [`15 — Operação`](15-operacao.md) | As instâncias, os serviços e o registo |
