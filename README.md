# Ubuntu Servers Installation Scripts

This repository contains automated installation scripts for various server applications on Linux distributions, primarily focused on Ubuntu.

## Overview

This collection of scripts aims to simplify and automate the process of installing and configuring different types of servers on Linux systems. Each script is designed to provide a straightforward and reproducible installation process.

## Available Scripts

### WireGuard VPN Server
- Location: `/wireguard/`
- Files:
  - `install_wireguard.sh`: Automated installation script for WireGuard VPN server
  - `creation_et_config_de_wireguard.txt`: Configuration guide and documentation

## Usage

1. Clone this repository:
```bash
git clone https://github.com/DESMOND-77/ubuntu_servers_installation.git
```

2. Navigate to the specific server directory you want to install
3. Make the installation script executable:
```bash
chmod +x install_*.sh
```

4. Run the installation script:
```bash
./install_*.sh
```

## Requirements

- Ubuntu or compatible Linux distribution
- Root/sudo privileges
- Basic understanding of server administration

## Contributing

Feel free to contribute by:
1. Forking the repository
2. Creating your feature branch
3. Committing your changes
4. Creating a pull request

## License

This project is open source and available under the MIT License.

## Disclaimer

Please review all scripts before running them on your system. While these scripts are designed to be safe and efficient, it's always good practice to understand what you're installing on your server.
