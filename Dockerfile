# Use the php:8.3-fpm-alpine base image
FROM php:8.3-fpm-alpine

# Set working directory to /var/www/html
WORKDIR /var/www/html

# Update packages and install dependencies.
# supercronic runs the scheduled jobs as any user (dcron needs root), and
# su-exec drops from root to www-data for them in the default root mode.
RUN apk upgrade --no-cache && \
    apk add --no-cache dumb-init shadow su-exec supercronic sqlite-dev libpng libpng-dev libjpeg-turbo libjpeg-turbo-dev freetype freetype-dev curl autoconf libgomp icu-dev icu-data-full nginx tzdata libzip-dev sqlite libwebp-dev && \
    docker-php-ext-install pdo pdo_sqlite calendar && \
    docker-php-ext-enable pdo pdo_sqlite && \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp && \
    docker-php-ext-install -j$(nproc) gd intl zip

# Upgrade again in a layer of its own so security fixes land on every build.
# The layer above only reruns when its text or the base image changes, so the
# build cache can otherwise ship packages that Alpine has since patched.
# CI passes a new APK_CACHE_BUST value each run to skip the cache here.
ARG APK_CACHE_BUST
RUN echo "apk cache bust: ${APK_CACHE_BUST}" && apk upgrade --no-cache

# Copy only what the app needs at runtime into the web root. Tests, dev
# scripts, docs and the container's own ops files stay out of it, so none of
# them can be requested over HTTP.
COPY --chown=www-data:www-data *.php robots.txt service-worker.js ./
COPY --chown=www-data:www-data api ./api
COPY --chown=www-data:www-data endpoints ./endpoints
COPY --chown=www-data:www-data images ./images
COPY --chown=www-data:www-data includes ./includes
COPY --chown=www-data:www-data libs ./libs
COPY --chown=www-data:www-data migrations ./migrations
COPY --chown=www-data:www-data scripts ./scripts
COPY --chown=www-data:www-data styles ./styles
COPY --chown=www-data:www-data webfonts ./webfonts
COPY --chown=www-data:www-data db ./db

# Container ops files live outside the web root
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/crontab /etc/tally/crontab
COPY docker/startup.sh /usr/local/bin/tally-startup
COPY docker/healthcheck.sh /usr/local/bin/tally-healthcheck

# Normalise line endings, make the scripts executable, and make the two data
# folders writable for any uid in group 0 too, so `--user <uid>:0` works as
# well as a chowned volume (OpenShift-style arbitrary uids).
RUN dos2unix /etc/tally/crontab /usr/local/bin/tally-startup /usr/local/bin/tally-healthcheck && \
    chmod 0644 /etc/tally/crontab && \
    chmod 0755 /usr/local/bin/tally-startup /usr/local/bin/tally-healthcheck && \
    mkdir -p /var/www/html/images/uploads/logos/avatars && \
    chown -R www-data:root /var/www/html/db /var/www/html/images/uploads/logos && \
    chmod -R g+rwX /var/www/html/db /var/www/html/images/uploads/logos && \
    echo 'pm.max_children = 15' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    echo 'pm.max_requests = 500' >> /usr/local/etc/php-fpm.d/zz-docker.conf && \
    printf 'upload_max_filesize = 256M\npost_max_size = 256M\nupload_tmp_dir = /tmp\nsession.save_path = /tmp\nsys_temp_dir = /tmp\n' > /usr/local/etc/php/conf.d/wallos-uploads.ini

# nginx listens on 80 in the default root mode, and on 8080 (or $PORT) when
# the container runs rootless.
EXPOSE 80 8080

ENTRYPOINT ["dumb-init", "--"]

# Requires docker engine 25+ for the --start-interval flag
HEALTHCHECK --interval=2m --timeout=2s --start-period=20s --start-interval=5s --retries=3 \
    CMD ["/usr/local/bin/tally-healthcheck"]

# Start PHP-FPM, nginx and the scheduled jobs
CMD ["/usr/local/bin/tally-startup"]
