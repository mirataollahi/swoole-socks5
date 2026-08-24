<?php

namespace App\Socks;

class Credential
{
    /**
     * @var string User socks5 auth username
     */
    private string $username;

    /**
     * @var string|null User auth password
     */
    private ?string $password = null;

    /**
     * @return string Get the auth credential username
     */
    public function getUsername(): string
    {
        return $this->username;
    }

    /** Create an instance of credential */
    public static function make(): self
    {
        return new self();
    }

    /**
     * Set a user credential username
     * @param string $username
     * @return self
     */
    public function setUsername(string $username): self
    {
        $this->username = trim($username);
        return $this;
    }

    /**
     * @return string|null Get a user credential password
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    /**
     * Set a user credential password . Password can be empty
     * @param string|null $password
     * @return $this
     */
    public function setPassword(?string $password): self
    {
        $this->password = trim($password);
        return $this;
    }

    /**
     * Validate received password from proxy client is correct
     * @param string $receivedPassword Received password from socks5 proxy client
     * @return bool Validation password result
     */
    public function validate(string $receivedPassword): bool
    {
        return $this->password === (trim($receivedPassword));
    }
}