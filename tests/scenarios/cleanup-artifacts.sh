#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
ARTIFACTS_ROOT="${ARTIFACTS_ROOT:-$ROOT_DIR/tests/artifacts}"
ARTIFACT_RUNS_TO_KEEP="${ARTIFACT_RUNS_TO_KEEP:-20}"

if ! [[ "$ARTIFACT_RUNS_TO_KEEP" =~ ^[0-9]+$ ]]; then
  echo "ARTIFACT_RUNS_TO_KEEP must be a non-negative integer" >&2
  exit 1
fi

[ -d "$ARTIFACTS_ROOT" ] || exit 0

index=0
while IFS= read -r directory; do
  index=$((index + 1))
  if [ "$index" -gt "$ARTIFACT_RUNS_TO_KEEP" ]; then
    rm -rf -- "$directory"
  fi
done < <(find "$ARTIFACTS_ROOT" -mindepth 1 -maxdepth 1 -type d -print | sort -r)
