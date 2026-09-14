COMPOSE = docker compose
RUN     = $(COMPOSE) run --rm app

.PHONY: up down build sh install test unit integration api lint lint-fix stan e2e seed seed-large sync zip

up:            ## start app + db
	$(COMPOSE) up -d app
down:
	$(COMPOSE) down
build:
	$(COMPOSE) build
sh:
	$(COMPOSE) exec app bash
install:       ## composer install inside the container
	$(RUN) composer install
test:          ## all PHPUnit suites: unit, integration and the fixture-backed api suite (needs `make up`)
	$(MAKE) unit
	$(MAKE) integration
	$(MAKE) api
unit:
	$(RUN) composer test:unit
integration:
	$(RUN) composer test:integration
api:           ## API suite against a built-in PHP server inside the container (mirrors CI)
	$(COMPOSE) exec -T app pkill -f 'php -S 127.0.0.1:8081' >/dev/null 2>&1; $(COMPOSE) exec -e LANDINGS_API_FIXTURE=tests/fixtures/landings.json -e API_BASE_URL=http://127.0.0.1:8081 app sh -c "php -S 127.0.0.1:8081 -t public public/index.php >/tmp/php-server.log 2>&1 & sleep 1; composer test:api"
lint:
	$(RUN) composer lint
lint-fix:
	$(RUN) composer lint:fix
stan:
	$(RUN) composer stan
seed:          ## load schema + seed into the dev database
	$(COMPOSE) exec -T db mysql -uroot -proot testimonials < database/schema.sql
	$(COMPOSE) exec -T db mysql -uroot -proot testimonials < database/seed.sql
	$(COMPOSE) exec app php database/seed-images.php
seed-large:    ## generate a large demo dataset (300 products)
	$(COMPOSE) exec app php database/seed-large.php
sync:          ## run landing sync from the CLI
	$(COMPOSE) exec app php bin/sync.php
e2e:           ## Playwright happy-path against the running app (needs `make up && make seed`)
	$(COMPOSE) --profile e2e run --rm -e CI=1 playwright sh -c "npm ci && npx playwright test"
zip:
	./scripts/build-zip.sh
