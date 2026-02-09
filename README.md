# symfony_documents_flow

### Install in docker:
1. Copy .env.example to .env (optionally put you credentials inside).
```bash
$ cp .env .env.local
````

2. Build docker --- only for the first time
```bash
$ docker-compose build
```

3. Run in docker
```bash
$ docker-compose up
```

4. Go to php container 
```bash
$  docker exec -it php_container bash
```

5. Inside container install dependancies
```bash
$ composer install
```

6. Go to database - in browser and make sure database created
```web
http://localhost:8087/
```

7. Run migrations (make sure that database exist)
```bash
$ php bin/console doctrine:migrations:migrate
```

8. Optionally load fixtures
```bash
$ php bin/console doctrine:fixtures:load --group=base
```

## Xdebug

1. Добавить конфигурацию для xdebug -> php web page
2. Там же добавить сервер - don_stroy (в docker-compose.yml PHP_IDE_CONFIG)
3. Прописать хост - 0.0.0.0 и путь на сервере к проекту - /var/www/html


## Develop docks

    Create
    ------
$ php bin/console make:controller PageController
    create entity and repository
$ php bin/console make:entity


drop database don_stroy_mash;
create database don_stroy_mash;

// ============================

Mugration
$ php bin/console make:migration
$ php bin/console doctrine:migrations:migrate

    Load basic admin fixturex
$ php bin/console doctrine:fixtures:load --group=admin   --- only one fixture
$ php bin/console doctrine:fixtures:load --group=base   --- only one fixture
// ============================

    Debug routing
$ php bin/console debug:router

// ============================

Fixture
$ php bin/console make:fixture RoleFixtures  


$ php bin/console doctrine:fixtures:load    ---- run all fixtures and purge db
$ php bin/console doctrine:fixtures:load --group=roles              --- will be purged
$ php bin/console doctrine:fixtures:load --group=roles --append   --- without purge

// ==================================

1. Установка LibreOffice на сервер для ковертации ворд в пдф

Ubuntu/Debian:

sudo apt-get update
sudo apt-get install libreoffice

// ==================================
Convert html to pdf

apt-get update
apt-get install -y wget fontconfig libxrender1 xfonts-75dpi xfonts-base

wget https://github.com/wkhtmltopdf/packaging/releases/download/0.12.6-1/wkhtmltox_0.12.6-1.buster_amd64.deb

dpkg -i wkhtmltox_0.12.6-1.buster_amd64.deb
