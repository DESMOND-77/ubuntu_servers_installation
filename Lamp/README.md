# LAMP Stack Auto-Install Script

Script d'automatisation pour l'installation et la configuration d'un environnement LAMP complet (Apache, MySQL, PHP, phpMyAdmin) sur Linux Debian/Ubuntu.

## Table des matières

- [Fonctionnalités](#-fonctionnalités)
- [Prérequis](#️-prérequis)
- [Installation](#-installation)
- [Utilisation](#-utilisation)
- [Configuration](#️-configuration)
- [Services installés](#️-services-installés)
- [Sécurité](#-sécurité)
- [Dépannage](#-dépannage)
- [Contribution](#-contribution)
- [Licence](#-licence)

## Fonctionnalités

- Installation automatique d'Apache 2

- Installation automatique de MySQL/MariaDB

- Installation automatique de PHP 7.4+

- Installation automatique de phpMyAdmin

- Configuration sécurisée de MySQL

- Configuration automatique des virtual hosts

- Activation des modules Apache nécessaires

- Création de pages de test

- Journalisation complète de l'installation

- Vérification des services après installation

- Messages colorés pour une meilleure lisibilité

## Prérequis

- **Système d'exploitation** : Debian, Ubuntu ou dérivés
- **Privilèges** : Accès root (sudo)
- **Connexion Internet**
- **1 Go d'espace disque** minimum
- **512 Mo de RAM** minimum

## Installation

### Téléchargez le script

```bash
wget https://github.com/DESMOND-77/ubuntu_servers_installation/Lamp/install_lamp.sh
```

### Rendez le script exécutable

```bash
chmod +x install_lamp.sh
```

## 🚀 Utilisation

### Installation standard

```bash
sudo ./install_lamp.sh
```

### Installation avec paramètres prédéfinis

```bash
# Modifiez les variables dans le script avant exécution
# ou utilisez des variables d'environnement
export MYSQL_ROOT_PASSWORD="VotreMotDePasseSecurise"
export PHPMYADMIN_PASSWORD="AutreMotDePasse"
sudo ./install_lamp.sh
```

### Options de ligne de commande (à venir)

```bash
# Prochainement
./install_lamp.sh --mysql-password="xxx" --site-name="mon-site"
```

## Configuration

### Variables modifiables dans le script

| Variable | Description | Valeur par défaut |
|----------|-------------|-------------------|
| MYSQL_ROOT_PASSWORD | Mot de passe root MySQL | (défini pendant l'exécution) |
| PHPMYADMIN_PASSWORD | Mot de passe pour phpMyAdmin | (défini pendant l'exécution) |
| SITE_NAME | Nom du site/virtual host | mon_site |
| DOMAIN | Domaine ou IP du serveur | localhost |

### Structure des fichiers créés

```text
/var/www/html/              # Racine du site web
├── index.html              # Page d'accueil de test
├── info.php               # Page d'information PHP
└── (vos fichiers)

/etc/apache2/sites-available/
└── mon_site.conf          # Configuration du virtual host

/usr/share/phpmyadmin      # Installation de phpMyAdmin
```

## Services installés

| Service | Version | Port | Accès |
|---------|---------|------|-------|
| Apache 2 | 2.4+ | 80 (HTTP) | http://votre-ip |
| MySQL | 8.0+ | 3306 | mysql -u root -p |
| PHP | 7.4+ | N/A | http://votre-ip/info.php |
| phpMyAdmin | Dernière | N/A | http://votre-ip/phpmyadmin |

## Sécurité

### Actions automatiques du script

- Désactivation du site Apache par défaut

- Suppression des bases de test MySQL

- Suppression des utilisateurs anonymes MySQL

- Configuration du mot de passe root MySQL

- Activation des modules de sécurité Apache

- Configuration des permissions restrictives

### Actions recommandées après installation

#### Changer les mots de passe par défaut

```bash
mysql -u root -p -e "ALTER USER 'root'@'localhost' IDENTIFIED BY 'NouveauMotDePasseTresSecurise';"
```

#### Restreindre l'accès à phpMyAdmin

```bash
# Éditer le fichier de configuration
sudo nano /etc/apache2/conf-available/phpmyadmin.conf
# Ajouter : Require ip 192.168.1.0/24
```

#### Configurer SSL/TLS

```bash
sudo apt install certbot python3-certbot-apache
sudo certbot --apache -d votre-domaine.com
```

#### Supprimer les fichiers de test

```bash
sudo rm /var/www/html/info.php
```

#### Créer un utilisateur MySQL dédié

```sql
CREATE USER 'mon_user'@'localhost' IDENTIFIED BY 'mot_de_passe';
GRANT ALL PRIVILEGES ON *.* TO 'mon_user'@'localhost' WITH GRANT OPTION;
FLUSH PRIVILEGES;
```

## Dépannage

### Problèmes courants et solutions

#### Erreur "Port 80 déjà utilisé"

```bash
sudo netstat -tulpn | grep :80
sudo systemctl stop nginx  # Si Nginx est en cours
```

#### Impossible de se connecter à MySQL

```bash
# Réinitialiser le mot de passe root
sudo mysql_secure_installation
```

#### phpMyAdmin inaccessible

```bash
# Vérifier la configuration Apache
sudo apache2ctl configtest
sudo systemctl restart apache2
```

#### Erreurs de permissions

```bash
sudo chown -R www-data:www-data /var/www/html
sudo chmod -R 755 /var/www/html
```

### Journalisation

Le script génère un journal détaillé dans :

```text
/var/log/lamp_installation_AAAAMMJJ_HHMMSS.log
```

### Commandes utiles de vérification

```bash
# Vérifier l'état des services
systemctl status apache2
systemctl status mysql

# Vérifier la version PHP
php -v

# Vérifier les logs Apache
tail -f /var/log/apache2/error.log

# Tester la connexion MySQL
mysql -u root -p -e "SHOW DATABASES;"
```

## Contribution

Les contributions sont les bienvenues ! Voici comment contribuer :

1. Forkez le projet
2. Créez une branche pour votre fonctionnalité (`git checkout -b feature/AmazingFeature`)
3. Committez vos changements (`git commit -m 'Add some AmazingFeature'`)
4. Pushez vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrez une Pull Request

### Améliorations prévues

- Support pour CentOS/RHEL
- Options de ligne de commande
- Installation sélective des composants
- Support de Nginx
- Installation de WordPress/Drupal/Joomla
- Interface web de gestion

## Licence

Ce projet est sous licence MIT. Voir le fichier LICENSE pour plus de détails.

## Avertissement

Ce script est fourni tel quel, sans aucune garantie. Utilisez-le à vos propres risques. Il est recommandé de :

- Tester d'abord dans un environnement de développement
- Sauvegarder vos données avant l'utilisation
- Examiner le code source avant exécution
- Adapter la configuration à vos besoins spécifiques

## Support

Si vous rencontrez des problèmes :

- Consultez la section Dépannage
- Vérifiez les logs d'installation
- Ouvrez une issue sur GitHub
- Contactez l'auteur (si fourni)

**Note** : Ce script a été testé sur :

- Ubuntu 20.04 LTS
- Ubuntu 22.04 LTS
- Pop!_OS 22.04 LTS
- Debian 11
- Debian 12

**Dernière mise à jour** : 18/01/2026

---

N'hésitez pas à donner une étoile au projet si vous le trouvez utile !
