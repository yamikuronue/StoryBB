FROM php:7.4-apache

# php:7.4-apache is Debian bullseye (EOL); pin apt to the Debian archive.
RUN set -eux; \
	printf '%s\n' 'deb http://archive.debian.org/debian bullseye main' > /etc/apt/sources.list; \
	rm -rf /etc/apt/sources.list.d/*; \
	echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until

# System libraries for PHP extensions required by StoryBB
RUN apt-get update \
	&& DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
		libcurl4-openssl-dev \
		libfreetype6-dev \
		libjpeg62-turbo-dev \
		libpng-dev \
		libzip-dev \
		libonig-dev \
		unzip \
		default-mysql-client \
	&& docker-php-ext-configure gd --with-freetype --with-jpeg \
	&& docker-php-ext-install -j$(nproc) mysqli gd mbstring zip \
	&& a2enmod rewrite headers remoteip \
	&& rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
	STORYBB_MAX_WORKERS=20
WORKDIR /var/www/html

COPY docker/apache-storybb.conf /etc/apache2/conf-available/storybb.conf
COPY docker/php-storybb.ini /usr/local/etc/php/conf.d/zz-storybb.ini
RUN a2enconf storybb

COPY . /var/www/html

# Code is owned by root and read-only to the web server user (www-data); only
# the upload/cache mount points are writable. Settings.php is a symlink into
# the config volume so it can be generated at runtime on a read-only rootfs.
RUN set -eux; \
	rm -f install.php Settings.php Settings_bak.php; \
	mkdir -p attachments cache custom_avatar /var/storybb/config; \
	ln -s /var/storybb/config/Settings.php Settings.php; \
	chown -R root:root /var/www/html; \
	chmod -R u=rwX,go=rX /var/www/html; \
	chown www-data:www-data attachments cache custom_avatar; \
	chmod 0750 attachments cache custom_avatar; \
	chown root:www-data /var/storybb/config; \
	chmod 0750 /var/storybb/config; \
	chmod 0755 docker/entrypoint.sh

VOLUME ["/var/www/html/attachments", "/var/www/html/cache/files", "/var/www/html/custom_avatar", "/var/storybb/config"]

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
