#!/bin/bash

# 1. Configure PHP upload limits
cat > /assets/php-fpm-overrides.conf << 'EOF'
upload_max_filesize = 50M
post_max_size = 50M
memory_limit = 512M
max_execution_time = 300
EOF

# 2. Patch the Nginx Template to allow 50MB uploads
if grep -q "client_max_body_size" /assets/nginx.template.conf; then
    sed -i 's/client_max_body_size [^;]*;/client_max_body_size 50M;/g' /assets/nginx.template.conf
else
    sed -i 's/server {/server {\n    client_max_body_size 50M;/g' /assets/nginx.template.conf
fi

# 3. Start PHP-FPM with custom config and Nginx
node /assets/scripts/prestart.mjs /assets/nginx.template.conf /nginx.conf && (php-fpm -y /assets/php-fpm.conf -d upload_max_filesize=50M -d post_max_size=50M -d memory_limit=512M & nginx -c /nginx.conf)