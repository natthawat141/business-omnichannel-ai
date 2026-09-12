#!/usr/bin/env bash
set -euo pipefail

# Runs only on the VM. It creates a non-versioned deployment env file with
# random secrets. OPENROUTER_API_KEY is injected separately into runtime/
# through the cloud secret pipe; this script never prints a secret.
umask 077
root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root_dir"

if [[ -f .env ]]; then
  exit 0
fi

required=(CHATWOOT_HOSTNAME MANAGEMENT_HOSTNAME AI_HOSTNAME)
for name in "${required[@]}"; do
  if [[ -z "${!name:-}" ]]; then
    echo "missing required deployment setting: $name" >&2
    exit 1
  fi
done

secret() { openssl rand -hex 32; }
app_key="base64:$(openssl rand -base64 32 | tr -d '\n')"
ai_token="lk_ai.$(openssl rand -hex 32)"
admin_password="$(openssl rand -base64 24 | tr -d '\n')"
chatwoot_db_password="$(secret)"
chatwoot_redis_password="$(secret)"
management_db_password="$(secret)"
management_root_password="$(secret)"
mkdir -p runtime

cat > .env <<EOF
CHATWOOT_VERSION=v4.16.2-ce
CHATWOOT_HOSTNAME=${CHATWOOT_HOSTNAME}
MANAGEMENT_HOSTNAME=${MANAGEMENT_HOSTNAME}
AI_HOSTNAME=${AI_HOSTNAME}
FRONTEND_URL=https://${CHATWOOT_HOSTNAME}
SECRET_KEY_BASE=$(secret)
RAILS_ENV=production
NODE_ENV=production
INSTALLATION_ENV=docker
POSTGRES_HOST=chatwoot-postgres
POSTGRES_PORT=5432
POSTGRES_DATABASE=chatwoot
POSTGRES_USERNAME=chatwoot
POSTGRES_PASSWORD=${chatwoot_db_password}
CHATWOOT_POSTGRES_DATABASE=chatwoot
CHATWOOT_POSTGRES_USERNAME=chatwoot
CHATWOOT_POSTGRES_PASSWORD=${chatwoot_db_password}
REDIS_URL=redis://:${chatwoot_redis_password}@chatwoot-redis:6379
CHATWOOT_REDIS_PASSWORD=${chatwoot_redis_password}
DEFAULT_LOCALE=th
ACTIVE_STORAGE_SERVICE=local
ENABLE_ACCOUNT_SIGNUP=false
MAILER_SENDER_EMAIL=Chatwoot <no-reply@localhost>
MANAGEMENT_DB_DATABASE=management
MANAGEMENT_DB_USERNAME=management
MANAGEMENT_DB_PASSWORD=${management_db_password}
MANAGEMENT_DB_ROOT_PASSWORD=${management_root_password}
APP_NAME=Business Management
APP_ENV=production
APP_KEY=${app_key}
APP_URL=https://${MANAGEMENT_HOSTNAME}
LOG_CHANNEL=stderr
LOG_LEVEL=warning
ADMIN_NAME=Bill Natthawat
ADMIN_EMAIL=bill.natthawat@gmail.com
ADMIN_PASSWORD=${admin_password}
AI_SERVICE_TOKEN=${ai_token}
SEED_DEMO_DATA=false
CHATWOOT_ACCOUNT_ID=
CHATWOOT_HANDOFF_TEAM_ID=
CHATWOOT_BOT_ACCESS_TOKEN=
CHATWOOT_WEBHOOK_TOKEN=$(secret)
CHATWOOT_ALLOWED_INBOX_IDS=
OPENROUTER_MODEL=openai/gpt-5.6-luna
EOF

chmod 600 .env
echo "deployment environment created"
