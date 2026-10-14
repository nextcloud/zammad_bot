# SPDX-FileCopyrightText: 2015-2024 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Makefile for building the project

app_name=zammad_bot

project_dir=$(CURDIR)/../$(app_name)
build_dir=$(CURDIR)/build/artifacts
appstore_dir=$(build_dir)/appstore
source_dir=$(build_dir)/source
sign_dir=$(build_dir)/sign
package_name=$(app_name)
cert_dir=$(HOME)/.nextcloud/certificates
version+=main
composer=$(shell which composer 2> /dev/null)

all: appstore

release: appstore create-tag

create-tag:
	git tag -a v$(version) -m "Tagging the $(version) release."
	git push origin v$(version)

appstore:
	mkdir -p $(sign_dir)
	rsync -a \
	--exclude=/.git \
	--exclude=/.github \
	--exclude=/.tx \
	--exclude=/build \
	--exclude=/docs \
	--exclude=/l10n/l10n.pl \
	--exclude=/node_modules \
	--exclude=/src \
	--exclude=/tests \
	--exclude=/vendor \
	--exclude=/vendor-bin \
	--exclude=/eslint.config.mjs \
	--exclude=/.l10nignore \
	--exclude=/.php-cs-fixer.cache \
	--exclude=/.php-cs-fixer.dist.php \
	--exclude=/.gitattributes \
	--exclude=/.gitignore \
	--exclude=/.scrutinizer.yml \
	--exclude=/.travis.yml \
	--exclude=/babel.config.js \
	--exclude=/composer.json \
	--exclude=/composer.lock \
	--exclude=/Makefile \
	--exclude=/package.json \
	--exclude=/package-lock.json \
	--exclude=/psalm.xml \
	--exclude=/README.md \
	--exclude=/rector.php \
	--exclude=/stylelint.config.js \
	--exclude=/webpack.js \
	$(project_dir)/ $(sign_dir)/$(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing app files…"; \
		php ../../occ integrity:sign-app \
			--privateKey=$(cert_dir)/$(app_name).key\
			--certificate=$(cert_dir)/$(app_name).crt\
			--path=$(sign_dir)/$(app_name); \
	fi
	tar -czf $(build_dir)/$(app_name).tar.gz \
		-C $(sign_dir) $(app_name)
	@if [ -f $(cert_dir)/$(app_name).key ]; then \
		echo "Signing package…"; \
		openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(build_dir)/$(app_name).tar.gz | openssl base64; \
	fi

