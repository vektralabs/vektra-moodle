# Moodle dev environments for the assessment bot (plan assess-moodle-ws, group A).
COMPOSE = docker compose -f compose.moodle.yml

.PHONY: moodle43-up moodle43-down moodle43-reset moodle52-up moodle52-down moodle52-reset

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
