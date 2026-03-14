.DEFAULT_GOAL := help

.PHONY: help
help: ## Show available targets
	@awk 'BEGIN{FS=":.*##";print "\nUsage: make <target>\n"} /^[a-zA-Z0-9_.-]+:.*##/ {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# ===================================
# Code Quality
# ===================================

.PHONY: install
install: ## Install composer dependencies
	composer install

.PHONY: cgl
cgl: ## Check code style (dry-run)
	composer ci:test:php:cgl

.PHONY: cgl-fix
cgl-fix: ## Fix code style
	composer ci:cgl

.PHONY: phpstan
phpstan: ## Run PHPStan static analysis
	composer ci:test:php:phpstan

.PHONY: test
test: test-unit ## Run unit tests

.PHONY: test-unit
test-unit: ## Run unit tests
	composer ci:test:php:unit

.PHONY: test-functional
test-functional: ## Run functional tests (requires MySQL)
	composer ci:test:php:functional

.PHONY: test-fuzz
test-fuzz: ## Run fuzz tests
	.Build/bin/phpunit -c Build/phpunit.xml --testsuite fuzz --no-coverage

.PHONY: mutation
mutation: ## Run mutation tests (Infection)
	composer ci:mutation

.PHONY: ci
ci: cgl phpstan test-unit test-fuzz ## Run all local CI checks (no DB required)

.PHONY: clean
clean: ## Clean temporary files and caches
	rm -rf .php-cs-fixer.cache var/ infection.log infection-summary.log .phpunit.result.cache coverage/
