<?php

/*
 * This file is part of the package t3g/forger.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace App\Entity;

use Symfony\Component\Security\Core\User\UserInterface;

class User implements UserInterface
{
    private $username;
    private $password;
    private $roles;

    /**
     * @var string Attribute 'displayName' from LDAP
     */
    private $displayName;
    /**
     * @var string|null
     */
    private $email;

    public function __construct(
        ?string $username,
        ?string $password,
        ?string $displayName,
        ?string $email,
        array $roles = []
    ) {
        if ('' === $username || null === $username) {
            throw new \InvalidArgumentException('The username cannot be empty.');
        }
        $this->username = $username;
        $this->password = $password;
        $this->displayName = $displayName;
        $this->roles = $roles;
        $this->email = $email;
    }

    public function __toString()
    {
        return $this->getDisplayName() ?? $this->getUsername();
    }

    /**
     * {@inheritdoc}
     */
    public function getRoles()
    {
        return $this->roles;
    }

    /**
     * {@inheritdoc}
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * {@inheritdoc}
     */
    public function getSalt()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    /**
     * {@inheritdoc}
     */
    public function eraseCredentials()
    {
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }
}
