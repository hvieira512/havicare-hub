#!/bin/sh

WATCH_DIRS="${WATCH_DIRS:-/app/src /app/config /app/bin}"
POLL_INTERVAL="${POLL_INTERVAL:-1}"
CMD="$@"

if [ -z "$CMD" ]; then
    echo "Usage: bin/dev.sh <command>"
    echo "Example: bin/dev.sh php bin/server-hub.php"
    exit 1
fi

CHILD=""

# O `docker compose stop` manda o sinal a este script, e o comando vigiado é um filho dele.
# Sem reencaminhar, o filho morria com o contentor em vez de se desligar em condições -- e o
# hub, que distingue as duas coisas por um ficheiro que apaga no `SIGTERM`, registava cada
# paragem local como uma queda e enchia o sino da dashboard de avisos falsos.
#
# Em produção não se punha: lá o `ExecStart` do systemd é o próprio `php`, e o sinal chega-lhe
# directamente. Isto é só para o ciclo de desenvolvimento.
terminate() {
    if [ -n "$CHILD" ]; then
        kill -TERM "$CHILD" 2>/dev/null
        wait "$CHILD" 2>/dev/null
    fi
    exit 0
}
trap terminate TERM INT

echo "=== Dev watcher started ==="
echo "Watching: $WATCH_DIRS"
echo "Poll:     ${POLL_INTERVAL}s"
echo "Command:  $CMD"
echo ""

while true; do
    composer install --quiet 2>/dev/null
    $CMD &
    PID=$!
    CHILD=$PID
    TMPREF=$(mktemp)
    while true; do
        sleep "$POLL_INTERVAL"
        if ! kill -0 "$PID" 2>/dev/null; then
            echo "[dev] process died, restarting..."
            break
        fi
        CHANGED=$(find $WATCH_DIRS -newer "$TMPREF" \
            -not -name '*.swp' -not -name '*.swx' -not -name '.*~' \
            -type f 2>/dev/null | head -1)
        if [ -n "$CHANGED" ]; then
            echo "[dev] $CHANGED changed, restarting..."
            break
        fi
    done
    rm -f "$TMPREF"
    kill $PID 2>/dev/null; wait $PID 2>/dev/null
done
