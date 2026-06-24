#!/bin/sh

WATCH_DIRS="${WATCH_DIRS:-/app/src /app/config /app/bin}"
POLL_INTERVAL="${POLL_INTERVAL:-1}"
CMD="$@"

if [ -z "$CMD" ]; then
    echo "Usage: bin/dev.sh <command>"
    echo "Example: bin/dev.sh php bin/server-hub.php"
    exit 1
fi

echo "=== Dev watcher started ==="
echo "Watching: $WATCH_DIRS"
echo "Poll:     ${POLL_INTERVAL}s"
echo "Command:  $CMD"
echo ""

while true; do
    composer install --quiet 2>/dev/null
    $CMD &
    PID=$!
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
