# symfony_documents_flow

### Install in docker:
1. Copy .env.example to .env (optionally put you credentials inside).
```bash
$ cp .env .env.local

$ php bin/console make:migration
$ php bin/console doctrine:migrations:migrate
