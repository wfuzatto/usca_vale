#!/usr/bin/env bash
set -euo pipefail
ROOT="${1:-/var/www/html/usca_vale}"
PHP="$(command -v php || true)"
[[ -n "$PHP" && -f "$ROOT/config.php" ]] || { echo 'Primeiro instale via navegador e garanta PHP CLI.' >&2; exit 1; }
cat >/etc/systemd/system/usca-vale.service <<EOF
[Unit]
Description=USCA Vale Modbus TCP collector (read-only)
After=network-online.target mariadb.service
Wants=network-online.target
[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=$ROOT
ExecStart=$PHP $ROOT/bin/collect.php
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ReadWritePaths=$ROOT/backups /tmp
[Install]
WantedBy=multi-user.target
EOF
cat >/etc/systemd/system/usca-vale-backup.service <<EOF
[Unit]
Description=USCA Vale daily MySQL backup
[Service]
Type=oneshot
User=www-data
Group=www-data
WorkingDirectory=$ROOT
ExecStart=$PHP $ROOT/bin/backup.php
EOF
cat >/etc/systemd/system/usca-vale-backup.timer <<'EOF'
[Unit]
Description=USCA Vale 02:10 UTC daily backup
[Timer]
OnCalendar=*-*-* 02:10:00 UTC
Persistent=true
[Install]
WantedBy=timers.target
EOF
mkdir -p "$ROOT/backups"
chown www-data:www-data "$ROOT/backups"
systemctl daemon-reload
systemctl enable --now usca-vale.service usca-vale-backup.timer
systemctl --no-pager status usca-vale.service || true
echo "Configuração concluída. Veja: journalctl -u usca-vale.service -f"
