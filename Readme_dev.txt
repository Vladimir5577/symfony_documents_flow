    Check php grpc

$ php -m | grep grpc

// =============================================

    Database
    --------
$ php bin/console doctrine:database:drop --force
$ php bin/console doctrine:database:create


    Cache
    -----
$ php bin/console cache:clear --env=prod
$ php bin/console cache:warmup --env=prod


    Install GRPC to docker
    -----------------------

This is too heavy cpu process and takes long time

Only for getting binary

# Install gRPC extension (compilation ~15-20 min; strip reduces binary for later extraction)
RUN apt-get update && apt-get install -y build-essential libz-dev \
    && MAKEFLAGS="-j$(nproc)" pecl install grpc \
    && strip --strip-debug /usr/local/lib/php/extensions/*/grpc.so \
    && docker-php-ext-enable grpc \
    && apt-get purge -y build-essential \
    && apt-get autoremove -y


After build download binary file from container to next use

$ docker cp php_container:$(docker exec php_container find /usr/local/lib/php/extensions -name grpc.so) ./grpc.so

