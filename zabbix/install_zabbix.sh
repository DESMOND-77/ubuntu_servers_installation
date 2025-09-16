#!/usr/bin/env bash
set -euo pipefail

echo "=== Installation automatisée de Zabbix (Ubuntu 24.04) ==="

# Vérification d'exécution en root
if [ "$EUID" -ne 0 ]; then
    echo "Ce script doit être exécuté en root. Relancez avec sudo : sudo $0"
    exit 1
fi

# Lecture du nom d'hôte (pour URL de l'interface web)
read -rp "Nom d'hôte (domaine ou IP) pour l'interface web [localhost] : " WEB_HOST
if [ -z "$WEB_HOST" ]; then
    WEB_HOST="localhost"
fi

# Lecture du mot de passe root MySQL (masqué)
while true; do
    read -rs -p "Mot de passe MySQL root : " MYSQL_ROOT_PW
    echo
    if [ -n "$MYSQL_ROOT_PW" ]; then break; fi
    echo "Erreur : le mot de passe ne peut pas être vide."
done

# Lecture du mot de passe pour l'utilisateur MySQL 'zabbix' (avec confirmation)
while true; do
    read -rs -p "Mot de passe à créer pour l'utilisateur MySQL 'zabbix' : " ZABBIX_DB_PW
    echo
    read -rs -p "Confirmez ce mot de passe : " ZABBIX_DB_PW2
    echo
    if [ "$ZABBIX_DB_PW" = "$ZABBIX_DB_PW2" ] && [ -n "$ZABBIX_DB_PW" ]; then
        break
    fi
    echo "Les mots de passe ne correspondent pas ou sont vides. Réessayez."
done

echo
echo "1) Mise à jour du système et installation des paquets requis..."
export DEBIAN_FRONTEND=noninteractive
# Installation de la librairie MySQL client (doit etre dans le meme repertoire que le script)
sudo dpkg -i libmysqlclient21_8.0.28-0ubuntu4_amd64.deb
# Mise à jour des paquets existants

apt update -y
apt upgrade -y

# Installation des paquets essentiels (Apache, PHP, etc.)
apt install -y apache2 libapache2-mod-php \
    php php-cli php-cgi php-mbstring php-gd php-xml php-mysql php-bcmath php-imap php-snmp php-curl \
    wget curl gnupg lsb-release apt-transport-https software-properties-common

# Installer MySQL/MariaDB server si absent
if ! command -v mysql >/dev/null 2>&1; then
    echo "Installation du serveur MySQL/MariaDB..."
    apt install -y mysql-server mysql-client
fi

echo
echo "2) Ajout du dépôt Zabbix officiel et de sa clé..."
# Suppression d'éventuels anciens fichiers source Zabbix
rm -f /etc/apt/sources.list.d/zabbix.list
rm -f /etc/apt/sources.list.d/zabbix*.list
rm -f /etc/apt/sources.list.d/zabbix*.sources

# Téléchargement et installation du package zabbix-release
ZABBIX_DEB="zabbix-release_latest_7.4+ubuntu24.04_all.deb"
wget -q "https://repo.zabbix.com/zabbix/7.4/release/ubuntu/pool/main/z/zabbix-release/$ZABBIX_DEB"
dpkg -i "$ZABBIX_DEB" || true

# Création du keyring et ajout de la clé GPG pour [signed-by]
mkdir -p /etc/apt/keyrings
curl -fsSL https://repo.zabbix.com/zabbix-official-repo.key | gpg --dearmor | tee /etc/apt/keyrings/zabbix.gpg > /dev/null

# Ajuster les fichiers sources pour utiliser [signed-by=/etc/apt/keyrings/zabbix.gpg]
for f in /etc/apt/sources.list.d/zabbix*; do
    [ -e "$f" ] || continue
    if [[ "$f" == *.sources ]]; then
        sed -i "s|signed-by=.*|signed-by=/etc/apt/keyrings/zabbix.gpg|g" "$f"
    else
        sed -i "s|^deb |deb [signed-by=/etc/apt/keyrings/zabbix.gpg] |g" "$f"
    fi
done

echo
echo "3) Mise à jour des index APT et installation des paquets Zabbix..."
apt update -y
apt install -y zabbix-server-mysql zabbix-frontend-php zabbix-apache-conf zabbix-agent zabbix-sql-scripts

echo
echo "4) Configuration de la base de données Zabbix..."
# Test de connexion MySQL en root avec le mot de passe fourni
if mysql -uroot -p"${MYSQL_ROOT_PW}" -e "quit" >/dev/null 2>&1; then
    MYSQL_CMD="mysql -uroot -p${MYSQL_ROOT_PW}"
else
    echo "Connexion MySQL root par mot de passe échouée. Essai via auth_socket..."
    if sudo mysql -e "quit" >/dev/null 2>&1; then
        echo "Connexion via sudo mysql OK. Définition du mot de passe root fourni."
        sudo mysql <<SQL
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PW}';
FLUSH PRIVILEGES;
SQL
        MYSQL_CMD="mysql -uroot -p${MYSQL_ROOT_PW}"
    else
        echo "Impossible de se connecter à MySQL. Interrompu."
        exit 1
    fi
fi

# Création de la DB et de l'utilisateur Zabbix
$MYSQL_CMD <<SQL
CREATE DATABASE IF NOT EXISTS zabbix CHARACTER SET utf8mb4 COLLATE utf8mb4_bin;
CREATE USER IF NOT EXISTS 'zabbix'@'localhost' IDENTIFIED BY '${ZABBIX_DB_PW}';
GRANT ALL PRIVILEGES ON zabbix.* TO 'zabbix'@'localhost';
SET GLOBAL log_bin_trust_function_creators = 1;
FLUSH PRIVILEGES;
SQL

echo
echo "5) Import du schéma initial de Zabbix (server.sql.gz)..."
if [ -f /usr/share/zabbix-sql-scripts/mysql/server.sql.gz ]; then
    zcat /usr/share/zabbix-sql-scripts/mysql/server.sql.gz | mysql --default-character-set=utf8mb4 -uzabbix -p"${ZABBIX_DB_PW}" zabbix
elif [ -f /usr/share/zabbix/sql-scripts/mysql/server.sql.gz ]; then
    zcat /usr/share/zabbix/sql-scripts/mysql/server.sql.gz | mysql --default-character-set=utf8mb4 -uzabbix -p"${ZABBIX_DB_PW}" zabbix
else
    echo "Fichier SQL d'initialisation introuvable. Vérifiez l'installation du paquet zabbix-sql-scripts."
    exit 1
fi
# Réinitialiser log_bin_trust_function_creators (recommandé)
$MYSQL_CMD -e "SET GLOBAL log_bin_trust_function_creators = 0;"

echo
echo "6) Configuration du mot de passe DB dans /etc/zabbix/zabbix_server.conf..."
CONF_FILE="/etc/zabbix/zabbix_server.conf"
if [ -f "$CONF_FILE" ]; then
    esc_pwd=$(printf '%s' "$ZABBIX_DB_PW" | sed -e 's/[\\/&]/\\\\&/g')
    if grep -q "^DBPassword=" "$CONF_FILE"; then
        sed -i "s/^DBPassword=.*/DBPassword=${esc_pwd}/" "$CONF_FILE"
    else
        if grep -q "^#DBPassword" "$CONF_FILE"; then
            sed -i "s/^#DBPassword=.*/DBPassword=${esc_pwd}/" "$CONF_FILE"
        else
            echo "DBPassword=${esc_pwd}" >> "$CONF_FILE"
        fi
    fi
else
    echo "Fichier $CONF_FILE introuvable. Arrêt."
    exit 1
fi

echo
echo "7) Redémarrage et activation des services Zabbix..."
systemctl restart zabbix-server zabbix-agent apache2 || true
systemctl enable zabbix-server zabbix-agent apache2 || true

echo
echo "Installation terminée ! Accédez à l'interface web : http://${WEB_HOST}/zabbix"
echo "Identifiants par défaut : Admin / zabbix (pensez à les changer)."
echo "Détails MySQL : base=zabbix, utilisateur=zabbix, mot de passe=${ZABBIX_DB_PW}"
echo
echo "Merci d'avoir utilisé ce script !"
