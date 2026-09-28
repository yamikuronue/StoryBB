# StoryBB

[![Build Status](https://img.shields.io/github/workflow/status/StoryBB/StoryBB/syntax.yml?branch=master)](https://github.com/StoryBB/StoryBB/actions/workflows/syntax.yml) [![Open Source Helpers](https://www.codetriage.com/storybb/storybb/badges/users.svg)](https://www.codetriage.com/storybb/storybb) [![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](https://opensource.org/licenses/BSD-3-Clause) 
![GitHub last commit](https://img.shields.io/github/last-commit/storybb/storybb/master.svg)

StoryBB is a piece of software designed to let you run a forum with a roleplayer-focused set of features.

The software is licensed under [BSD 3-clause license](https://opensource.org/licenses/BSD-3-Clause).

Contributions to documentation are licensed under [CC-by-SA 3](https://creativecommons.org/licenses/by-sa/3.0). Third party libraries or sets of images, are under their own licenses.

## How to submit a pull request:
* Just do a PR against the master branch with why it seems like a good idea

## Requirements
* MySQL 5.5.3 or higher
* PHP 7.1.3 or higher

#### Required PHP extensions
* cURL
* GD
* MySQLi if using MySQL

#### Optional PHP extensions
* mbstring
* iconv
* Imagick or MagickWand for ImageMagick support
* APCu/memcache/Redis/SQLite 3/Zend SHM cache

## Docker (non-interactive install)

StoryBB can run as a Docker container. On first boot it writes `Settings.php` from environment variables, creates the schema, seeds default data, and creates an admin account — no browser installer.

The default Compose file runs **only the web app** and expects an **external MySQL** (for example DigitalOcean Managed MySQL). A local MariaDB overlay is available for development.

### Security model

The container is built so that a compromised PHP request cannot modify the forum's code:

* The root filesystem is read-only (`read_only: true`), with all Linux capabilities dropped except the few Apache needs, and `no-new-privileges`.
* Code is owned by `root`; Apache/PHP workers run as `www-data` and can only write to the upload mounts.
* `Settings.php` lives in the config volume (symlinked into the document root), owned `root:www-data` and mode `0640`. The admin panel cannot rewrite it; edit it on the host instead.
* `attachments/`, `cache/` and `cache/files/` are never served directly (StoryBB streams them through PHP). `custom_avatar/` is served but PHP and scripts are disabled there.
* `cache/` is a tmpfs: compiled templates and other generated PHP are discarded on every restart. Persistent uploads that StoryBB keeps in `cache/files/` (smileys, favicons, affiliate images) are a separate volume.
* PHP shell functions (`exec`, `system`, `proc_open`, ...) are disabled.

Redeploying (`docker compose up -d --build`) always starts from a clean copy of the code.

### DigitalOcean Droplet (recommended)

A 1 GB / 1 vCPU Basic droplet ($6/month) plus a 10 GB Block Storage volume ($1/month) is enough for a small-to-medium forum when the database is DigitalOcean Managed MySQL.

1. **Database.** Create (or reuse) a Managed MySQL database and user for StoryBB. Add the droplet to the database's Trusted Sources. DO managed MySQL listens on port `25060`.
2. **Droplet and firewall.** Create an Ubuntu droplet with SSH-key login only. Attach a Cloud Firewall allowing inbound TCP 22, 80 and 443 (and UDP 443 for HTTP/3).
3. **Block Storage.** Attach a volume, then mount it at `/mnt/storybb` with `noexec,nosuid,nodev` so nothing on it can ever run as a program. In `/etc/fstab`:

   ```
   /dev/disk/by-id/scsi-0DO_Volume_storybb /mnt/storybb ext4 defaults,nofail,discard,noatime,nodev,nosuid,noexec 0 2
   ```

   Then `sudo mkdir -p /mnt/storybb && sudo mount -a`.
4. **Swap.** Building the image compiles PHP extensions, which can exceed 1 GB:

   ```bash
   sudo fallocate -l 2G /swapfile && sudo chmod 600 /swapfile && sudo mkswap /swapfile && sudo swapon /swapfile
   echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
   ```
5. **Host updates.** `sudo apt install unattended-upgrades` and install Docker Engine with the Compose plugin.
6. **Run StoryBB.** Point your domain's DNS at the droplet, then:

   ```bash
   cp .env.example .env
   # Set STORYBB_DOMAIN and STORYBB_BOARDURL=https://<domain>
   # Set STORYBB_DB_SERVER / STORYBB_DB_PORT / STORYBB_DB_NAME / STORYBB_DB_USER / STORYBB_DB_PASSWD
   # Set a strong STORYBB_ADMIN_PASSWORD
   docker compose -f docker-compose.yml -f docker-compose.droplet.yml up -d --build
   ```

Caddy obtains a TLS certificate automatically and is the only thing listening publicly; the StoryBB container is reachable only on the internal Docker network. Everything that must persist is under `/mnt/storybb`: `attachments/`, `files/`, `custom_avatar/`, `config/Settings.php` and `caddy/` (certificates). Enable volume snapshots to back it up.

The default `docker-compose.yml` on its own runs the same hardened container with Docker named volumes and publishes port `8787`, for use behind any other reverse proxy.

### Moving from an existing install

When pointing at a database from an older install:

* **Do not copy code or PHP files from the old server**, especially if it was ever compromised. Copy only the upload data into `/mnt/storybb/attachments/` (`*.dat` files and avatar images), and check for stray `.php`, `.phtml` or `.htaccess` files before copying.
* The database stores absolute paths from the old server. Update them to the container paths (use your table prefix in place of `sbb_`):

  ```sql
  SELECT variable, value FROM sbb_settings
    WHERE variable IN ('attachmentUploadDir', 'custom_avatar_dir', 'custom_avatar_url', 'smileys_dir');
  SELECT id_theme, variable, value FROM sbb_themes
    WHERE variable IN ('theme_dir', 'theme_url', 'images_url');
  ```

  Directory values should be under `/var/www/html` (e.g. `/var/www/html/attachments`, `/var/www/html/custom_avatar`, `/var/www/html/Themes/natural`), and URLs should start with your new `STORYBB_BOARDURL`. `attachmentUploadDir` is JSON, where `/` may appear escaped as `\/`.
* The installer detects the existing install and skips creating tables and the admin account; it only writes a fresh `Settings.php` from your environment variables.

### Local development (app + MariaDB)

```bash
cp .env.example .env
# For local DB overlay you can use:
#   STORYBB_BOARDURL=http://localhost:8787
#   STORYBB_DB_SERVER=db
#   STORYBB_DB_PORT=3306
#   STORYBB_DB_USER=storybb
#   STORYBB_DB_PASSWD=storybb
#   STORYBB_ADMIN_PASSWORD=changeme
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
```

Then open [http://localhost:8787](http://localhost:8787) and sign in with the admin account from `.env`.

### Configuration

All forum bootstrap settings are environment variables (see [`.env.example`](.env.example)):

| Variable | Purpose |
| --- | --- |
| `STORYBB_BOARDURL` | Public URL of the forum (no trailing slash) |
| `STORYBB_FORUM_NAME` | Forum display name |
| `STORYBB_DB_*` | Database host, port, name, user, password, prefix |
| `STORYBB_DB_SSL` / `STORYBB_DB_SSL_CA` | `1` to encrypt the MySQL connection (required for DO managed MySQL); optional CA certificate path to also verify the server |
| `STORYBB_ADMIN_*` | First administrator username, password, email |
| `STORYBB_FORCE_RECONFIG` | `1` to rewrite `Settings.php` from env on start |
| `STORYBB_FORCE_REINSTALL` | `1` to drop/recreate the DB and reinstall (destructive; needs DROP privilege) |
| `STORYBB_MAX_WORKERS` | Max concurrent Apache/PHP workers (default `20`, sized for 1 GB RAM) |
| `STORYBB_DOMAIN` | Droplet overlay: hostname Caddy gets a TLS certificate for |
| `STORYBB_DATA_DIR` | Droplet overlay: Block Storage mount point (default `/mnt/storybb`) |

Compose files:

* [`docker-compose.yml`](docker-compose.yml) — hardened `web` only (external MySQL, named volumes)
* [`docker-compose.droplet.yml`](docker-compose.droplet.yml) — adds Caddy (HTTPS) and Block Storage bind mounts for a DigitalOcean droplet
* [`docker-compose.local.yml`](docker-compose.local.yml) — adds local MariaDB for development

### Notes

* Set `STORYBB_BOARDURL` to the URL browsers actually use.
* Do not place `other/install.php` in the web root; the Docker image intentionally omits the browser installer.
* Change default admin credentials before exposing the stack publicly.
* On Managed MySQL, create the empty database ahead of time if the app user cannot `CREATE DATABASE`.



### Browser preview note

In Cloud Agent environments, open the published Compose port (default **8787**), not bare `http://127.0.0.1/` without a port — host-network demos on port 80 are not always forwarded to your local browser.
