# Déploiement Debian 13 + Docker + FrankenPHP

Ce dépôt contient tout le nécessaire pour faire tourner l'application avec
[FrankenPHP](https://frankenphp.dev) (PHP 8.4 embarquant le serveur web Caddy :
HTTP/2 et HTTP/3, HTTPS automatique via Let's Encrypt) sur un serveur Debian 13
(« trixie »).

Contenu ajouté :

| Fichier | Rôle |
| --- | --- |
| `Dockerfile` | Image multi-stage FrankenPHP (cible `frankenphp_prod`, image finale basée sur `debian:13-slim`) |
| `compose.prod.yaml` | Stack complète : service `php` (FrankenPHP) + `database` (PostgreSQL 16) |
| `frankenphp/Caddyfile` | Configuration Caddy : worker PHP, gzip/zstd/brotli |
| `frankenphp/conf.d/*.ini` | Réglages PHP (OPcache, préchargement en prod) |
| `frankenphp/docker-entrypoint.sh` | Attente de la base + exécution des migrations Doctrine au démarrage |

Le build de l'image compile tout au moment du déploiement :
`composer install --no-dev`, `composer dump-env prod`, `asset-map:compile`
et `tailwind:build` (le projet reste sans Node.js, conformément à AGENTS.md).
L'image finale ne contient ni Composer ni les sources de dev, uniquement PHP,
FrankenPHP et l'application prête à servir.

## 1. Préparer le serveur (Debian 13)

```bash
# Installation de Docker Engine + plugin Compose
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian \
  trixie stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

# Utiliser docker sans root (se déconnecter/reconnecter ensuite)
sudo usermod -aG docker $USER
```

## 2. Récupérer le code et configurer l'environnement

```bash
git clone https://github.com/zet74prof/conventio2.git
cd conventio2
```

Créer `.env.local` (non versionné) à la racine du projet :

```dotenv
# Domaine public (le certificat TLS est obtenu automatiquement par Caddy)
SERVER_NAME=conventio2.mon-domaine.fr
APP_SECRET=<générez avec: openssl rand -hex 32>
POSTGRES_PASSWORD=<mot de passe PostgreSQL fort>
```

> `SERVER_NAME` doit pointer en DNS vers l'IP du serveur, et les ports 80 et 443
> (TCP et UDP) doivent être ouverts : FrankenPHP/Caddy obtient et renouvelle le
> certificat HTTPS tout seul.

## 3. Lancer la stack

```bash
docker compose -f compose.prod.yaml up -d --build --wait
```

Au premier démarrage, le conteneur `php` attend que PostgreSQL soit prêt puis
exécute `doctrine:migrations:migrate`. L'application est ensuite servie sur
https://conventio2.mon-domaine.fr

Commandes utiles :

```bash
docker compose -f compose.prod.yaml logs -f php          # suivre les logs
docker compose -f compose.prod.yaml ps                   # état des services
docker compose -f compose.prod.yaml exec php php bin/console about
```

## 4. Mettre à jour l'application

```bash
git pull
docker compose -f compose.prod.yaml up -d --build --wait
```

Le nouveau code est compilé dans une image neuve, les migrations sont rejouées
automatiquement, et les volumes (base de données, certificats TLS, `var/share`)
sont conservés.
