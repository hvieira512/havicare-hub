#!/usr/bin/env bash
# Corre uma suite de PHPUnit repartida por vários processos, um ficheiro de teste de cada vez.
#
# Não é o paratest: o paratest que fala com o PHPUnit 10 exige PHP 8.4 ou menos, e o CI
# também corre em 8.5.
#
# Uso: tests/run-parallel.sh tests/Integration
set -uo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

SUITE_DIR="${1:?indique a pasta da suite, por exemplo tests/Integration}"

# Acima de oito processos o MySQL serializa o DDL e a corrida fica mais lenta, não mais rápida.
WORKERS="${TEST_WORKERS:-$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)}"
[ "$WORKERS" -gt 8 ] && WORKERS=8

export OUTPUT_DIR
OUTPUT_DIR="$(mktemp -d)"
trap 'rm -rf "$OUTPUT_DIR"' EXIT

# Do maior ficheiro para o menor, para os longos arrancarem primeiro e os curtos encherem o fim.
find "$SUITE_DIR" -name '*Test.php' -print0 \
  | xargs -0 ls -S \
  | xargs -P "$WORKERS" -n 1 sh -c \
      'vendor/bin/phpunit --do-not-cache-result "$@" > "$OUTPUT_DIR/$$.log" 2>&1' _
status=$?

cat "$OUTPUT_DIR"/*.log

# A soma serve de prova de que o paralelo corre os mesmos testes que uma corrida única.
awk '
    /^OK \(/                 { gsub(/[^0-9 ]/, " "); tests += $1; assertions += $2 }
    /^Tests: [0-9]+, Assert/ { gsub(/[^0-9 ]/, " "); tests += $1; assertions += $2 }
    END { printf "\n%s: %d testes, %d asserções\n", SUITE, tests, assertions }
' SUITE="$SUITE_DIR" "$OUTPUT_DIR"/*.log

exit $((status == 0 ? 0 : 1))
