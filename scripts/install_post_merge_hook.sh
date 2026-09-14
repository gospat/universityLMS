#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
HOOK_PATH="${REPO_DIR}/.git/hooks/post-merge"

cat > "${HOOK_PATH}" <<'EOF'
#!/usr/bin/env bash

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"
"${REPO_DIR}/scripts/ulms_refresh_live.sh"
EOF

chmod +x "${HOOK_PATH}"

echo "Installed git post-merge hook at ${HOOK_PATH}"
