#!/usr/bin/env bash
# Runs on the server, called by .github/workflows/deploy.yml after the code
# has been copied to /opt/tadawul-virtual. Safe to run again at any time.
#
#   PUBLIC_HOST=<ip or domain> bash deploy/server-deploy.sh
set -euo pipefail

cd "$(dirname "$0")/.."
ENV_FILE=deploy/.env
COMPOSE=(docker compose -f deploy/docker-compose.yml --env-file "$ENV_FILE")
export DEBIAN_FRONTEND=noninteractive

# A new server may still be running its own first-boot package updates,
# so wait for the package lock instead of failing.
if ! command -v docker >/dev/null || ! docker compose version >/dev/null 2>&1; then
  echo "==> Installing Docker"
  apt-get -o DPkg::Lock::Timeout=600 update -q
  apt-get -o DPkg::Lock::Timeout=600 install -y -q docker.io docker-compose-v2 docker-buildx
  systemctl enable --now docker
fi

# Image builds need more memory than a small server has free.
if [ "$(swapon --noheadings | wc -l)" -eq 0 ] && [ ! -e /swapfile ]; then
  echo "==> Adding 2 GB of swap"
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

# Secrets are generated once on the server and never leave it.
if [ ! -f "$ENV_FILE" ]; then
  echo "==> Creating $ENV_FILE"
  umask 077
  cat > "$ENV_FILE" <<ENV
POSTGRES_PASSWORD=$(openssl rand -hex 24)
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=http://${PUBLIC_HOST:-localhost}
HTTP_PORT=80
BOT_INTERVAL_MS=1000
ENV
fi

echo "==> Building and starting"
"${COMPOSE[@]}" up -d --build --remove-orphans

port=$(grep -E '^HTTP_PORT=' "$ENV_FILE" | cut -d= -f2)
port=${port:-80}
echo "==> Waiting for the site on port $port"
for _ in $(seq 1 30); do
  if curl -fsS -o /dev/null "http://localhost:$port/"; then
    "${COMPOSE[@]}" ps
    docker image prune -f >/dev/null
    echo "==> Site is up"
    exit 0
  fi
  sleep 5
done

echo "==> Site did not answer. Service state and recent logs:"
"${COMPOSE[@]}" ps -a
"${COMPOSE[@]}" logs --tail 60
exit 1
