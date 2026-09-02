#!/usr/bin/env bash
set -euo pipefail

# Cópias de segurança da base de dados de uma instância do hub. Ver `docs/18-backups.md`.
#
#   backup-db.sh            copia e roda -- é o que o temporizador chama
#   backup-db.sh rotate     só a rotação
#   backup-db.sh install    escreve as unidades de systemd e liga o temporizador
#
# A instância vem do diretório em que o script está, como nos alvos do Makefile: em
# `/opt/havicare-hub` copia a produção e em `/opt/havicare-hub-dev` a de desenvolvimento.
# Não há argumento nenhum que a escolha, e por isso não há escolha que se faça mal.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT_DIR/.env"

# Lê uma chave do `.env` sem o carregar: carregá-lo executaria o que lá estivesse e
# traria para o ambiente dezenas de variáveis sem uso aqui.
env_value() {
    [ -f "$ENV_FILE" ] || return 0
    sed -n "s/^$1=//p" "$ENV_FILE" | tail -n 1
}

DB_NAME="${DB_NAME:-$(env_value DB_NAME)}"
DB_HOST="${DB_HOST:-$(env_value DB_HOST)}"
DB_PORT="${DB_PORT:-$(env_value DB_PORT)}"
DB_USER="${DB_USER:-$(env_value DB_USER)}"
DB_PASSWORD="${DB_PASSWORD:-$(env_value DB_PASSWORD)}"

BACKUP_DIR="${BACKUP_DIR:-/var/backups/$DB_NAME}"

# Quinze diárias e doze mensais: um ano de alcance por 27 ficheiros. O teto de espaço é
# esse número vezes o tamanho de um dump, e não cresce com o tempo nem com o número de
# vezes que a rotação corre.
DAILY_KEEP=15
MONTHLY_KEEP=12

require_db_name() {
    [ -n "$DB_NAME" ] || { echo "backup-db: falta DB_NAME -- não há $ENV_FILE" >&2; exit 1; }
}

# A produção corre MariaDB e o ambiente local corre MySQL: serve o cliente que estiver
# instalado, e os dois aceitam as mesmas opções.
dump_binary() {
    command -v mariadb-dump || command -v mysqldump || {
        echo "backup-db: não há mariadb-dump nem mysqldump" >&2
        exit 1
    }
}

dump() {
    require_db_name

    local file="$BACKUP_DIR/$DB_NAME-$(date +%F).sql.gz"

    # Resolvido antes do dump e em linha própria: dentro da substituição o `exit` só
    # mataria o subshell, e é a atribuição que faz o `set -e` parar o script.
    local dump_bin
    dump_bin="$(dump_binary)"

    # As cópias levam os resumos das passwords da API, os IMEI e os nomes dos clientes:
    # não são legíveis por mais ninguém além de quem as escreve.
    umask 077
    mkdir -p "$BACKUP_DIR"

    # O ficheiro só ganha o nome definitivo depois de estar completo. Um dump interrompido
    # a meio deixaria um `.gz` truncado com aparência de cópia boa, que é pior do que
    # cópia nenhuma -- e é `pipefail` que faz a falha do dump derrubar o `gzip` à frente.
    trap 'rm -f "$file.part"' EXIT
    MYSQL_PWD="$DB_PASSWORD" "$dump_bin" \
        --single-transaction --quick \
        -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" \
        "$DB_NAME" | gzip >"$file.part"
    mv "$file.part" "$file"
    trap - EXIT

    echo "backup-db: $file ($(du -h "$file" | cut -f1))"
}

# Apaga tudo menos as $1 entradas mais recentes da lista que recebe na entrada.
keep_newest() {
    sort -r | tail -n "+$(($1 + 1))" | while read -r old; do rm -- "$old"; done
}

rotate() {
    require_db_name
    [ -d "$BACKUP_DIR" ] || return 0

    # A data está no nome, e por isso a ordem alfabética é a cronológica. O critério é o
    # nome e não a data do ficheiro de propósito: copiar o diretório para outra máquina
    # reescreve as datas dos ficheiros, e uma rotação por `-mtime` passava a poupar tudo.
    find "$BACKUP_DIR" -name "$DB_NAME-????-??-??.sql.gz" ! -name '*-01.sql.gz' \
        | keep_newest "$DAILY_KEEP"
    find "$BACKUP_DIR" -name "$DB_NAME-????-??-01.sql.gz" \
        | keep_newest "$MONTHLY_KEEP"
}

install_units() {
    require_db_name

    # O nome sai do diretório, para que as duas instâncias possam ter cada uma o seu
    # temporizador sem colidirem: `havicare-hub-backup` e `havicare-hub-dev-backup`.
    local name
    name="$(basename "$ROOT_DIR")-backup"

    cat >"/etc/systemd/system/$name.service" <<EOF
[Unit]
Description=Cópia de segurança da base de dados $DB_NAME

[Service]
Type=oneshot
ExecStart=$ROOT_DIR/bin/backup-db.sh
EOF

    # `Persistent=true` recupera a cópia do dia se a máquina estiver desligada às 03:30.
    cat >"/etc/systemd/system/$name.timer" <<EOF
[Unit]
Description=Cópia diária da base de dados $DB_NAME

[Timer]
OnCalendar=03:30
Persistent=true

[Install]
WantedBy=timers.target
EOF

    systemctl daemon-reload
    systemctl enable --now "$name.timer"
    systemctl list-timers "$name.timer" --no-pager
}

case "${1:-backup}" in
    backup) dump; rotate ;;
    rotate) rotate ;;
    install) install_units ;;
    *) echo "uso: $0 [backup|rotate|install]" >&2; exit 1 ;;
esac
