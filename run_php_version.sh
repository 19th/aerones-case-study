#!/bin/bash

# Navigate to the php_version directory
cd php_version

# Check if composer binary is in the PATH and install dependencies using Composer
if command -v composer >/dev/null 2>&1; then
    echo "Using Composer from PATH..."
    composer install
else
    if [ ! -f "composer.phar" ]; then
        echo "Downloading Composer..."
        php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
        php composer-setup.php
        php -r "unlink('composer-setup.php');"
    fi

    echo "Installing dependencies..."
    php composer.phar install
fi

# Run the PHP script
echo "Running file-download-guzzle.php..."
php file-download-guzzle.php -vvv