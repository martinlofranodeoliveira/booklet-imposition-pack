FROM php:8.4-cli-bookworm@sha256:54b467ede1db96c66675e7b78457dd43729d0e7be31978d4a1e5c069ceabcf76
RUN apt-get update && apt-get install -y --no-install-recommends qpdf poppler-utils python3 libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip ca-certificates
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install -j2 gd
RUN php -r "if (!copy('https://getcomposer.org/download/2.11.1/composer.phar', '/usr/local/bin/composer')) { exit(1); }" \
    && echo '6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c  /usr/local/bin/composer' | sha256sum -c - \
    && chmod 755 /usr/local/bin/composer
RUN useradd --create-home --uid 10001 --shell /usr/sbin/nologin booklet
WORKDIR /app
COPY --chown=booklet:booklet . /app
RUN chown booklet:booklet /app
USER booklet
ENV HOME=/home/booklet TMPDIR=/tmp
RUN composer install --no-plugins --no-scripts --no-interaction --prefer-dist \
    && composer --version && composer show setasign/fpdi && composer show setasign/fpdf \
    && qpdf --version && pdftoppm -v && python3 --version && php --ri gd
CMD ["php", "bin/booklet.php", "demo", "--assert"]
