#!/usr/bin/env bash
# ==============================================================================
# install_zabbix.sh — Installation automatisée de Zabbix (Ubuntu 22.04/24.04)
# Version : 2.1
# ==============================================================================
set -euo pipefail

# ------------------------------------------------------------------------------
# JOURNALISATION
# ------------------------------------------------------------------------------
LOG_FILE="/var/log/zabbix_install.log"
exec > >(tee -a "$LOG_FILE") 2>&1
echo "=== Début de l'installation : $(date '+%Y-%m-%d %H:%M:%S') ==="

# ------------------------------------------------------------------------------
# NETTOYAGE SÉCURISÉ DES FICHIERS TEMPORAIRES
# ------------------------------------------------------------------------------
MYSQL_CONF=""
cleanup() {
    [ -n "$MYSQL_CONF" ] && [ -f "$MYSQL_CONF" ] && rm -f "$MYSQL_CONF"
}
trap cleanup EXIT INT TERM

# ------------------------------------------------------------------------------
# PURGE PRÉVENTIVE DES SOURCES ZABBIX
# Exécutée en tout premier lieu, avant tout appel à apt-get update,
# afin d'éliminer tout fichier corrompu ou en conflit hérité d'une
# ------------------------------------------------------------------------------
purge_zabbix_sources() {
    rm -f /etc/apt/sources.list.d/zabbix*.list \
          /etc/apt/sources.list.d/zabbix*.sources \
          /etc/apt/keyrings/zabbix.gpg \
          /etc/apt/trusted.gpg.d/zabbix*.gpg \
          /etc/apt/trusted.gpg.d/zabbix*.gpg~
}
purge_zabbix_sources

# ------------------------------------------------------------------------------
# 0) VÉRIFICATIONS PRÉALABLES
# ------------------------------------------------------------------------------
echo
echo "0) Vérification des prérequis..."

if [ "$EUID" -ne 0 ]; then
    echo "ERREUR : ce script doit être exécuté en root. Relancez avec : sudo $0"
    exit 1
fi

if ! command -v lsb_release >/dev/null 2>&1; then
    apt-get install -y lsb-release >/dev/null 2>&1
fi

DISTRO_ID=$(lsb_release -is | tr '[:upper:]' '[:lower:]')
DISTRO_CODENAME=$(lsb_release -cs)
DISTRO_RELEASE=$(lsb_release -rs)

if [ "$DISTRO_ID" != "ubuntu" ]; then
    echo "ERREUR : distribution non supportée ($DISTRO_ID). Ce script est conçu pour Ubuntu."
    exit 1
fi

case "$DISTRO_CODENAME" in
    noble) UBUNTU_VERSION="24.04" ;;
    jammy) UBUNTU_VERSION="22.04" ;;
    *)
        echo "ERREUR : version Ubuntu non supportée ($DISTRO_CODENAME / $DISTRO_RELEASE)."
        echo "Versions supportées : Ubuntu 22.04 (Jammy), Ubuntu 24.04 (Noble)."
        exit 1
        ;;
esac
echo "Système détecté : Ubuntu $UBUNTU_VERSION ($DISTRO_CODENAME) — OK"

FREE_KB=$(df / --output=avail | tail -1)
FREE_GB=$(( FREE_KB / 1024 / 1024 ))
if [ "$FREE_GB" -lt 2 ]; then
    echo "AVERTISSEMENT : espace disque faible (${FREE_GB} Go). Au moins 2 Go sont recommandés."
fi

ZABBIX_VERSION="7.4"

# ------------------------------------------------------------------------------
# SAISIE DES PARAMÈTRES
# ------------------------------------------------------------------------------
echo
echo "=== Configuration de l'installation ==="

read -rp "Nom d'hôte ou IP pour l'interface web [localhost] : " WEB_HOST
WEB_HOST="${WEB_HOST:-localhost}"

while true; do
    read -rs -p "Mot de passe MySQL root : " MYSQL_ROOT_PW; echo
    [ -n "$MYSQL_ROOT_PW" ] && break
    echo "ERREUR : le mot de passe ne peut pas être vide."
done

while true; do
    read -rs -p "Mot de passe pour l'utilisateur MySQL 'zabbix' : " ZABBIX_DB_PW; echo
    read -rs -p "Confirmation du mot de passe : " ZABBIX_DB_PW2; echo
    if [ "$ZABBIX_DB_PW" = "$ZABBIX_DB_PW2" ] && [ -n "$ZABBIX_DB_PW" ]; then
        break
    fi
    echo "ERREUR : les mots de passe ne correspondent pas ou sont vides. Réessayez."
done

# ------------------------------------------------------------------------------
# FONCTIONS UTILITAIRES
# ------------------------------------------------------------------------------
mysql_exec() {
    local user="$1" password="$2"
    shift 2
    MYSQL_CONF=$(mktemp /tmp/.my_cnf_XXXXXX)
    chmod 600 "$MYSQL_CONF"
    printf '[client]\nuser=%s\npassword=%s\n' "$user" "$password" > "$MYSQL_CONF"
    mysql --defaults-file="$MYSQL_CONF" "$@"
    local rc=$?
    rm -f "$MYSQL_CONF"; MYSQL_CONF=""
    return $rc
}

check_service() {
    local svc="$1"
    if systemctl is-active --quiet "$svc"; then
        echo "  [OK] $svc est actif."
    else
        echo "  [ERREUR] $svc n'a pas démarré. Consultez : journalctl -u $svc --no-pager -n 20"
        return 1
    fi
}

# ------------------------------------------------------------------------------
# 1) MISE À JOUR SYSTÈME ET PAQUETS REQUIS
# ------------------------------------------------------------------------------
echo
echo "1) Mise à jour du système et installation des paquets requis..."
export DEBIAN_FRONTEND=noninteractive

apt-get update -y
apt-get upgrade -y

apt-get install -y \
    apache2 libapache2-mod-php \
    php php-cli php-cgi php-mbstring php-gd php-xml php-mysql \
    php-bcmath php-imap php-snmp php-curl \
    wget curl gnupg lsb-release apt-transport-https \
    software-properties-common

if ! command -v mysql >/dev/null 2>&1; then
    echo "  MySQL introuvable — installation en cours..."
    apt-get install -y mysql-server mysql-client
fi

# ------------------------------------------------------------------------------
# 2) AJOUT DU DÉPÔT ZABBIX OFFICIEL
# ------------------------------------------------------------------------------
echo
echo "2) Ajout du dépôt Zabbix officiel (version ${ZABBIX_VERSION}, Ubuntu ${UBUNTU_VERSION})..."

ZABBIX_DEB="zabbix-release_latest_${ZABBIX_VERSION}+ubuntu${UBUNTU_VERSION}_all.deb"
ZABBIX_DEB_URL="https://repo.zabbix.com/zabbix/${ZABBIX_VERSION}/release/ubuntu/pool/main/z/zabbix-release/${ZABBIX_DEB}"

echo "  Téléchargement : $ZABBIX_DEB_URL"
if ! wget -q -O "/tmp/${ZABBIX_DEB}" "$ZABBIX_DEB_URL"; then
    echo "ERREUR : impossible de télécharger le paquet Zabbix. Vérifiez la connectivité réseau."
    exit 1
fi

# Installation du .deb uniquement pour satisfaire les dépendances éventuelles,
# puis suppression immédiate de TOUS les fichiers sources qu'il a générés.
# On crée ensuite nos propres entrées, propres et maîtrisées, pour éviter
# tout conflit de clé signed-by ou toute URI mal formée.
dpkg -i "/tmp/${ZABBIX_DEB}"
rm -f "/tmp/${ZABBIX_DEB}"
purge_zabbix_sources

# Importation de la clé GPG dans le keyring dédié
mkdir -p /etc/apt/keyrings
if ! curl -fsSL https://repo.zabbix.com/zabbix-official-repo.key \
        | gpg --dearmor -o /etc/apt/keyrings/zabbix.gpg; then
    echo "ERREUR : impossible d'importer la clé GPG Zabbix."
    exit 1
fi
chmod 644 /etc/apt/keyrings/zabbix.gpg

# Création manuelle des fichiers sources avec signed-by explicite
cat > /etc/apt/sources.list.d/zabbix.list <<EOF
deb [signed-by=/etc/apt/keyrings/zabbix.gpg] https://repo.zabbix.com/zabbix/${ZABBIX_VERSION}/stable/ubuntu ${DISTRO_CODENAME} main
deb-src [signed-by=/etc/apt/keyrings/zabbix.gpg] https://repo.zabbix.com/zabbix/${ZABBIX_VERSION}/stable/ubuntu ${DISTRO_CODENAME} main
EOF

echo "  Fichiers sources Zabbix créés."

# ------------------------------------------------------------------------------
# 3) INSTALLATION DES PAQUETS ZABBIX
# ------------------------------------------------------------------------------
echo
echo "3) Installation des paquets Zabbix..."
apt-get update -y
apt-get install -y \
    zabbix-server-mysql \
    zabbix-frontend-php \
    zabbix-apache-conf \
    zabbix-agent \
    zabbix-sql-scripts

# ------------------------------------------------------------------------------
# 4) CONFIGURATION DE LA BASE DE DONNÉES
# ------------------------------------------------------------------------------
echo
echo "4) Configuration de la base de données Zabbix..."

AUTH_OK=false

MYSQL_CONF=$(mktemp /tmp/.my_cnf_XXXXXX)
chmod 600 "$MYSQL_CONF"
printf '[client]\nuser=root\npassword=%s\n' "${MYSQL_ROOT_PW}" > "$MYSQL_CONF"

if mysqladmin --defaults-file="$MYSQL_CONF" ping --silent >/dev/null 2>&1; then
    echo "  Connexion MySQL root par mot de passe : OK"
    AUTH_OK=true
fi
rm -f "$MYSQL_CONF"; MYSQL_CONF=""

if [ "$AUTH_OK" = false ]; then
    echo "  Connexion par mot de passe échouée. Tentative via auth_socket..."
    if sudo mysql -e "quit" >/dev/null 2>&1; then
        echo "  Connexion via auth_socket : OK. Application du mot de passe root..."
        sudo mysql <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PW}';
FLUSH PRIVILEGES;
SQL
        MYSQL_CONF=$(mktemp /tmp/.my_cnf_XXXXXX)
        chmod 600 "$MYSQL_CONF"
        printf '[client]\nuser=root\npassword=%s\n' "${MYSQL_ROOT_PW}" > "$MYSQL_CONF"
        if mysqladmin --defaults-file="$MYSQL_CONF" ping --silent >/dev/null 2>&1; then
            AUTH_OK=true
        fi
        rm -f "$MYSQL_CONF"; MYSQL_CONF=""
    fi
fi

if [ "$AUTH_OK" = false ]; then
    echo "ERREUR : impossible de se connecter à MySQL avec les identifiants fournis."
    exit 1
fi

mysql_exec "root" "${MYSQL_ROOT_PW}" <<SQL
CREATE DATABASE IF NOT EXISTS zabbix CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER IF NOT EXISTS 'zabbix'@'localhost' IDENTIFIED BY '${ZABBIX_DB_PW}';
GRANT ALL PRIVILEGES ON zabbix.* TO 'zabbix'@'localhost';
SET GLOBAL log_bin_trust_function_creators = 1;
FLUSH PRIVILEGES;
SQL

# ------------------------------------------------------------------------------
# 5) IMPORT DU SCHÉMA SQL (idempotent)
# ------------------------------------------------------------------------------
echo
echo "5) Import du schéma initial Zabbix..."

SQL_FILE=""
for candidate in \
    /usr/share/zabbix-sql-scripts/mysql/server.sql.gz \
    /usr/share/zabbix/sql-scripts/mysql/server.sql.gz; do
    if [ -f "$candidate" ]; then
        SQL_FILE="$candidate"
        break
    fi
done

if [ -z "$SQL_FILE" ]; then
    echo "ERREUR : fichier SQL d'initialisation introuvable. Vérifiez le paquet zabbix-sql-scripts."
    exit 1
fi

USER_COUNT=$(mysql_exec "zabbix" "${ZABBIX_DB_PW}" zabbix \
    -sN -e "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema='zabbix' AND table_name='users';" 2>/dev/null || echo "0")

if [ "$USER_COUNT" -gt 0 ]; then
    echo "  Base de données déjà initialisée — import ignoré."
else
    echo "  Import depuis : $SQL_FILE"
    MYSQL_CONF=$(mktemp /tmp/.my_cnf_XXXXXX)
    chmod 600 "$MYSQL_CONF"
    printf '[client]\nuser=zabbix\npassword=%s\n' "${ZABBIX_DB_PW}" > "$MYSQL_CONF"

    if ! zcat "$SQL_FILE" | mysql --defaults-file="$MYSQL_CONF" \
            --default-character-set=utf8mb4 zabbix; then
        rm -f "$MYSQL_CONF"; MYSQL_CONF=""
        echo "ERREUR : l'import du schéma SQL a échoué."
        exit 1
    fi
    rm -f "$MYSQL_CONF"; MYSQL_CONF=""
    echo "  Import SQL terminé avec succès."
fi

mysql_exec "root" "${MYSQL_ROOT_PW}" \
    -e "SET GLOBAL log_bin_trust_function_creators = 0;" >/dev/null

# ------------------------------------------------------------------------------
# 6) CONFIGURATION DE zabbix_server.conf
# ------------------------------------------------------------------------------
echo
echo "6) Configuration de /etc/zabbix/zabbix_server.conf..."

CONF_FILE="/etc/zabbix/zabbix_server.conf"
[ -f "$CONF_FILE" ] || { echo "ERREUR : fichier $CONF_FILE introuvable."; exit 1; }

# Utilisation d'un fichier temporaire pour éviter tout problème
# d'échappement avec des mots de passe contenant des caractères spéciaux.
TMP_CONF=$(mktemp)
cp "$CONF_FILE" "$TMP_CONF"

if grep -q "^DBPassword=" "$TMP_CONF"; then
    # Remplacement de la ligne existante via Python pour éviter les problèmes sed
    python3 -c "
import sys
pw = sys.argv[1]
lines = open('$TMP_CONF').readlines()
lines = ['DBPassword=' + pw + '\n' if l.startswith('DBPassword=') else l for l in lines]
open('$TMP_CONF', 'w').writelines(lines)
" "${ZABBIX_DB_PW}"
elif grep -qE "^#\s*DBPassword=" "$TMP_CONF"; then
    python3 -c "
import sys, re
pw = sys.argv[1]
lines = open('$TMP_CONF').readlines()
lines = [re.sub(r'^#\s*DBPassword=.*', 'DBPassword=' + pw, l) for l in lines]
open('$TMP_CONF', 'w').writelines(lines)
" "${ZABBIX_DB_PW}"
else
    echo "DBPassword=${ZABBIX_DB_PW}" >> "$TMP_CONF"
fi

cp "$TMP_CONF" "$CONF_FILE"
rm -f "$TMP_CONF"
echo "  DBPassword configuré dans $CONF_FILE."

# ------------------------------------------------------------------------------
# 7) DÉMARRAGE ET VÉRIFICATION DES SERVICES
# ------------------------------------------------------------------------------
echo
echo "7) Activation et démarrage des services..."

for svc in zabbix-server zabbix-agent apache2; do
    systemctl enable "$svc"
    systemctl restart "$svc"
done

echo "  Vérification de l'état des services :"
SERVICES_OK=true
for svc in zabbix-server zabbix-agent apache2; do
    check_service "$svc" || SERVICES_OK=false
done

# ------------------------------------------------------------------------------
# RÉSUMÉ FINAL
# ------------------------------------------------------------------------------
echo
echo "======================================================================"
if [ "$SERVICES_OK" = true ]; then
    echo "Installation terminée avec succès — $(date '+%Y-%m-%d %H:%M:%S')"
else
    echo "Installation terminée avec des avertissements. Vérifiez les services en erreur."
fi
echo "----------------------------------------------------------------------"
echo "Interface web    : http://${WEB_HOST}/zabbix"
echo "Identifiants     : Admin / zabbix  (à modifier immédiatement)"
echo "Base de données  : zabbix  |  Utilisateur MySQL : zabbix"
echo "Journal complet  : $LOG_FILE"
echo "======================================================================"