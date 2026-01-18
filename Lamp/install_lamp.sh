#!/bin/bash

# Script d'installation automatique LAMP + phpMyAdmin
# Testé sur Debian/Ubuntu
# À exécuter avec les privilèges root

# Couleurs pour les messages
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Fonction pour afficher les messages
print_message() {
    echo -e "${GREEN}[INFO]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERREUR]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[ATTENTION]${NC} $1"
}

# Vérification des privilèges root
if [ "$EUID" -ne 0 ]; then
    print_error "Ce script doit être exécuté en tant que root (utilisez sudo)"
    exit 1
fi

# Variables configurables
MYSQL_ROOT_PASSWORD=""
PHPMYADMIN_PASSWORD=""
SITE_NAME="mon_site"
DOMAIN="localhost"

# Demander les informations si non fournies en variable
if [ -z "$MYSQL_ROOT_PASSWORD" ]; then
    read -sp "Entrez le mot de passe root MySQL: " MYSQL_ROOT_PASSWORD
    echo
fi

if [ -z "$PHPMYADMIN_PASSWORD" ]; then
    read -sp "Entrez le mot de passe pour phpMyAdmin: " PHPMYADMIN_PASSWORD
    echo
fi

# Journalisation
LOG_FILE="/var/log/lamp_installation_$(date +%Y%m%d_%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1

print_message "Début de l'installation - Journal: $LOG_FILE"

# Mise à jour du système
print_message "Mise à jour des paquets système..."
apt-get update && apt-get upgrade -y

# Installation d'Apache
print_message "Installation d'Apache..."
apt-get install -y apache2 apache2-utils

# Activation des modules Apache
print_message "Activation des modules Apache..."
a2enmod rewrite
a2enmod headers
a2enmod expires

# Installation de MySQL
print_message "Installation de MySQL..."
apt-get install -y mysql-server mysql-client

# Sécurisation de MySQL
print_message "Configuration de MySQL..."
mysql --user=root <<_EOF_
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${MYSQL_ROOT_PASSWORD}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
_EOF_

# Installation de PHP et extensions
print_message "Installation de PHP et extensions..."
apt-get install -y php libapache2-mod-php php-mysql php-cli php-curl php-gd php-json php-mbstring php-xml php-zip php-bcmath

# Installation de phpMyAdmin
print_message "Installation de phpMyAdmin..."
echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect apache2" | debconf-set-selections
echo "phpmyadmin phpmyadmin/dbconfig-install boolean true" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/admin-pass password ${MYSQL_ROOT_PASSWORD}" | debconf-set-selections
echo "phpmyadmin phpmyadmin/mysql/app-pass password ${PHPMYADMIN_PASSWORD}" | debconf-set-selections
echo "phpmyadmin phpmyadmin/app-password-confirm password ${PHPMYADMIN_PASSWORD}" | debconf-set-selections

apt-get install -y phpmyadmin

# Configuration de phpMyAdmin
print_message "Configuration de phpMyAdmin..."
if [ -f /etc/phpmyadmin/config.inc.php ]; then
    # Ajout d'une configuration de sécurité supplémentaire
    cat >> /etc/phpmyadmin/config.inc.php << EOF

// Configuration de sécurité supplémentaire
\$cfg['Servers'][1]['AllowNoPassword'] = false;
\$cfg['ForceSSL'] = true;
\$cfg['LoginCookieValidity'] = 14400;
EOF
fi

# Configuration du virtual host Apache
print_message "Création du virtual host..."
cat > /etc/apache2/sites-available/${SITE_NAME}.conf << EOF
<VirtualHost *:80>
    ServerAdmin webmaster@${DOMAIN}
    ServerName ${DOMAIN}
    DocumentRoot /var/www/html

    ErrorLog \${APACHE_LOG_DIR}/error.log
    CustomLog \${APACHE_LOG_DIR}/access.log combined

    <Directory /var/www/html>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Configuration pour phpMyAdmin
    Alias /phpmyadmin /usr/share/phpmyadmin
    <Directory /usr/share/phpmyadmin>
        Options FollowSymLinks
        DirectoryIndex index.php
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

# Activation du site et désactivation du site par défaut
a2dissite 000-default.conf
a2ensite ${SITE_NAME}.conf

# Création de la page de test PHP
print_message "Création de la page de test PHP..."
cat > /var/www/html/info.php << EOF
<?php
phpinfo();
?>
EOF

cat > /var/www/html/index.html << EOF
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Installation LAMP réussie</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .info { background: #f0f0f0; padding: 20px; border-radius: 5px; }
    </style>
</head>
<body>
    <h1 class="success">Installation LAMP réussie !</h1>
    <div class="info">
        <h2>Services installés :</h2>
        <ul>
            <li>Apache2</li>
            <li>MySQL</li>
            <li>PHP</li>
            <li>phpMyAdmin</li>
        </ul>
        <p><a href="/info.php">Voir les informations PHP</a></p>
        <p><a href="/phpmyadmin">Accéder à phpMyAdmin</a></p>
    </div>
</body>
</html>
EOF

# Ajustement des permissions
print_message "Ajustement des permissions..."
chown -R www-data:www-data /var/www/html
find /var/www/html -type d -exec chmod 755 {} \;
find /var/www/html -type f -exec chmod 644 {} \;

# Configuration du pare-feu (si ufw est installé)
if command -v ufw &> /dev/null; then
    print_message "Configuration du pare-feu..."
    ufw allow 'Apache Full'
    ufw allow ssh
    ufw --force enable
fi

# Redémarrage des services
print_message "Redémarrage des services..."
systemctl restart apache2
systemctl restart mysql

# Activation au démarrage
systemctl enable apache2
systemctl enable mysql

# Vérification des services
print_message "Vérification des services..."
if systemctl is-active --quiet apache2; then
    print_message "Apache est en cours d'exécution"
else
    print_error "Apache n'est pas en cours d'exécution"
fi

if systemctl is-active --quiet mysql; then
    print_message "MySQL est en cours d'exécution"
else
    print_error "MySQL n'est pas en cours d'exécution"
fi

# Affichage des informations de connexion
print_message "================================================"
print_message "INSTALLATION TERMINÉE AVEC SUCCÈS !"
print_message "================================================"
print_message "Informations d'accès :"
print_message "  - Site web : http://$(hostname -I | awk '{print $1}')"
print_message "  - phpMyAdmin : http://$(hostname -I | awk '{print $1}')/phpmyadmin"
print_message "  - Test PHP : http://$(hostname -I | awk '{print $1}')/info.php"
print_message ""
print_message "Informations de connexion MySQL :"
print_message "  - Utilisateur : root"
print_message "  - Mot de passe : [celui que vous avez défini]"
print_message ""
print_message "Informations de connexion phpMyAdmin :"
print_message "  - Utilisateur : root (ou créer un nouvel utilisateur)"
print_message "  - Mot de passe MySQL : [celui que vous avez défini]"
print_message ""
print_warning "PENSEZ À :"
print_warning "1. Changer les mots de passe par défaut"
print_warning "2. Configurer SSL/TLS pour phpMyAdmin"
print_warning "3. Restreindre l'accès à phpMyAdmin par IP si nécessaire"
print_warning "4. Supprimer le fichier info.php après utilisation"
print_message "================================================"
print_message "Journal d'installation : $LOG_FILE"