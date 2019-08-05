ARG BASE_IMAGE
FROM $BASE_IMAGE

RUN wget -O /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
RUN cd /usr/bin && wget http://elasticorn.net/elasticorn.phar && wget http://elasticorn.net/elasticorn.phar.pubkey && chmod +x elasticorn.phar
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y php-yaml php7.2-ldap

RUN npm install --unsafe-perm -g gulp-cli
