FROM php:8.3-cli

RUN docker-php-ext-install pdo pdo_mysql && \
    apt-get update && apt-get install -y libcurl4-openssl-dev && \
    docker-php-ext-install curl

WORKDIR /app
COPY . .

CMD php -S 0.0.0.0:$PORT -t .
