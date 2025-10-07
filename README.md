# Ubuntu Servers & Development Environment Installation Scripts

Ce dépôt contient des scripts d'automatisation pour l'installation et la configuration de différents serveurs et environnements de développement sous Linux, principalement orientés Ubuntu.

## Vue d'ensemble

Cette collection de scripts vise à simplifier et automatiser le processus d'installation et de configuration de différents types de serveurs et d'environnements de développement sur les systèmes Linux. Chaque script est conçu pour fournir un processus d'installation simple et reproductible.

## Scripts Disponibles

### Environnement de Développement
#### DevOps, Réseaux, IA & Web 
- Location: `/Os_installation/`
- Files:
  - `setup_DevOps-network-ia-web.sh`: Script complet d'installation incluant :
    - Outils réseau et cybersécurité (Nmap, Wireshark, TCPdump)
    - Docker & Docker Compose
    - Node.js & npm
    - n8n (Automatisation)
    - Ollama (IA locale)
    - Stack LAMP (Apache, MySQL, PHP)
    - VSCode, Postman, Terminator
    - GitHub CLI
    - Ansible
  - `README.md`: Guide d'installation détaillé et documentation

### Serveur VPN WireGuard
- Location: `/wireguard/`
- Files:
  - `install_wireguard.sh`: Script d'installation automatisée du serveur VPN
  - `creation_et_config_de_wireguard.txt`: Guide de configuration
  - `README.md`: Instructions détaillées d'installation et de configuration

## Utilisation

1. Clonez ce dépôt :
```bash
git clone https://github.com/DESMOND-77/ubuntu_servers_installation.git
```

2. Accédez au répertoire du script que vous souhaitez utiliser :
```bash
cd ubuntu_servers_installation/<dossier_script>
```

3. Rendez le script exécutable :
```bash
chmod +x *.sh
```

4. Exécutez le script avec sudo :
```bash
sudo ./script_name.sh
```

## Prérequis

- Ubuntu ou distribution Linux compatible
- Privilèges root/sudo
- Connexion Internet stable
- Connaissance de base en administration système

## Structure du Projet

```
ubuntu_servers_installation/
├── Os_installation/
│   ├── setup_DevOps-network-ia-web.sh
│   └── README.md
├── wireguard/
│   ├── install_wireguard.sh
│   ├── creation_et_config_de_wireguard.txt
│   └── README.md
└── README.md
```

## Contribution

N'hésitez pas à contribuer en :
1. Forkant le dépôt
2. Créant votre branche de fonctionnalité
3. Committant vos modifications
4. Créant une pull request

## Sécurité

- Tous les scripts sont exécutés avec des privilèges sudo
- Vérifiez toujours le contenu des scripts avant de les exécuter
- Les scripts incluent des vérifications de base pour éviter les erreurs courantes

## Licence

Ce projet est open source et disponible sous la licence MIT.

## Avertissement

Veuillez examiner tous les scripts avant de les exécuter sur votre système. Bien que ces scripts soient conçus pour être sûrs et efficaces, il est toujours recommandé de comprendre ce que vous installez sur votre système.
