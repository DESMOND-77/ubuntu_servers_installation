# Script d'Installation Automatisée de Zabbix

Ce script permet l'installation automatisée de Zabbix sur Ubuntu 24.04.

## Prérequis

**Important :** Le fichier `libmysqlclient21_8.0.28-0ubuntu4_amd64.deb` doit impérativement être présent dans le même répertoire que le script d'installation `install_zabbix.sh`. Cette dépendance est requise pour le bon fonctionnement de l'installation.

## Fonctionnalités du Script

Le script effectue les opérations suivantes :

1. Vérification des droits root
2. Configuration des paramètres d'installation :
   - Nom d'hôte pour l'interface web
   - Mot de passe root MySQL
   - Mot de passe pour l'utilisateur MySQL 'zabbix'
3. Installation des dépendances système
4. Configuration du dépôt Zabbix
5. Installation des composants Zabbix
6. Configuration de la base de données
7. Import du schéma initial
8. Configuration du serveur Zabbix

## Utilisation

1. Assurez-vous que `libmysqlclient21_8.0.28-0ubuntu4_amd64.deb` est dans le même répertoire que `install_zabbix.sh`
2. Exécutez le script en tant que root :
   ```bash
   sudo ./install_zabbix.sh
   ```
3. Suivez les instructions à l'écran pour la configuration

## Accès à l'Interface Web

Après l'installation, accédez à l'interface via : `http://votre_serveur/zabbix`

Identifiants par défaut :
- Utilisateur : Admin
- Mot de passe : zabbix

**Note de sécurité :** Il est fortement recommandé de changer le mot de passe par défaut après la première connexion.
