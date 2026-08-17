<?php

declare(strict_types=1);

/*
 * Copyright (c) Precision Soft
 */

namespace PrecisionSoft\Doctrine\Audit\Test\Utility\Entity;

use Doctrine\ORM\Mapping as ORM;
use PrecisionSoft\Doctrine\Audit\Attribute\Ignore;

/** The `#[Ignore]` is on a PRIVATE property on purpose: Doctrine merges the field into the child's metadata, while `getProperties()` on the child does not return it. */
#[ORM\MappedSuperclass]
abstract class AbstractSecretHolder
{
    #[Ignore]
    #[ORM\Column(type: 'string', length: 64)]
    private string $password = '';

    #[ORM\Column(type: 'string', length: 64)]
    private string $email = '';

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }
}
