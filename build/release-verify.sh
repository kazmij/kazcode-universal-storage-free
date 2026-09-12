#!/usr/bin/env bash
# Release-integrity verifier wrapper. Keep release correctness logic in
# release-integrity.php so local runs and CI execute the same checks.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

exec php "${ROOT}/build/release-integrity.php" "$@"
