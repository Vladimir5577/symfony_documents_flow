# symfony_documents_flow

### Install in docker:
1. Copy .env.example to .env (optionally put you credentials inside).
```bash
$ cp .env .env.local


Create
$ php bin/console make:controller PageController


Mugration
$ php bin/console make:migration
$ php bin/console doctrine:migrations:migrate

Fixture
$ php bin/console make:fixture RoleFixtures  


$ php bin/console doctrine:fixtures:load    ---- run all fixtures and purge db
$ php bin/console doctrine:fixtures:load --group=roles              --- will be purged
$ php bin/console doctrine:fixtures:load --group=roles --append   --- without purge



## Xdebug

1. Добавить конфигурацию для xdebug -> php web page
2. Там же добавить сервер - don_stroy (в docker-compose.yml PHP_IDE_CONFIG)
3. Прописать хост - 0.0.0.0 и путь на сервере к проекту - /var/www/html

