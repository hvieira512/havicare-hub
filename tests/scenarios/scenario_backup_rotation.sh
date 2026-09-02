#!/usr/bin/env bash
set -euo pipefail

# A rotação das cópias é a única parte do backup que apaga ficheiros, e um erro nela não
# se manifesta como avaria: apaga cópias em silêncio e só se descobre no dia em que uma
# delas faz falta. Daí estar presa por um cenário próprio.
#
# Ao contrário dos restantes cenários, este não levanta infraestrutura nenhuma. A rotação
# decide pelo nome do ficheiro, e nomes bastam para a exercitar.

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

DB="havicare_hub"
DAYS=400

echo "[backup_rotation] $((DAYS + 1)) cópias diárias em $WORK_DIR"

# As datas vêm do perl porque o `date` do macOS e o do Linux discordam na aritmética de
# dias, e este cenário corre nos dois.
for day in $(perl -e '
    my $now = time;
    for my $i (0 .. $ARGV[0]) {
        my @d = localtime($now - 86400 * $i);
        printf "%04d-%02d-%02d\n", $d[5] + 1900, $d[4] + 1, $d[3];
    }
' "$DAYS"); do
    touch "$WORK_DIR/$DB-$day.sql.gz"
done

newest="$(find "$WORK_DIR" -name "$DB-*.sql.gz" | sort -r | head -n 1)"
oldest="$(find "$WORK_DIR" -name "$DB-*.sql.gz" | sort | head -n 1)"

DB_NAME="$DB" BACKUP_DIR="$WORK_DIR" "$ROOT_DIR/bin/backup-db.sh" rotate

count() { find "$WORK_DIR" -name "$1" | wc -l | tr -d ' '; }

total="$(count "$DB-*.sql.gz")"
monthly="$(count "$DB-????-??-01.sql.gz")"
daily=$((total - monthly))

echo "[backup_rotation] sobraram $total ficheiros: $daily diários e $monthly mensais"

# O teto de espaço é este número, e é o que permite prometer que as cópias não enchem o
# disco: 27 ficheiros vezes o tamanho de um dump, sem crescer com o tempo.
[ "$total" -eq 27 ] || { echo "esperados 27 ficheiros, ficaram $total" >&2; exit 1; }
[ "$daily" -eq 15 ] || { echo "esperados 15 diários, ficaram $daily" >&2; exit 1; }
[ "$monthly" -eq 12 ] || { echo "esperados 12 mensais, ficaram $monthly" >&2; exit 1; }

# A cópia mais recente é a que se usa num restauro, e a mais antiga tinha de sair.
[ -f "$newest" ] || { echo "a cópia mais recente foi apagada: $newest" >&2; exit 1; }
[ ! -f "$oldest" ] || { echo "a cópia mais antiga sobreviveu: $oldest" >&2; exit 1; }

# Correr a rotação outra vez não pode apagar mais nada: o temporizador chama-a todos os
# dias, e uma rotação que morda a cada passagem esvaziava o diretório numa semana.
DB_NAME="$DB" BACKUP_DIR="$WORK_DIR" "$ROOT_DIR/bin/backup-db.sh" rotate
again="$(count "$DB-*.sql.gz")"
[ "$again" -eq 27 ] || { echo "a segunda rotação mexeu: $again ficheiros" >&2; exit 1; }

# As cópias de uma instância não podem ser tocadas pela rotação da outra: as duas bases
# convivem na mesma máquina e um engano aqui apagava as cópias da produção.
touch "$WORK_DIR/${DB}_dev-2020-06-15.sql.gz"
DB_NAME="$DB" BACKUP_DIR="$WORK_DIR" "$ROOT_DIR/bin/backup-db.sh" rotate
[ -f "$WORK_DIR/${DB}_dev-2020-06-15.sql.gz" ] \
    || { echo "a rotação de $DB apagou uma cópia de ${DB}_dev" >&2; exit 1; }

echo "[backup_rotation] OK"
