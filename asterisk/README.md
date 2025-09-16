# Guide d'Installation et Configuration d'Asterisk

Ce guide détaille l'installation et la configuration d'un serveur VoIP Asterisk sur un système Ubuntu/Debian.

## Installation

1. Mise à jour du système :
```bash
sudo apt update
sudo apt upgrade -y
```

2. Installation d'Asterisk et des dépendances :
```bash
sudo apt install asterisk mpg123 -y
```

## Structure des Fichiers de Configuration

Les fichiers principaux de configuration se trouvent dans `/etc/asterisk/` :

- `sip.conf` : Configuration des utilisateurs SIP
- `extensions.conf` : Plan de numérotation
- `voicemail.conf` : Configuration des boîtes vocales
- `musiconhold.conf` : Configuration de la musique d'attente

## Configuration de Base

### 1. Configuration SIP (sip.conf)

```ini
[general]
language=fr
allowguest=no
allowoverlap=no
bindport=5060
bindaddr=0.0.0.0
svrlookup=no
alwaysauthreject=yes
canreinvite=no
nat=yes
session-timers=refuse
localnet=192.168.0.0/255.255.255.0
disallow=all
allow=ilbc,g729,gsm,g723,ulaw,alaw,h263,h263p,h264,vp8,g723.1
dtmfmode=rfc2833
directmedia=yes
videosupport=yes
host=dynamic

; Exemple d'utilisateur
[1000]
username=user1
fullname=admin user1
mailbox=1000@voiceMail
secret=user1
context=internal
```

### 2. Plan de Numérotation (extensions.conf)

```ini
[internal]
; Service de messagerie vocale
exten => 500,1,VoiceMailMain()
exten => 500,2,HangUp()

; Configuration utilisateur type
exten => 1000,1,Ringing
exten => 1000,2,Wait(1)
exten => 1000,3,Answer()
exten => 1000,4,Set(CHANNEL(musicclass)=sidy)
exten => 1000,5,Dial(SIP/1000,60,tTrm(sidy))
exten => 1000,6,PlayBack(vm-nobodyavail)
exten => 1000,7,VoiceMail(1000@VoiceMail)
exten => 1000,8,Hangup()
```

### 3. Configuration de la Messagerie Vocale (voicemail.conf)

```ini
[general]
format=wav49|wav
maxmsg=100
maxsec=60
minsec=3
skipms=3000
maxsilence=10
silencethreshold=128
maxlogins=3

[VoiceMail]
1000 => user1,admin user1
```

### 4. Configuration de la Musique d'Attente

1. Créer le répertoire pour les fichiers audio :
```bash
sudo mkdir -p /var/lib/asterisk/monmp3
```

2. Configuration (musiconhold.conf) :
```ini
[sidy]
mode=custom
directory=/var/lib/asterisk/monmp3
application=/usr/bin/mpg123 -q -r 8000 -f 8192 -b 2048 --mono -s
```

## Gestion du Service

```bash
# Démarrer Asterisk
sudo systemctl start asterisk

# Activer au démarrage
sudo systemctl enable asterisk

# Console Asterisk
sudo asterisk -r

# Commandes utiles dans la console
reload                    # Recharger la configuration
module reload chan_sip.so # Recharger le module SIP
sip show peers           # Afficher les peers SIP
sip show users           # Afficher les utilisateurs
```

## Sécurité

Recommandations de sécurité :
- Toujours modifier les mots de passe par défaut
- Utiliser des mots de passe forts pour les comptes SIP
- Configurer correctement le pare-feu (UFW)
- Désactiver allowguest dans sip.conf
- Utiliser alwaysauthreject=yes
- Surveiller régulièrement les logs

## Dépannage

1. Vérifier le statut du service :
```bash
sudo systemctl status asterisk
```

2. Consulter les logs :
```bash
sudo tail -f /var/log/asterisk/messages
sudo tail -f /var/log/asterisk/full
```

3. Vérifier la configuration :
```bash
sudo asterisk -rx "sip show peers"
sudo asterisk -rx "core show channels"
```

## Support

Pour plus d'informations :
- [Documentation officielle Asterisk](https://wiki.asterisk.org)
- [Forum Asterisk](https://community.asterisk.org)

## Notes

- Pensez à sauvegarder vos fichiers de configuration avant toute modification
- Testez toujours vos modifications dans un environnement de test avant la production
- Surveillez régulièrement l'utilisation des ressources système
