#!/bin/bash
# Script d’installation et configuration automatique WireGuard avec multi-clients + QR code
# Pour Debian/Ubuntu

set -e

# Vérification des privilèges root
if [ "$EUID" -ne 0 ]; then
    echo "Ce script doit être exécuté en tant que root. Utilisez sudo."
    exit 1
fi

# Vérification de la distribution
if ! grep -qs -E "(debian|ubuntu)" /etc/os-release; then
    echo "Ce script est conçu pour Debian/Ubuntu uniquement."
    exit 1
fi

SERVER_IP=$(curl -4 -s ifconfig.me)   # IP publique IPv4 du serveur
SERVER_PORT=36090
SERVER_INTERFACE="wg0"
SERVER_NETWORK="10.30.20"
SERVER_CONF="/etc/wireguard/$SERVER_INTERFACE.conf"
SERVER_PRIV_KEY="/etc/wireguard/private.key"
SERVER_PUB_KEY="/etc/wireguard/public.key"
clients_folder="~/wireguard_clients"
echo "=== Mise à jour du système et installation des paquets ==="
apt update && apt install -y wireguard-tools qrencode curl

echo "=== Génération des clés du serveur ==="
mkdir -p $clients_folder
umask 077
mkdir -p /etc/wireguard
wg genkey | tee "$SERVER_PRIV_KEY" | wg pubkey > "$SERVER_PUB_KEY"
SERVER_PRIV=$(cat "$SERVER_PRIV_KEY")
SERVER_PUB=$(cat "$SERVER_PUB_KEY")

echo "=== Configuration du serveur WireGuard ($SERVER_INTERFACE) ==="
# Détermination de l'interface réseau principale
DEFAULT_IFACE=$(ip route get 8.8.8.8 | awk '{print $5; exit}')

cat > "$SERVER_CONF" <<EOF
[Interface]
Address = $SERVER_NETWORK.1/24
ListenPort = $SERVER_PORT
PrivateKey = $SERVER_PRIV
SaveConfig = False

PostUp = iptables -A FORWARD -i %i -j ACCEPT; iptables -t nat -A POSTROUTING -o $DEFAULT_IFACE -j MASQUERADE
PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -t nat -D POSTROUTING -o $DEFAULT_IFACE -j MASQUERADE
EOF

echo "=== Activation du routage IP ==="
sysctl -w net.ipv4.ip_forward=1
echo 'net.ipv4.ip_forward=1' > /etc/sysctl.d/99-wireguard.conf

echo "=== Démarrage du service WireGuard ==="
systemctl enable --now wg-quick@"$SERVER_INTERFACE"

# Génération des clients
read -rp "Nombre de clients à créer (défaut: 3): " NUM_CLIENTS
NUM_CLIENTS=${NUM_CLIENTS:-3}

for i in $(seq 1 "$NUM_CLIENTS"); do
    CLIENT_NAME="client$i"
    CLIENT_PRIV_KEY=$(wg genkey)
    CLIENT_PUB_KEY=$(echo "$CLIENT_PRIV_KEY" | wg pubkey)
    CLIENT_IP="$SERVER_NETWORK.$((i+1))/32"

    echo "=== Ajout du $CLIENT_NAME ($CLIENT_IP) ==="

    # Ajout au serveur
    cat >> "$SERVER_CONF" <<EOF

[Peer]
PublicKey = $CLIENT_PUB_KEY
AllowedIPs = $CLIENT_IP
EOF

    # Création fichier client
    CLIENT_CONF="$clients_folder/$CLIENT_NAME.conf"
    touch $CLIENT_CONF
    cat > "$CLIENT_CONF" <<EOF
[Interface]
PrivateKey = $CLIENT_PRIV_KEY
Address = $SERVER_NETWORK.$((i+1))/24
DNS = 1.1.1.1, 8.8.8.8

[Peer]
PublicKey = $SERVER_PUB
Endpoint = $SERVER_IP:$SERVER_PORT
AllowedIPs = 0.0.0.0/0
PersistentKeepalive = 25
EOF

    # Génération du QR code
    echo -e "\nQR Code pour $CLIENT_NAME :"
    qrencode -t ansiutf8 < "$CLIENT_CONF"
    echo "Fichier de configuration : $CLIENT_CONF"
done

echo "=== Rechargement de la configuration WireGuard ==="
chmod 600 $SERVER_CONF
chmod 600 $SERVER_PRIV_KEY

ufw allow $SERVER_PORT/udp
wg syncconf "$SERVER_INTERFACE" <(wg-quick strip "$SERVER_INTERFACE")

echo "=== Installation terminée ==="
echo "Les fichiers clients sont dans $clients_folder"
echo "Utilisez 'qrencode -t ansiutf8 < fichier.conf' pour réafficher les QR codes"