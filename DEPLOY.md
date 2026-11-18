# Déploiement Debian 13 + Docker + FrankenPHP

Ce dépôt contient tout le nécessaire pour faire tourner l'application avec
[FrankenPHP](https://frankenphp.dev) (PHP 8.4 embarquant le serveur web Caddy)
sur un serveur Debian 13 (« trixie ») **interne**. Le HTTPS public est terminé
sur le reverse-proxy de l'établissement, qui redirige
`https://conventio-dev.lycee-faure.fr` vers
`http://conventio-dev.bts-sio.local` (voir section 5).

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

# Créer le fichier d'environnement à partir de l'exemple
cp .env.prod.example .env.prod
chmod 600 .env.prod
$EDITOR .env.prod
```

Renseignez dans `.env.prod` (non versionné) au minimum :
`SERVER_NAME`, `APP_SECRET` et `POSTGRES_PASSWORD`.
Toutes les commandes Compose utilisent ensuite `.env.prod` :

```bash
docker compose --env-file .env.prod -f compose.prod.yaml up -d --build --wait
```

> `SERVER_NAME` (préfixé `http://`) doit pointer en DNS interne vers l'IP du
> serveur ; Caddy sert du HTTP simple, sans tentative de certificat. `TRUSTED_PROXIES`
> doit contenir l'IP du reverse-proxy.

## 3. Lancer la stack

```bash
docker compose --env-file .env.prod -f compose.prod.yaml up -d --build --wait
```

Au premier démarrage, le conteneur `php` attend que PostgreSQL soit prêt puis
exécute `doctrine:migrations:migrate`. L'application est ensuite joignable :

- depuis l'intérieur : http://conventio-dev.bts-sio.local
- depuis l'extérieur : https://conventio-dev.lycee-faure.fr (via le reverse-proxy)

Commandes utiles :

```bash
docker compose --env-file .env.prod -f compose.prod.yaml logs -f php          # suivre les logs
docker compose --env-file .env.prod -f compose.prod.yaml ps                   # état des services
docker compose --env-file .env.prod -f compose.prod.yaml exec php php bin/console about
```

## 4. Mettre à jour l'application

```bash
git pull
docker compose --env-file .env.prod -f compose.prod.yaml up -d --build --wait
```

Le nouveau code est compilé dans une image neuve, les migrations sont rejouées
automatiquement, et les volumes (base de données, certificats TLS, `var/share`)
sont conservés.

## 5. Exposition via le reverse-proxy

Architecture mise en œuvre :

```
Internet --HTTPS--> reverse-proxy (certificat conventio-dev.lycee-faure.fr)
                        |  HTTP (LAN)
                        v
           FrankenPHP sur le serveur Debian (port 80)
```

- Le certificat public est géré par le reverse-proxy. `SERVER_NAME` commençant
  par `http://`, Caddy ne fait pas d'ACME : il sert du HTTP simple, sans
  redirection ni certificat. Le flux interne entre proxy et serveur circule
  en clair sur le LAN de l'établissement.
- Limitez l'accès au port 80 du serveur aux seules IP des reverse-proxies
  (règle pare-feu), sinon l'application reste joignable en direct sans HTTPS.
- `TRUSTED_PROXIES` (= IP du reverse-proxy, passé à Symfony via la config
  `framework.trusted_proxies`) permet de tenir compte de `X-Forwarded-Proto`,
  de sécuriser les cookies de session et de générer des URLs `https`.
  Ne jamais mettre `*` ici.
- Le reverse-proxy doit transmettre `X-Forwarded-Proto: https`,
  `X-Forwarded-Host`, `X-Forwarded-For` et conserver l'en-tête `Host`.

## 6. Gérer les mots de passe de manière sécurisée

Principes appliqués à ce dépôt :

1. **Aucun secret dans Git.** Les valeurs réelles vivent uniquement dans
   `.env.prod` sur le serveur (non versionné, `chmod 600`, propriétaire de
   votre utilisateur). Seul `.env.prod.example`, avec des valeurs factices,
   est committé.
2. **Générez les secrets, ne les inventez pas.** Chaque secret vient d'un
   générateur aléatoire :
   ```bash
   openssl rand -hex 32      # APP_SECRET
   openssl rand -base64 24  # POSTGRES_PASSWORD
   ```
3. **Minimisez l'exposition.** Un utilisateur déclaré dans `.env.prod` sert
   uniquement `php` et `database` ; limitez les accès sudo sur le serveur.
   Gardez en tête que tout compte pouvant lancer Docker *ou* lire
   `.env.prod` voit les secrets (`docker inspect` les affiche en clair) :
   l'accès au groupe `docker` équivaut à un accès root.
4. **Rotation.** Pour changer `POSTGRES_PASSWORD`, modifiez `.env.prod` puis
   `docker compose --env-file .env.prod -f compose.prod.yaml up -d --force-recreate`
   (le mot de passe est répercuté par la variable d'environnement, la donnée
   elle-même est conservée dans le volume). Pour la base, préférez confirmer
   avec `ALTER USER conventio2 WITH PASSWORD '...'` si vous la modifiez à chaud.
5. **Sauvegardes.** Protégez aussi les sauvegardes :
   ```bash
   docker compose --env-file .env.prod -f compose.prod.yaml exec database \
     pg_dump -U conventio2 conventio2 | gzip | \
     openssl enc -aes-256-cbc -pbkdf2 -out conventio2-$(date +%F).sql.gz.enc
   ```
   Conservez la phrase de passe de sauvegarde séparément du serveur.

Pour un isolement plus fort (plusieurs serveurs, secrets partagés entre
services), regardez [Docker Secrets](https://docs.docker.com/compose/use-secrets/)
ou le [Symfony Secrets Vault](https://symfony.com/doc/current/configuration/secrets.html),
 qui chiffre les secrets dans le dépôt ; `docker compose --env-file` suffit
 pour un serveur unique comme ici.
