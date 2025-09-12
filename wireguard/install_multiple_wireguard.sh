#!/bin/bash
# Script d’installation et configuration automatique WireGuard avec multi-clients + QR code
# Pour Debian/Ubuntu

set -e

SERVER_IP=$(curl -s ifconfig.me)   # IP publique du serveur
SERVER_PORT=51820
SERVER_INTERFACE="wg0"
SERVER_NETWORK="10.0.0.0/24"
SERVER_PRIV_KEY="/etc/wireguard/server_private.key"
SERVER_PUB_KEY="/etc/wireguard/server_public.key"

echo "=== Installation de WireGuard et qrencode ==="
apt update && apt install -y wireguard iproute2 iptables curl qrencode

echo "=== Génération des clés du serveur ==="
umask 077
wg genkey | tee $SERVER_PRIV_KEY | wg pubkey > $SERVER_PUB_KEY
SERVER_PRIV=$(cat $SERVER_PRIV_KEY)
SERVER_PUB=$(cat $SERVER_PUB_KEY)

echo "=== Configuration du serveur WireGuard ($SERVER_INTERFACE) ==="
cat > /etc/wireguard/$SERVER_INTERFACE.conf <<EOF
[Interface]
Address = 10.0.0.1/24
ListenPort = $SERVER_PORT
PrivateKey = $SERVER_PRIV

PostUp   = iptables -A FORWARD -i %i -j ACCEPT; iptables -t nat -A POSTROUTING -o \$(ip route get 8.8.8.8 | awk '{print \$5}') -j MASQUERADE
PostDown = iptables -D FORWARD -i %i -j ACCEPT; iptables -t nat -D POSTROUTING -o \$(ip route get 8.8.8.8 | awk '{print \$5}') -j MASQUERADE
EOF

echo "=== Activation du routage IP ==="
sysctl -w net.ipv4.ip_forward=1
sed -i 's/#net.ipv4.ip_forward=1/net.ipv4.ip_forward=1/' /etc/sysctl.conf

echo "=== Démarrage du service WireGuard ==="
systemctl enable wg-quick@$SERVER_INTERFACE
systemctl start wg-quick@$SERVER_INTERFACE

# Génération de plusieurs clients
NUM_CLIENTS=3
BASE_IP=2

for i in $(seq 1 $NUM_CLIENTS); do
    CLIENT_NAME="client$i"
    CLIENT_PRIV_KEY=$(wg genkey)
    CLIENT_PUB_KEY=$(echo $CLIENT_PRIV_KEY | wg pubkey)
    CLIENT_IP="10.0.0.$((BASE_IP+i))/32"

    echo "=== Ajout du $CLIENT_NAME ($CLIENT_IP) ==="

    # Ajout au serveur
    cat >> /etc/wireguard/$SERVER_INTERFACE.conf <<EOF

[Peer]
PublicKey = $CLIENT_PUB_KEY
AllowedIPs = $CLIENT_IP
EOF

    # Création fichier client
    cat > ~/$CLIENT_NAME.conf <<EOF
[Interface]
PrivateKey = $CLIENT_PRIV_KEY
Address = 10.0.0.$((BASE_IP+i))/24
DNS = 1.1.1.1

[Peer]
PublicKey = $SERVER_PUB
Endpoint = $SERVER_IP:$SERVER_PORT
AllowedIPs = 0.0.0.0/0, ::/0
EOF

    # Génération du QR code
    qrencode -t ansiutf8 < ~/$CLIENT_NAME.conf
    echo "👉 Fichier de config : ~/$CLIENT_NAME.conf"
    echo "👉 QR code affiché ci-dessus pour $CLIENT_NAME"
done

echo "=== Redémarrage de WireGuard pour appliquer toutes les configs ==="
systemctl restart wg-quick@$SERVER_INTERFACE

echo "=== Installation terminée 🎉 ==="
echo "👉 Les fichiers clients sont disponibles dans ~/"
echo "👉 Tu peux scanner les QR codes avec l’app WireGuard (Android/iOS)."