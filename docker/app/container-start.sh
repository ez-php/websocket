#!/bin/bash
set -e
cd /var/www/html
composer install --no-interaction --prefer-dist
sleep infinity
