FROM debian:bookworm-slim

# Set working directory
WORKDIR /app

# Install required packages (adjust if swoole-cli has dependencies like libc, libstdc++, etc.)
RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        libstdc++6 \
        curl \
        bash \
    && rm -rf /var/lib/apt/lists/*

# Copy the project files to the container
COPY . /app

# Make sure the swoole-cli binary and proxy.sh are executable
RUN chmod +x /app/swoole-cli /app/proxy.sh

# Command to run your server
CMD ["sh", "proxy.sh"]

