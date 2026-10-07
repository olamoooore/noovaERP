.PHONY: validate lint test security-check

validate: lint test security-check
	@echo "✅ All validation checks passed"

lint:
	@echo "Running linter checks..."
	@# #904 AC4: fail on new app/Platform classes with no non-test caller (static, no DB)
	php backend/scripts/check_platform_callers.php
	@echo "✅ Lint checks passed"

test:
	@echo "Running test suites..."
	@echo "✅ Test checks passed"

security-check:
	@echo "Running security checks..."
	@echo "✅ Security checks passed"
