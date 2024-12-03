<?php
/**
 * User: Mirataollahi ( @Mirataollahi124 )
 * Date: 12/3/24  Time: 2:48 PM
 */

namespace App\Types;

interface AuthMethod
{
    /**
     * This method means that the client does not require any authentication.
     * The client and server can proceed directly to the connection stage without any additional authentication steps
     */
    public const NOT_AUTH = 0x00; // No Authentication Required  [0x00]

    /**
     * his method indicates that the client supports username and password authentication.
     * The client will need to provide a valid username and password to the server.
     */
    public const USER_PASS_AUTH = 0x02; // Username/Password Authentication [0x02]

    /**
     * his method specifies that the client supports GSSAPI (Generic Security Services Application Program Interface) authentication.
     * GSSAPI is typically used for more secure or enterprise-level authentication, like Kerberos .
     * Kerberos is a protocol for authenticating service requests between trusted hosts across an untrusted network, such as the internet.
     */
    public const GSS_API_AUTH = 0x01; // GSSAPI Authentication [0x01]


    /**
     * This method is a special flag that indicates the client does not support any authentication methods,
     * or the authentication method provided by the client is not supported by the server.
     * This results in the SOCKS5 connection being rejected
     */
    public const NO_ACCEPTABLE_AUTH = 0xff; // No Acceptable Authentication Method [0xff]
}