#!/bin/bash

# 1. Patch the Nginx Template to allow 50MB uploads
# We use substitution because it's safer than append in this context
sed -i 's/server {/server { client_max_body_size 50M;/g' /assets/nginx.template.conf

# 2. Run the standard Nixpacks start sequence
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf & nginx -c /nginx.conf)