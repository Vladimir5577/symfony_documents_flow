================================================================================
Мониторинг проекта + метрики электронной подписи
================================================================================

СТЕК (docker-compose.monitoring.yml)
--------------------------------------------------------------------------------
Prometheus (http://localhost:9090) — сбор метрик, retention 15 дней.
Grafana    (http://localhost:3000, admin/admin) — дашборды.
postgres-exporter (:9187) — метрики PostgreSQL.
nginx-exporter    (:9113) — метрики Nginx (stub_status).
Loki/Promtail НЕТ. Готового PHP-экспортера (prometheus_client_php) НЕТ,
и по правилам проекта новые composer-зависимости не добавляются.

Запуск:
  docker compose up -d
  docker compose -f docker-compose.monitoring.yml up -d
Конфиг scrape'ов: docker_env/prometheus/prometheus.yml (сеть project-net).

МЕТРИКИ ПОДПИСАНИЯ — ДВА ИСТОЧНИКА
--------------------------------------------------------------------------------

1) Console-команда app:signature:metrics (счётчики из БД)
   src/Command/SignatureMetricsCommand.php

   docker exec php_container bin/console app:signature:metrics

   JSON-вывод:
     signatures_total.simple      — всего ПЭП-подписей (document_signature)
     signatures_total.enhanced    — всего УНЭП-подписей
     declines_total               — отказов от подписания (document_history,
                                    action LIKE 'signing_declined%')
     documents_on_signing         — документов в статусе ON_SIGNING
     documents_signed             — документов в статусе SIGNED
     certificates_active          — действующих сертификатов (active, не истёк)
     certificates_revoked         — отозванных сертификатов
     certificates_expiring_30d    — активных сертификатов, истекающих в 30 дней

2) Структурированные логи, канал monolog `signature` (события)
   var/log/signature.log, формат JSON (monolog.formatter.json).
   Пишут: SigningService и SignatureVerificationService.

   События (поле message / context.event):
     signature.signed              level=simple|enhanced, document_id,
                                   signer_id, fully_signed
     signature.declined            document_id, signer_id
     signature.verification_failed reason=hash_mismatch|invalid_signature|
                                   certificate_expired|certificate_revoked,
                                   document_id, signature_id

КАК ПОДКЛЮЧИТЬ К PROMETHEUS
--------------------------------------------------------------------------------
Вариант A (рекомендуемый, без новых зависимостей): node_exporter textfile
collector. Cron на хосте раз в минуту:

  docker exec php_container bin/console app:signature:metrics \
    | jq -r '"signature_total{level=\"simple\"} \(.signatures_total.simple)",
             "signature_total{level=\"enhanced\"} \(.signatures_total.enhanced)",
             "signature_declines_total \(.declines_total)",
             "documents_on_signing \(.documents_on_signing)",
             "documents_signed \(.documents_signed)",
             "certificates_active \(.certificates_active)",
             "certificates_revoked \(.certificates_revoked)",
             "certificates_expiring_30d \(.certificates_expiring_30d)"' \
    > /var/lib/node_exporter/textfile/signature.prom.tmp \
  && mv /var/lib/node_exporter/textfile/signature.prom.tmp \
        /var/lib/node_exporter/textfile/signature.prom

  (node_exporter запускать с --collector.textfile.directory=
   /var/lib/node_exporter/textfile и добавить job в prometheus.yml.)

Вариант B: любой внешний агент/скрипт, который дергает команду и шлёт
куда угодно (Zabbix, Telegraf exec-input и т.п.) — формат JSON стабильный.

Ошибки проверки (verification_failed) в БД не хранятся — считать по
var/log/signature.log (grep '"verification_failed"' | wc -l, либо promtail,
если Loki когда-нибудь появится).

РЕКОМЕНДУЕМЫЕ АЛЕРТЫ
--------------------------------------------------------------------------------
- certificates_expiring_30d > 0        — предупреждение: пора перевыпускать.
- rate(verification_failed) > 0        — кто-то подсовывает подменённые файлы
                                          или битые подписи; смотреть signature.log
                                          (reason=hash_mismatch — подмена файла).
- documents_on_signing долго не убывает — зависшие маршруты подписания.

СМЕЖНОЕ
--------------------------------------------------------------------------------
var/log/ca.log — аудит УЦ (канал `ca`): выпуск/отзыв/перевыпуск сертификатов.
Подробно про подпись: dev_docks/signature/Readme_signature.txt.
