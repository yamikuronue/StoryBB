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
	&& a2enmod rewrite headers \
	&& rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html
WORKDIR /var/www/html

# Allow .htaccess overrides for StoryBB routing
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
	&& sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
	&& printf '%s\n' \
		'<Directory /var/www/html>' \
		'    Options FollowSymLinks' \
		'    AllowOverride All' \
		'    Require all granted' \
		'</Directory>' \
		> /etc/apache2/conf-available/storybb.conf \
	&& a2enconf storybb

COPY . /var/www/html

# Writable runtime directories; never expose the web installer at the document root
RUN mkdir -p attachments cache custom_avatar \
	&& rm -f install.php \
	&& chown -R www-data:www-data /var/www/html \
	&& chmod -R ug+rwX attachments cache custom_avatar \
	&& chmod +x /var/www/html/docker/entrypoint.sh

VOLUME ["/var/www/html/attachments", "/var/www/html/cache", "/var/www/html/custom_avatar"]

EXPOSE 80

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
