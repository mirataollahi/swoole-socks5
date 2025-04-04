<?php

use App\Tools\Config\EnvManager;


/**
 * Configure file
 */
EnvManager::putEnv('SOCKS5_HOST', '0.0.0.0'); // The host address for the SOCKS5 proxy
EnvManager::putEnv('SOCKS5_PORT', 8700);      // The port number for the SOCKS5 proxy
EnvManager::putEnv('SOCKS5_AUTH_ENABLE', false); // Enable or disable authentication
EnvManager::putEnv('SOCKS5_USERNAME', false);   // Username for authentication (if enabled)
EnvManager::putEnv('SOCKS5_PASSWORD', false);   // Password for authentication (if enabled)


/**
 * Running in debug mode show more logs
 */
EnvManager::putEnv('IS_DEBUG', false);



/**
 * Configure file
 */
EnvManager::putEnv('ADMIN_HOST', '0.0.0.0'); // The host address for the SOCKS5 proxy
EnvManager::putEnv('ADMIN_PORT', 8701);      // The port number for the SOCKS5 proxy





/**
 * Prometheus exporter configs (Optional)
 */
EnvManager::putEnv('PROMETHEUS_EXP_HOST', '0.0.0.0');
EnvManager::putEnv('PROMETHEUS_EXP_PORT', 8702);
