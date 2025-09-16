# WireGuard VPN - Installation et Configuration Automatisée

Ce répertoire contient les scripts et instructions pour l'installation et la configuration automatisée d'un serveur VPN WireGuard sur Debian/Ubuntu.

## Contenu du Répertoire

- `install_wireguard.sh` : Script d'installation automatisée
- `creation_et_config_de_wireguard.txt` : Guide de configuration manuelle et commandes de référence

## Script d'Installation Automatisée

### Fonctionnalités

Le script `install_wireguard.sh` offre :

- Installation automatique des paquets requis (wireguard-tools, qrencode)
- Configuration du serveur WireGuard
- Génération automatique des configurations clients
- Création de QR codes pour une configuration facile des clients mobiles
- Support multi-clients
- Configuration automatique du pare-feu (UFW)
- Activation du forwarding IP

### Paramètres par Défaut

- Interface : wg0
- Réseau : 10.30.20.0/24
- Port : 36090
- DNS Clients : 1.1.1.1, 8.8.8.8

### Utilisation

1. Exécutez le script en tant que root :
   ```bash
   sudo ./install_wireguard.sh
   ```
2. Suivez les instructions à l'écran
3. Choisissez le nombre de clients à créer
4. Récupérez les configurations dans le dossier `~/wireguard_clients/`

### Fichiers Générés

- `/etc/wireguard/wg0.conf` : Configuration du serveur
- `~/wireguard_clients/client*.conf` : Configurations des clients
- QR codes pour chaque client

## Configuration Manuelle (Référence)

Le fichier `creation_et_config_de_wireguard.txt` contient :

- Instructions pour la configuration manuelle
- Commandes de base WireGuard
- Exemples de configuration
- Instructions de dépannage

### Commandes Utiles

```bash
# Vérifier la configuration
wg show wg0

# Sauvegarder la configuration
wg-quick save wg0

# Activer la connexion VPN
wg-quick up wg0
```

## Sécurité

- Les clés privées sont stockées avec les permissions 600
- Le forwarding IP est configuré de manière sécurisée
- Les règles iptables sont automatiquement gérées

## Prérequis

- Système Debian/Ubuntu
- Privilèges root
- Connexion Internet active
- Port UDP 36090 accessible (configurable)

## Support

Pour plus d'informations sur WireGuard :
- [Site officiel WireGuard](https://www.wireguard.com/)
- Configuration manuelle : voir `creation_et_config_de_wireguard.txt`
