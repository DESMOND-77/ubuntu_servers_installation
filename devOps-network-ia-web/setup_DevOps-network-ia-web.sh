#!/bin/bash

# ============================================================
# Ubuntu Setup Script - Réseaux, DevOps, IA & Web
# Auteur : DESMOND-77
# ============================================================

echo "=== Mise à jour du système ==="
sudo apt update && sudo apt upgrade -y

echo "=== Installation paquets de base ==="
sudo apt install -y curl git build-essential net-tools unzip htop \
  software-properties-common apt-transport-https ca-certificates gnupg lsb-release

echo "=== Outils réseaux & cybersécurité ==="
sudo apt install -y nmap wireshark tcpdump traceroute whois netcat-openbsd
sudo usermod -aG wireshark $USER

echo "=== Installation Docker & Docker Compose ==="
sudo mkdir -p /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | \
  sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
  https://download.docker.com/linux/ubuntu $(lsb_release -cs) stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt update
sudo apt install -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo usermod -aG docker $USER

echo "=== Installation Node.js & npm ==="
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

echo "=== Installation n8n ==="
sudo npm install -g n8n

echo "=== Installation Ollama (IA locale) ==="
curl -fsSL https://ollama.com/install.sh | sh

echo "=== Installation LAMP Stack (Apache, MySQL, PHP) ==="
sudo apt install -y apache2 mysql-server php libapache2-mod-php php-mysql

echo "=== Installation VSCode ==="
sudo snap install code --classic

echo "=== Installation Postman ==="
sudo snap install postman

echo "=== Installation Terminator ==="
sudo apt install -y terminator

echo "=== Installation GitHub CLI ==="
sudo apt install -y gh

echo "=== Installation Ansible ==="
sudo apt install -y ansible

echo "=== Nettoyage ==="
sudo apt autoremove -y && sudo apt clean

echo "============================================================"
echo " Installation terminée !"
echo " ⚡ Redémarre ton PC pour appliquer les changements (Docker, Wireshark)."
echo "============================================================"
