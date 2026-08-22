<?php

declare(strict_types=1);

namespace WPStaticSecure\Deployment;

use InvalidArgumentException;

/** Provider-neutral identity for the destination selected by an operator. */
final class DeploymentTarget
{
    public function __construct(private string $identity, private string $rootIdentity)
    {
        $this->identity = $this->assertIdentity($identity, 'target identity', 128);
        $this->rootIdentity = $this->assertIdentity($rootIdentity, 'target root identity', 255);
    }

    public function identity(): string
    {
        return $this->identity;
    }

    public function rootIdentity(): string
    {
        return $this->rootIdentity;
    }

    public function fingerprint(): string
    {
        return $this->identity . '@' . $this->rootIdentity;
    }

    private function assertIdentity(string $value, string $label, int $maxLength): string
    {
        if (strlen($value) === 0 || strlen($value) > $maxLength || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException(ucfirst($label) . ' must be a narrow ASCII identity.');
        }
        return $value;
    }
}
