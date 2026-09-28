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

### DigitalOcean (app + Managed MySQL)

1. Create a Managed MySQL database (or MySQL on another droplet). Create a database and user for StoryBB. Note the host, port (often `25060` for DO managed), user, and password. Allow the app droplet/App Platform to connect (Trusted Sources / VPC).
2. On the droplet (or in App Platform env vars), set:

```bash
cp .env.example .env
# Set STORYBB_BOARDURL to your public HTTPS URL
# Set STORYBB_DB_SERVER / STORYBB_DB_PORT / STORYBB_DB_NAME / STORYBB_DB_USER / STORYBB_DB_PASSWD
# Set a strong STORYBB_ADMIN_PASSWORD
docker compose up -d --build
```

The container reaches MySQL over the network using `STORYBB_DB_SERVER` — there is no database container in the default stack.

Persistent volumes store attachments, cache, avatars, and `Settings.php`. Put a reverse proxy (nginx/Caddy) or DigitalOcean Load Balancer in front for HTTPS, and keep `STORYBB_BOARDURL` on `https://...`.

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
| `STORYBB_ADMIN_*` | First administrator username, password, email |
| `STORYBB_FORCE_RECONFIG` | `1` to rewrite `Settings.php` from env on start |
| `STORYBB_FORCE_REINSTALL` | `1` to drop/recreate the DB and reinstall (destructive; needs DROP privilege) |

Compose files:

* [`docker-compose.yml`](docker-compose.yml) — `web` only (DigitalOcean / external MySQL)
* [`docker-compose.local.yml`](docker-compose.local.yml) — adds local MariaDB for development

### Notes

* Set `STORYBB_BOARDURL` to the URL browsers actually use.
* Do not place `other/install.php` in the web root; the Docker image intentionally omits the browser installer.
* Change default admin credentials before exposing the stack publicly.
* On Managed MySQL, create the empty database ahead of time if the app user cannot `CREATE DATABASE`.



### Browser preview note

In Cloud Agent environments, open the published Compose port (default **8787**), not bare `http://127.0.0.1/` without a port — host-network demos on port 80 are not always forwarded to your local browser.
