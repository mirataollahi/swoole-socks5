FROM phpswoole/swoole:5.0.1-php8.2-alpine

RUN set -ex \
    && pecl channel-update pecl.php.net

# Set the working directory in the container
WORKDIR /app

# Copy the project files to the container
COPY . /app

# Expose the port your Swoole server will listen on
EXPOSE 9501

# Command to run your server
CMD ["php", "server.php"]

