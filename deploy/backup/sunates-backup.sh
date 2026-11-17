#!/usr/bin/env bash
# Nightly MySQL backup for SUN-ATES (spec section 9: scheduled MySQL backups).
#
# - Dumps the main database (and the verification-log database if VERIFICATION_DB is set, since spec
#   section 9 keeps those logs apart) with a consistent snapshot, no table locks.
# - Encrypts each dump with GPG for the ICT backup key: backups hold personal data.
# - Keeps 14 daily and 8 weekly copies, and copies the newest one off the server if OFFSITE is set.
#
# Install:  cp deploy/backup/sunates-backup.sh /usr/local/bin/ && chmod 750 /usr/local/bin/sunates-backup.sh
# Config:   /etc/sunates-backup.env (mode 600, root only), for example:
#             DB_NAME=sunates
#             VERIFICATION_DB=            # optional second database
#             GPG_RECIPIENT=ict-backups@soroti.ac.ug
#             BACKUP_DIR=/var/backups/sunates
#             OFFSITE=backup@backup-host:/srv/sunates/   # optional, rsync over SSH
#           MySQL credentials go in /root/.my.cnf ([mysqldump] user=..., password=...), never on the command line.
# Restore:  see deploy/README.md, "Restoring a backup". Test a restore on staging every quarter.

set -euo pipefail

# shellcheck disable=SC1091
source /etc/sunates-backup.env

: "${DB_NAME:?DB_NAME is required}"
: "${GPG_RECIPIENT:?GPG_RECIPIENT is required}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/sunates}"
STAMP="$(date +%Y%m%d-%H%M%S)"

umask 077
mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"

dump() {
    local db="$1"
    local out="$BACKUP_DIR/daily/${db}-${STAMP}.sql.gz.gpg"

    mysqldump --single-transaction --quick --routines --triggers --no-tablespaces "$db" \
        | gzip -9 \
        | gpg --batch --yes --encrypt --recipient "$GPG_RECIPIENT" --output "$out"

    # A backup that cannot be read back is not a backup.
    gpg --batch --list-packets "$out" > /dev/null
    echo "$(date -Is) backed up ${db} -> ${out} ($(du -h "$out" | cut -f1))"

    # Sunday's copy is also kept as a weekly.
    if [ "$(date +%u)" = "7" ]; then
        cp "$out" "$BACKUP_DIR/weekly/"
    fi
}

dump "$DB_NAME"
if [ -n "${VERIFICATION_DB:-}" ]; then
    dump "$VERIFICATION_DB"
fi

# Retention: 14 daily, 8 weekly.
find "$BACKUP_DIR/daily" -name '*.sql.gz.gpg' -mtime +14 -delete
find "$BACKUP_DIR/weekly" -name '*.sql.gz.gpg' -mtime +56 -delete

if [ -n "${OFFSITE:-}" ]; then
    rsync -a --chmod=F600 "$BACKUP_DIR/daily/" "$OFFSITE"
fi
