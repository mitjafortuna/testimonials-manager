# Deploying to Fly.io

Two Fly apps: `tm-dfvu` (this repo's Dockerfile, Apache+PHP) and `tm-dfvu-db` (stock `mysql:8.0` on a volume, private network only).

## One-time setup (already done for the demo)

```bash
# database
cd deploy/mysql
fly apps create tm-dfvu-db
fly volumes create mysqldata --size 1 --region fra -a tm-dfvu-db --yes
fly secrets set MYSQL_ROOT_PASSWORD=<random> MYSQL_PASSWORD=<random> -a tm-dfvu-db
fly deploy -a tm-dfvu-db --ha=false
cd ../..

# app
fly apps create tm-dfvu
fly volumes create uploads --size 1 --region fra -a tm-dfvu --yes
fly secrets set DB_HOST=tm-dfvu-db.internal DB_PASS=<same MYSQL_PASSWORD> LANDINGS_API_KEY=<key> -a tm-dfvu
fly deploy -a tm-dfvu --ha=false
```

The release command (`php bin/install.php`) creates the schema and demo data on first deploy and is a no-op afterwards.

## Continuous deployment

`.github/workflows/deploy.yml` runs `flyctl deploy --remote-only` on every push to `main`, using the `FLY_API_TOKEN` repository secret.

## Operations

- Logs: `fly logs -a tm-dfvu`
- Shell: `fly ssh console -a tm-dfvu`
- Re-seed: `fly ssh console -a tm-dfvu -C "php bin/install.php"`
- Rotate the upstream key: `fly secrets set LANDINGS_API_KEY=… -a tm-dfvu`
