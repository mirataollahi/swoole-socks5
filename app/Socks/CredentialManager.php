<?php

namespace App\Socks;

class CredentialManager
{
    /**
     * @var array<string,Credential> All users auth credentials list
     */
    public array $credentials = [];

    /** Initialize and load all users credential info */
    public function __construct()
    {
        $this->add('root','password');
    }

    /** Add a user auth credential */
    public function add(string $username,string $password): void
    {
        $credential = Credential::make()
            ->setUsername($username)
            ->setPassword($password);
        $this->credentials[$credential->getUsername()] = $credential;
    }
}