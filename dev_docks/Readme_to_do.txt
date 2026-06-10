TODO: media CORS and resize fallback

1) Validate nginx config:
   nginx -t

2) Reload nginx (inside container or service):
   nginx -s reload
   or
   docker compose restart nginx

3) Verify media resize endpoint:
   - Open /media/cache/... URL from frontend.
   - Confirm image is generated and returned.

4) Verify CORS headers for media:
   - Access-Control-Allow-Origin
   - Access-Control-Allow-Methods
   - Access-Control-Allow-Headers

5) Production hardening:
   - Replace wildcard origin (*) with explicit frontend origin.
   - Keep CORS only for required paths (/media/, /uploads/).

6) Temporary workaround note:
   - A temporary CORS + fallback workaround was added for /media/ due to split internal/external host:port setup.
   - Remove this workaround after migrating to a normal single domain setup (same origin, no internal/external ports).
