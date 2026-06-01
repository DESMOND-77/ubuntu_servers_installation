#!/bin/bash

# Script d'installation automatique LAMP + phpMyAdmin + VHost Manager
# Testé sur Debian/Ubuntu (compatible Ubuntu 26.04+)
# À exécuter avec les privilèges root

# ─── COULEURS ──────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

print_message() { echo -e "${GREEN}[INFO]${NC} $1"; }
print_error()   { echo -e "${RED}[ERREUR]${NC} $1"; }
print_warning() { echo -e "${YELLOW}[ATTENTION]${NC} $1"; }
print_step()    { echo -e "\n${BLUE}━━━ $1 ━━━${NC}"; }

# ─── VÉRIFICATION ROOT ─────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    print_error "Ce script doit être exécuté en tant que root (utilisez sudo)"
    exit 1
fi

# ─── VARIABLES CONFIGURABLES ───────────────────────────────────────────────
MYSQL_ROOT_PASSWORD=""
PHPMYADMIN_PASSWORD=""
SITE_NAME="000-default"
DOMAIN="localhost"
VHOST_HELPER_BIN="/usr/local/bin/vhost-helper"
VHOST_HELPER_SVC="/etc/systemd/system/vhost-helper.service"
VHOST_SOCKET="/run/vhost-manager.sock"

# ─── SAISIE DES MOTS DE PASSE ──────────────────────────────────────────────
if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    read -sp "Entrez le mot de passe root MySQL: " MYSQL_ROOT_PASSWORD
    echo
fi

if [ -z "$PHPMYADMIN_PASSWORD" ]; then
    read -sp "Entrez le mot de passe pour phpMyAdmin: " PHPMYADMIN_PASSWORD
    echo
fi

# ─── JOURNALISATION ────────────────────────────────────────────────────────
LOG_FILE="/var/log/lamp_installation_$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1

print_message "Début de l'installation - Journal: $LOG_FILE"

# ══════════════════════════════════════════════════════════════════════════
print_step "1/9 — Nettoyage et mise à jour du système"
# ══════════════════════════════════════════════════════════════════════════
apt-get purge mysql* apache2* -y
apt-get autoremove -y
rm -rf /etc/mysql /var/lib/mysql /var/log/mysql
deluser --remove-home mysql 2>/dev/null || true
delgroup mysql           2>/dev/null || true
apt-get update && apt-get upgrade -y

# ══════════════════════════════════════════════════════════════════════════
print_step "2/9 — Installation Apache + PHP + MySQL + Python3"
# ══════════════════════════════════════════════════════════════════════════
print_message "Installation d'Apache..."
apt-get install -y apache2 apache2-utils

print_message "Activation des modules Apache..."
a2enmod rewrite headers expires

print_message "Installation de MySQL..."
apt-get install -y mysql-server mysql-client

print_message "Installation de PHP et extensions..."
apt-get install -y \
    php libapache2-mod-php php-mysql php-cli \
    php-curl php-gd php-mbstring php-xml php-zip php-bcmath

print_message "Vérification de Python 3 (requis pour vhost-helper)..."
apt-get install -y python3

# ══════════════════════════════════════════════════════════════════════════
print_step "3/9 — Sécurisation de MySQL"
# ══════════════════════════════════════════════════════════════════════════
mysql --user=root <<_EOF_
ALTER USER 'root'@'localhost' IDENTIFIED WITH caching_sha2_password BY '${MYSQL_ROOT_PASSWORD}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
_EOF_

# ══════════════════════════════════════════════════════════════════════════
print_step "4/9 — Installation et configuration de phpMyAdmin"
# ══════════════════════════════════════════════════════════════════════════
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2"     | debconf-set-selections
echo "phpmyadmin phpmyadmin/dbconfig-install boolean true"                  | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password ${MYSQL_ROOT_PASSWORD}" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password ${PHPMYADMIN_PASSWORD}"   | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password ${PHPMYADMIN_PASSWORD}" | debconf-set-selections

apt-get install -y phpmyadmin

if [ -f /etc/phpmyadmin/config.inc.php ]; then
    cat >> /etc/phpmyadmin/config.inc.php << 'EOF'

// Sécurité supplémentaire
$cfg['Servers'][1]['AllowNoPassword'] = false;
$cfg['ForceSSL'] = false;
$cfg['LoginCookieValidity'] = 14400;
EOF
fi

# ══════════════════════════════════════════════════════════════════════════
print_step "5/9 — Configuration du VirtualHost Apache"
# ══════════════════════════════════════════════════════════════════════════
cat > /etc/apache2/sites-available/${SITE_NAME}.conf << EOF
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    ServerName ${DOMAIN}
    DocumentRoot /var/www/html

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # phpMyAdmin
    Alias /phpmyadmin /usr/share/phpmyadmin
    <Directory /usr/share/phpmyadmin>
        Options FollowSymLinks
        DirectoryIndex index.php
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

a2dissite 000-default.conf 2>/dev/null || true
a2ensite ${SITE_NAME}.conf

# ══════════════════════════════════════════════════════════════════════════
print_step "6/9 — Installation du daemon vhost-helper"
# (Remplace les appels sudo depuis PHP — compatible Ubuntu 26.04+)
# ══════════════════════════════════════════════════════════════════════════
print_message "Création du daemon Python3 vhost-helper..."

cat > "${VHOST_HELPER_BIN}" << 'PYEOF'
#!/usr/bin/env python3
"""
vhost-helper — Daemon racine pour VHost Manager
Socket Unix : /run/vhost-manager.sock (root:www-data 0660)
Protocole   : JSON, réponse JSON
"""
import grp, json, os, re, signal, socket, subprocess, sys

SOCK  = '/run/vhost-manager.sock'
HOSTS = '/etc/hosts'

CMDS = {
    'a2ensite':       ['/usr/sbin/a2ensite'],
    'a2dissite':      ['/usr/sbin/a2dissite'],
    'apache_reload':  ['/usr/sbin/apachectl', 'graceful'],
    'apache_restart': ['/usr/sbin/apachectl', 'restart'],
}

def valid_site(name: str) -> bool:
    return bool(re.fullmatch(r'[a-zA-Z0-9._\-]+\.conf', name or ''))

def handle(raw: bytes) -> dict:
    try:
        req     = json.loads(raw.decode('utf-8', errors='replace'))
        cmd     = req.get('cmd', '')
        args    = req.get('args', [])
        content = req.get('content', '')

        if cmd == 'write_hosts':
            if 'localhost' not in content:
                return {'code': 1, 'output': 'Refus : "localhost" absent — entrées système obligatoires'}
            with open(HOSTS, 'w') as f:
                f.write(content)
            return {'code': 0, 'output': 'OK'}

        if cmd not in CMDS:
            return {'code': 1, 'output': f'Commande non autorisée : {cmd}'}

        full = list(CMDS[cmd])
        if args:
            site = str(args[0])
            if not valid_site(site):
                return {'code': 1, 'output': f'Nom de site invalide : {site}'}
            full.append(site)

        r = subprocess.run(
            full, capture_output=True, text=True, timeout=30,
            env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'en_US.UTF-8'}
        )
        return {'code': r.returncode, 'output': (r.stdout + r.stderr).strip()}

    except json.JSONDecodeError:
        return {'code': 1, 'output': 'JSON invalide'}
    except Exception as e:
        return {'code': 1, 'output': str(e)}

def main():
    if os.getuid() != 0:
        print('vhost-helper doit être lancé en root.', file=sys.stderr)
        sys.exit(1)

    if os.path.exists(SOCK):
        os.unlink(SOCK)

    srv = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    srv.bind(SOCK)

    try:
        gid = grp.getgrnam('www-data').gr_gid
        os.chown(SOCK, 0, gid)
    except KeyError:
        pass
    os.chmod(SOCK, 0o660)

    srv.listen(10)

    def stop(sig, frame):
        srv.close()
        if os.path.exists(SOCK):
            os.unlink(SOCK)
        sys.exit(0)

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT,  stop)

    print(f'[vhost-helper] En écoute sur {SOCK}', flush=True)

    while True:
        try:
            conn, _ = srv.accept()
            data = b''
            conn.settimeout(10)
            try:
                while True:
                    chunk = conn.recv(65536)
                    if not chunk:
                        break
                    data += chunk
                    if len(data) > 5 * 1024 * 1024:
                        break
            except socket.timeout:
                pass
            resp = handle(data)
            conn.sendall(json.dumps(resp).encode())
            conn.close()
        except Exception as e:
            print(f'[vhost-helper] Erreur : {e}', file=sys.stderr, flush=True)

if __name__ == '__main__':
    main()
PYEOF

chmod 755 "${VHOST_HELPER_BIN}"
chown root:root "${VHOST_HELPER_BIN}"
print_message "Daemon vhost-helper créé : ${VHOST_HELPER_BIN}"

# ── Service systemd ────────────────────────────────────────────────────────
print_message "Création du service systemd vhost-helper..."

cat > "${VHOST_HELPER_SVC}" << 'SVCEOF'
[Unit]
Description=VHost Manager Helper Daemon
After=network.target apache2.service
Wants=apache2.service

[Service]
Type=simple
ExecStart=/usr/local/bin/vhost-helper
Restart=on-failure
RestartSec=3
User=root

# Compatibilité opérations système requises
PrivateTmp=no
NoNewPrivileges=no
ProtectSystem=no
ProtectHome=no

StandardOutput=journal
StandardError=journal
SyslogIdentifier=vhost-helper

[Install]
WantedBy=multi-user.target
SVCEOF

systemctl daemon-reload
systemctl enable vhost-helper
systemctl start  vhost-helper

# Attendre que le socket soit créé
print_message "Attente du démarrage du daemon..."
for i in $(seq 1 10); do
    if [ -S "${VHOST_SOCKET}" ]; then
        print_message "Socket vhost-helper prêt : ${VHOST_SOCKET}"
        break
    fi
    sleep 1
    if [ "$i" -eq 10 ]; then
        print_error "Socket vhost-helper non créé après 10s — vérifiez : journalctl -u vhost-helper"
    fi
done

# ── Sudoers (CLI uniquement — plus utilisé par PHP) ───────────────────────
print_message "Configuration sudoers (usage CLI uniquement)..."
cat > /etc/sudoers.d/apache-vhost << 'SUDOEOF'
# Compatibilité Ubuntu 26.04+ (use_pty global désactivé pour www-data)
# Note : le dashboard PHP utilise vhost-helper daemon, pas sudo
Defaults:www-data !use_pty

www-data ALL=(ALL) NOPASSWD: /usr/sbin/a2ensite
www-data ALL=(ALL) NOPASSWD: /usr/sbin/a2dissite
www-data ALL=(ALL) NOPASSWD: /usr/sbin/apachectl
www-data ALL=(ALL) NOPASSWD: /usr/bin/tee /etc/hosts
SUDOEOF
chmod 0440 /etc/sudoers.d/apache-vhost
chown root:root /etc/sudoers.d/apache-vhost

# Valider la syntaxe sudoers
if visudo -c -f /etc/sudoers.d/apache-vhost &>/dev/null; then
    print_message "Syntaxe sudoers OK"
else
    print_warning "Erreur syntaxe sudoers — le daemon vhost-helper prend le relais"
    rm -f /etc/sudoers.d/apache-vhost
fi

# ── Permissions sites-available ───────────────────────────────────────────
chown www-data:www-data /etc/apache2/sites-available
chmod 775 /etc/apache2/sites-available

# ══════════════════════════════════════════════════════════════════════════
print_step "7/9 — Déploiement du dashboard VHost Manager"
# ══════════════════════════════════════════════════════════════════════════

# Page de test PHP
cat > /var/www/html/info.php << 'EOF'
<?php phpinfo(); ?>
EOF

# Dashboard principal
if [ -f "./index.php" ]; then
    cp ./index.php /var/www/html/index.php
    print_message "Dashboard VHost Manager copié."
else
    print_warning "index.php introuvable dans le répertoire courant — copiez-le manuellement dans /var/www/html/"
fi

# Nettoyage pages HTML par défaut Apache
rm -f /var/www/html/*.html

# Ajustement des permissions
print_message "Ajustement des permissions /var/www/html..."
chown -R www-data:www-data /var/www/html
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

# ══════════════════════════════════════════════════════════════════════════
print_step "8/9 — Pare-feu et démarrage des services"
# ══════════════════════════════════════════════════════════════════════════

# Pare-feu
if command -v ufw &>/dev/null; then
    print_message "Configuration du pare-feu UFW..."
    ufw allow 'Apache Full'
    ufw allow ssh
    ufw --force enable
fi

# Redémarrage des services
print_message "Redémarrage Apache et MySQL..."
systemctl restart apache2
systemctl restart mysql

# Activation au démarrage
systemctl enable apache2
systemctl enable mysql

# ══════════════════════════════════════════════════════════════════════════
print_step "9/9 — Vérification de l'installation"
# ══════════════════════════════════════════════════════════════════════════

check_service() {
    if systemctl is-active --quiet "$1"; then
        print_message "✓ $1 est en cours d'exécution"
        return 0
    else
        print_error "✗ $1 n'est pas en cours d'exécution"
        return 1
    fi
}

check_service apache2
check_service mysql
check_service vhost-helper

# Vérification socket vhost-helper
if [ -S "${VHOST_SOCKET}" ]; then
    SOCK_PERMS=$(stat -c "%U:%G %a" "${VHOST_SOCKET}")
    print_message "✓ Socket vhost-helper : ${VHOST_SOCKET} (${SOCK_PERMS})"

    # Test fonctionnel du daemon
    if command -v python3 &>/dev/null; then
        TEST_RESULT=$(python3 -c "
import socket, json
try:
    s = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
    s.settimeout(3)
    s.connect('${VHOST_SOCKET}')
    s.sendall(json.dumps({'cmd':'apache_reload','args':[]}).encode())
    s.shutdown(1)
    resp = json.loads(s.recv(4096))
    print('OK' if resp.get('code') == 0 else 'WARN:' + resp.get('output',''))
    s.close()
except Exception as e:
    print('FAIL:' + str(e))
" 2>/dev/null)
        if [[ "$TEST_RESULT" == "OK" ]]; then
            print_message "✓ Daemon vhost-helper opérationnel (test apache_reload réussi)"
        else
            print_warning "⚠ Daemon vhost-helper répond mais : ${TEST_RESULT}"
        fi
    fi
else
    print_error "✗ Socket vhost-helper absent — vérifiez : journalctl -u vhost-helper -n 20"
fi

# ─── RÉSUMÉ FINAL ──────────────────────────────────────────────────────────
SERVER_IP=$(hostname -I | awk '{print $1}')

echo ""
print_message "════════════════════════════════════════════════"
print_message "   INSTALLATION TERMINÉE AVEC SUCCÈS !"
print_message "════════════════════════════════════════════════"
print_message ""
print_message "URLs d'accès :"
print_message "  Dashboard VHost  : http://${SERVER_IP}/"
print_message "  phpMyAdmin       : http://${SERVER_IP}/phpmyadmin"
print_message "  Test PHP         : http://${SERVER_IP}/info.php"
print_message ""
print_message "Connexion MySQL :"
print_message "  Utilisateur : root"
print_message "  Mot de passe : [défini à l'installation]"
print_message ""
print_message "Services installés :"
print_message "  apache2       — Serveur web"
print_message "  mysql         — Base de données"
print_message "  vhost-helper  — Daemon privilégié pour le dashboard"
print_message ""
print_warning "À FAIRE APRÈS L'INSTALLATION :"
print_warning "  1. Supprimer /var/www/html/info.php après test"
print_warning "  2. Configurer SSL/TLS (certbot recommandé)"
print_warning "  3. Restreindre /phpmyadmin par IP si exposé"
print_warning "  4. Tester le dashboard : http://${SERVER_IP}/"
print_message ""
print_message "Commandes utiles :"
print_message "  systemctl status vhost-helper"
print_message "  journalctl -u vhost-helper -f"
print_message "════════════════════════════════════════════════"
print_message "Journal d'installation : ${LOG_FILE}"