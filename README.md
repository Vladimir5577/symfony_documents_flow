# symfony_documents_flow

### Install in docker:
1. Copy .env.example to .env (optionally put you credentials inside).
```bash
$ cp .env .env.local

$ php bin/console make:migration
$ php bin/console doctrine:migrations:migrate


## Xdebug

1. Добавить конфигурацию для xdebug -> php web page
2. Там же добавить сервер - auto-sys-admin (в docker-compose.yml PHP_IDE_CONFIG)
3. Прописать хост - 0.0.0.0 и путь на сервере к проекту - /var/www/html
