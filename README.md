# Ubuntu Servers & Systems Installation Scripts

Ce dépôt contient des scripts d'automatisation pour l'installation et la configuration de serveurs et d'environnements de développement sous Linux, principalement orientés Ubuntu.

## Vue d'ensemble

Cette collection de scripts vise à simplifier et automatiser le processus d'installation et de configuration de différents types de serveurs et d'environnements de développement sur les systèmes Linux. Chaque script est conçu pour fournir un processus d'installation simple et reproductible.

## Scripts disponibles

### Configuration Système
- Location: `/devOps-network-ia-web/`
- Files:
  - `setup_DevOps-network-ia-web.sh`: Script complet d'installation pour un environnement DevOps, Réseau, IA et Web
  - `README.md`: Guide détaillé d'installation et de configuration
- Composants inclus:
  - Outils réseaux et cybersécurité (Nmap, Wireshark, TCPdump)
  - Docker & Docker Compose
  - Node.js & npm
  - Stack LAMP (Apache, MySQL, PHP)
  - Outils de développement (VSCode, Postman, GitHub CLI)
  - Ansible et n8n
  - Ollama (IA locale)

### Serveur VPN WireGuard
- Location: `/wireguard/`
- Files:
  - `install_wireguard.sh`: Script d'installation automatisée du serveur VPN
  - `creation_et_config_de_wireguard.txt`: Guide de configuration détaillé
  - `README.md`: Instructions complètes d'installation

### Serveur de Monitoring Zabbix
- Location: `/zabbix/`
- Files:
  - `install_zabbix.sh`: Script d'installation automatisée de Zabbix
  - `libmysqlclient21_8.0.28-0ubuntu4_amd64.deb`: Bibliothèque client MySQL requise
  - `README.md`: Guide d'installation et informations sur les dépendances

### Serveur VoIP Asterisk
- Location: `/asterisk/`
- Files:
  - `asterisk_base_config.txt`: Exemples de configuration de base
  - `asterisk voip.txt`: Guide de configuration VoIP
  - `README.md`: Guide complet d'installation et de configuration
### Serveur LAMP
- Location: `/lamp/`
- Files:
  - `install_lamp.sh`: Script d'installation automatisée de lamp
  - `README.md`: Guide d'installation, de configuration et informations sur les dépendances
## Structure du projet

```
ubuntu_servers_installation/
├── devOps-network-ia-web/
│   ├── setup_DevOps-network-ia-web.sh
│   └── README.md
├── wireguard/
│   ├── install_wireguard.sh
│   ├── creation_et_config_de_wireguard.txt
│   └── README.md
├── zabbix/
│   ├── install_zabbix.sh
│   ├── libmysqlclient21_8.0.28-0ubuntu4_amd64.deb
│   └── README.md
├── asterisk/
│   ├── asterisk_base_config.txt
│   ├── asterisk voip.txt
│   └── README.md
├── lamp/
│   ├── install_lamp.sh
│   └── README.md
└── README.md
```

## Utilisation

1. Clonez ce dépôt :
```bash
git clone https://github.com/DESMOND-77/ubuntu_servers_installation.git
```

2. Accédez au répertoire du script souhaité :
```bash
cd ubuntu_servers_installation/<dossier_script>
```

3. Consultez le README.md du dossier pour les instructions spécifiques

4. Rendez le script d'installation exécutable :
```bash
chmod +x *.sh
```

5. Exécutez le script avec les privilèges sudo :
```bash
sudo ./script_name.sh
```

## Prérequis

- Ubuntu ou distribution Linux compatible
- Privilèges root/sudo
- Connexion Internet stable
- Connaissance de base en administration système
- Espace disque suffisant (varie selon les installations)

## Sécurité

- Tous les scripts doivent être exécutés avec les privilèges sudo
- Examinez toujours le contenu des scripts avant de les exécuter
- Sauvegardez vos données importantes avant toute installation
- Suivez les recommandations de sécurité spécifiques à chaque service

## Contribution

N'hésitez pas à contribuer en :
1. Forkant le dépôt
2. Créant votre branche de fonctionnalité
3. Committant vos modifications
4. Créant une pull request

Les contributions sont les bienvenues pour :
- Ajouter de nouveaux scripts d'installation
- Améliorer les scripts existants
- Mettre à jour la documentation
- Corriger des bugs
- Ajouter des fonctionnalités

## Licence

Ce projet est open source et disponible sous la licence MIT.

## Avertissement

Veuillez examiner tous les scripts avant de les exécuter sur votre système. Bien que ces scripts soient conçus pour être sûrs et efficaces, il est toujours recommandé de comprendre ce que vous installez sur votre serveur. Certaines installations peuvent nécessiter des configurations supplémentaires en fonction de votre environnement spécifique.
