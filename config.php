<?php

use App\Tools\EnvManager;


/**
 * Configure file
 *
 * This file allows you to define essential application settings in one centralized location.
 * It serves as the primary place to specify configuration values,
 * ensuring the application works as intended.
 *
 * If env
 */




EnvManager::putEnv('SOCKS5_HOST', '0.0.0.0'); // The host address for the SOCKS5 proxy
EnvManager::putEnv('SOCKS5_PORT', 8700);      // The port number for the SOCKS5 proxy
EnvManager::putEnv('SOCKS5_AUTH_ENABLE', false); // Enable or disable authentication
EnvManager::putEnv('SOCKS5_USERNAME', false);   // Username for authentication (if enabled)
EnvManager::putEnv('SOCKS5_PASSWORD', false);   // Password for authentication (if enabled)