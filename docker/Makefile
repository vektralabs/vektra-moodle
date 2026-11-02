# Moodle dev environments for the assessment bot (plan assess-moodle-ws, group A).
COMPOSE = docker compose -f compose.moodle.yml

.PHONY: moodle43-up moodle43-down moodle43-reset moodle52-up moodle52-down moodle52-reset \
	moodle43-provision moodle52-provision moodle43-devdeps moodle52-devdeps moodle43-seed moodle52-seed moodle43-check moodle52-check

moodle43-up:
	$(COMPOSE) --env-file env.43 up -d --build
moodle43-down:
	$(COMPOSE) --env-file env.43 down
moodle43-reset: ## removes the 4.3 database and moodledata volumes
	$(COMPOSE) --env-file env.43 down -v

moodle52-up:
	$(COMPOSE) --env-file env.52 up -d --build
moodle52-down:
	$(COMPOSE) --env-file env.52 down
moodle52-reset: ## removes the 5.2 database and moodledata volumes
	$(COMPOSE) --env-file env.52 down -v

# Provisioning, dev deps and fixtures (run after the container is up and installed).
# The tokens in fixtures/out/provision-*.json are secrets: that directory is git-ignored.
moodle43-provision:
	docker exec -u www-data -e ASSESS_OUT=/opt/vektra/fixtures/out/provision-m43.json vektra-moodle43 php /opt/vektra/provision/assess_service_account.php
moodle52-provision:
	docker exec -u www-data -e ASSESS_OUT=/opt/vektra/fixtures/out/provision-m52.json vektra-moodle52 php /opt/vektra/provision/assess_service_account.php
moodle43-devdeps:
	docker exec -u root vektra-moodle43 bash /opt/vektra/fixtures/prepare_dev_deps.sh
moodle52-devdeps:
	docker exec -u root vektra-moodle52 bash /opt/vektra/fixtures/prepare_dev_deps.sh
moodle43-seed:
	docker exec -u www-data vektra-moodle43 php /opt/vektra/fixtures/seed_assess_fixtures.php
moodle52-seed:
	docker exec -u www-data vektra-moodle52 php /opt/vektra/fixtures/seed_assess_fixtures.php
moodle43-check:
	docker exec -u www-data vektra-moodle43 php /opt/vektra/fixtures/seed_assess_fixtures.php --check
moodle52-check:
	docker exec -u www-data vektra-moodle52 php /opt/vektra/fixtures/seed_assess_fixtures.php --check
