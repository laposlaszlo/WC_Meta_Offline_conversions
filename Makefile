SHELL := /bin/bash

.PHONY: bump tag zip release changelog release-all release-all-push

bump:
	@if [ -z "$(VERSION)" ]; then echo "Usage: make bump VERSION=1.0.1"; exit 1; fi
	./scripts/bump-version.sh $(VERSION)

tag:
	@if [ -z "$(VERSION)" ]; then echo "Usage: make tag VERSION=1.0.1"; exit 1; fi
	./scripts/bump-version.sh $(VERSION) --tag

zip:
	./scripts/build-release.sh

changelog:
	@if [ -z "$(VERSION)" ]; then echo "Usage: make changelog VERSION=1.0.1"; exit 1; fi
	./scripts/update-changelog.sh $(VERSION)

release: tag zip
	@echo "Tag created and zip built. Next: git push origin v$(VERSION)"

release-all: bump changelog
	@if [ -z "$(VERSION)" ]; then echo "Usage: make release-all VERSION=1.0.1 MESSAGE=\"Release v1.0.1\""; exit 1; fi
	@if [ -z "$(MESSAGE)" ]; then echo "Usage: make release-all VERSION=1.0.1 MESSAGE=\"Release v1.0.1\""; exit 1; fi
	git add meta-offline-conversions/meta-offline-conversions.php CHANGELOG.md
	git commit -m "$(MESSAGE)"
	git tag -a v$(VERSION) -m "$(MESSAGE)"
	./scripts/build-release.sh
	@echo "Done. Next: git push origin v$(VERSION) && git push"

release-all-push: release-all
	git push
	git push origin v$(VERSION)
