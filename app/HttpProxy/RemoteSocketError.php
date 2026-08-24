<?php

namespace App\HttpProxy;

enum RemoteSocketError
{
    case RECEIVE_ERROR;
    case SEND_ERROR;
    case CONNECT_ERROR;
}