ARG BASE_IMAGE
ARG username
FROM $BASE_IMAGE

RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg

RUN npm install --unsafe-perm -g gulp-cli
USER $username
RUN COMPOSER_BIN_DIR=/usr/bin composer require t3g/elasticorn:^7.0.2
RUN chmod +x /usr/bin/elasticorn.php
