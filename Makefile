.PHONY: test lint stan format

lint:
./vendor/bin/pint

stan:
./vendor/bin/phpstan analyse

format:
./vendor/bin/pint --test

test:
php artisan test
