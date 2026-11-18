#!/bin/sh
# Container health check: asks nginx, on whichever port startup.sh chose.
port=$(cat /tmp/tally/port 2>/dev/null || echo 80)
exec curl -fsS -o /dev/null "http://127.0.0.1:${port}/health.php"
