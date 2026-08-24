<?php

namespace App\Types;

enum ProxyServer
{
    /** Socks5 standard proxy server */
    case SOCKS5;

    /** Http standard proxy server */
    case HTTP;
}