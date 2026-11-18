#!/bin/sh

set -euo pipefail

APP_DIR=/var/www/html
# Everything the container writes at runtime, apart from the two volumes
# (db/ and images/uploads/logos/), goes under /tmp, so the root filesystem
# can be mounted read-only. See "Hardened containers" in the README.
RUNTIME_DIR=/tmp/tally
NGINX_RUNTIME_DIR="$RUNTIME_DIR/nginx"

log() {
  echo "[startup] $*"
}

CURRENT_UID=$(id -u)

if [ "$CURRENT_UID" -eq 0 ]; then
  ROOTLESS=0
  PORT=${PORT:-80}
else
  ROOTLESS=1
  # Unprivileged processes can't bind ports below 1024.
  PORT=${PORT:-8080}
fi

case "$PORT" in
  ''|*[!0-9]*)
    log "PORT must be a number, got '$PORT'"
    exit 1
    ;;
esac

mkdir -p "$NGINX_RUNTIME_DIR/client_body" "$NGINX_RUNTIME_DIR/proxy" \
  "$NGINX_RUNTIME_DIR/fastcgi" "$NGINX_RUNTIME_DIR/uwsgi" "$NGINX_RUNTIME_DIR/scgi"
# The health check reads the port from here.
echo "$PORT" > "$RUNTIME_DIR/port"

# Render the nginx config for this port. Listen on IPv6 and IPv4 together,
# unless the kernel has IPv6 turned off.
if [ -e /proc/net/if_inet6 ]; then
  LISTEN="[::]:$PORT ipv6only=off"
else
  LISTEN="$PORT"
fi
sed "s/__LISTEN__/$LISTEN/g" /etc/nginx/nginx.conf.template > "$NGINX_RUNTIME_DIR/nginx.conf"

if [ "$ROOTLESS" -eq 0 ]; then
  # Root mode (the default): run the web app as www-data with the PUID/PGID
  # the user asked for, and hand it the data folders.
  PUID=${PUID:-82}
  PGID=${PGID:-82}
  log "Running as root; app user www-data gets uid $PUID, gid $PGID"

  # Only touch /etc/passwd and /etc/group when the ids change, so the
  # default ids also work with a read-only root filesystem.
  if [ "$(id -g www-data)" != "$PGID" ]; then
    groupmod -o -g "$PGID" www-data
  fi
  if [ "$(id -u www-data)" != "$PUID" ]; then
    usermod -o -u "$PUID" www-data
  fi
  mkdir -p "$APP_DIR/db" "$APP_DIR/images/uploads/logos/avatars"
  chown -R www-data:www-data "$APP_DIR/db" "$APP_DIR/images/uploads/logos"
  # nginx's own workers run as the nginx user and need its temp folders.
  chown -R nginx:nginx "$NGINX_RUNTIME_DIR/client_body" "$NGINX_RUNTIME_DIR/proxy" \
    "$NGINX_RUNTIME_DIR/fastcgi" "$NGINX_RUNTIME_DIR/uwsgi" "$NGINX_RUNTIME_DIR/scgi"
else
  # Rootless mode (docker run --user / compose "user:"): nothing can be
  # chowned, so the volumes must already belong to this uid.
  log "Running rootless as uid $CURRENT_UID, gid $(id -g); nginx listens on $PORT"

  mkdir -p "$APP_DIR/images/uploads/logos/avatars" 2>/dev/null || true
  for dir in "$APP_DIR/db" "$APP_DIR/images/uploads/logos" "$APP_DIR/images/uploads/logos/avatars"; do
    if [ ! -d "$dir" ] || [ ! -w "$dir" ]; then
      log "ERROR: $dir is not writable by uid $CURRENT_UID."
      log "Give the volume to this user on the host, for example:"
      log "  chown -R $CURRENT_UID:$(id -g) ./db ./logos"
      exit 1
    fi
  done
fi

# PIDs we track
PHP_FPM_PID=
NGINX_PID=
CRON_PID=
shutdown_in_progress=0

shutdown_once() {
  exit_signal=$?
  kill_signal=$(kill -l "$exit_signal" 2>/dev/null || echo "$exit_signal")

  [ "$shutdown_in_progress" -eq 1 ] && return 0
  shutdown_in_progress=1

  log "Got signal: $kill_signal - shutting down gracefully..."
  # nginx wants QUIT for graceful
  nginx -e stderr -c "$NGINX_RUNTIME_DIR/nginx.conf" -s quit || true
  # php-fpm graceful quit as well
  [ -n "${PHP_FPM_PID}" ] && kill -QUIT "${PHP_FPM_PID}" 2>/dev/null || true
  # cron can just get TERM
  [ -n "${CRON_PID}" ] && kill -TERM "${CRON_PID}" 2>/dev/null || true
  log "Graceful shutdown complete."
}

# Handle all common stop signals
trap 'shutdown_once' SIGTERM SIGINT SIGQUIT

# Runs a command as the app user: www-data in root mode, ourselves when rootless.
as_app_user() {
  if [ "$ROOTLESS" -eq 0 ]; then
    su-exec www-data "$@"
  else
    "$@"
  fi
}

# Create the database if it does not exist, and run any migrations, before
# anything starts serving requests.
as_app_user /usr/local/bin/php "$APP_DIR/endpoints/cronjobs/createdatabase.php"
as_app_user /usr/local/bin/php "$APP_DIR/endpoints/db/migrate.php"

log "Launching php-fpm"
php-fpm -F &
PHP_FPM_PID=$!

log "Launching supercronic"
# supercronic runs the jobs in /etc/tally/crontab and logs their output to
# stdout, so `docker logs` shows them. It runs as the app user in both modes.
as_app_user supercronic /etc/tally/crontab &
CRON_PID=$!

log "Launching nginx"
nginx -e stderr -c "$NGINX_RUNTIME_DIR/nginx.conf" -g 'daemon off;' &
NGINX_PID=$!

# Catch up on renewals, exchange rates and the update check right away
# rather than waiting for their first scheduled run.
as_app_user /usr/local/bin/php "$APP_DIR/endpoints/cronjobs/updatenextpayment.php" || true
as_app_user /usr/local/bin/php "$APP_DIR/endpoints/cronjobs/updateexchange.php" || true
as_app_user /usr/local/bin/php "$APP_DIR/endpoints/cronjobs/checkforupdates.php" || true

# Essentially wait until all child processes exit
wait
