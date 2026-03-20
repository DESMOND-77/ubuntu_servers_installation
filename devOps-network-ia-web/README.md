# Configuration Environnement Ubuntu - DevOps, Réseaux, IA & Web

Ce dossier contient des scripts pour configurer automatiquement un environnement de développement Ubuntu avec tous les outils nécessaires pour le DevOps, le réseau, l'IA et le développement Web.

## 📦 Contenu du Script Principal

Le script `setup_DevOps-network-ia-web.sh` installe et configure les composants suivants :

### 🛠 Outils de Base
- Git
- curl
- build-essential
- net-tools
- unzip
- htop

### 🔒 Outils Réseaux & Cybersécurité
- Nmap
- Wireshark
- TCPdump
- Traceroute
- Whois
- Netcat

### 🐋 Conteneurisation
- Docker
- Docker Compose
- Ajout de l'utilisateur au groupe docker

### 💻 Développement
- Node.js & npm (version 20.x)
- n8n (Automatisation des workflows)
<!-- - Stack LAMP
  - Apache2
  - MySQL Server
  - PHP -->

### 🤖 Intelligence Artificielle
- Ollama (IA locale)

### 🔧 Outils de Développement
- Visual Studio Code
- Postman
- Terminator (Terminal avancé)
- GitHub CLI
- Ansible

## 🚀 Installation

1. Rendez le script exécutable :
```bash
chmod +x setup_DevOps-network-ia-web.sh
```

2. Exécutez le script :
```bash
sudo ./setup_DevOps-network-ia-web.sh
```

## ⚠️ Important

- Le script doit être exécuté avec les privilèges sudo
- Un redémarrage est nécessaire après l'installation pour :
  - Activer les permissions Docker
  - Appliquer les configurations Wireshark
- Une connexion Internet stable est requise

## 🔍 Post-Installation

Après l'installation :

1. Redémarrez votre système
2. Vérifiez que Docker fonctionne : `docker run hello-world`
<!-- 3. Testez la connexion MySQL : `mysql -u root -p` -->
3. Vérifiez le service Apache : `http://localhost`

## 📝 Personnalisation

Le script peut être modifié pour :
- Ajouter des paquets supplémentaires
- Modifier les versions des logiciels
- Ajuster les configurations par défaut

## 🐛 Dépannage

Si vous rencontrez des problèmes :

1. Vérifiez les logs système : `journalctl -xe`
2. Assurez-vous que tous les services sont actifs : `systemctl status service-name`
3. Vérifiez la connectivité Internet
4. Consultez les permissions utilisateur pour Docker et Wireshark

## 📚 Documentation

- [Docker Documentation](https://docs.docker.com/)
- [Node.js Documentation](https://nodejs.org/docs/)
- [Wireshark User Guide](https://www.wireshark.org/docs/)
- [Ollama Documentation](https://ollama.ai/docs)
- [n8n Documentation](https://docs.n8n.io/)