<?php
/**
 * User: Taha ( @Taha124 )
 * Date: 6/10/25  Time: 3:33 PM
 */

namespace App\HttpProxy;

enum HttpProxyResult: string
{
    case HTTP_BAD_GATEWAY_502 = "HTTP/1.1 502 Bad Gateway\r\n\r\n";
    case HTTP_BAD_REQUEST_400 = "HTTP/1.1 400 Bad Request\r\n\r\n";
    case CONNECTION_ESTABLISHED_200 = "HTTP/1.1 200 Connection Established\r\n\r\n";
}