<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\Entity;

use Doctrine\Common\Collections\ArrayCollection;

class User extends AbstractEntity
{
    protected string $name;

    // Работает ли пользователь в компании в настоящий момент
    protected bool $isEnabled = false;

    public const ROLE_UNDEFINED = 0;
    public const ROLE_DEVELOPER = 1;
    public const ROLE_TESTER = 2;
    public const ROLE_TEAM_LEAD = 3;
    public const ROLE_PROJECT_MANAGER = 4;

    protected int $roleId = self::ROLE_UNDEFINED;

    protected ArrayCollection $histories;

    public function __construct()
    {
        $this->histories = new ArrayCollection();
    }

    public function getIsEnabled(): bool
    {
        return $this->isEnabled;
    }

    public function setIsEnabled(bool $isEnabled): self
    {
        $this->isEnabled = $isEnabled;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getRoleId(): int
    {
        return $this->roleId;
    }

    public function setRoleId(int $roleId): self
    {
        $this->roleId = $roleId;

        return $this;
    }

    /**
     * @return ArrayCollection<int, History>
     */
    public function getHistories(): ArrayCollection
    {
        return $this->histories;
    }

    public function addHistory(History $object): self
    {
        if (!$this->histories->contains($object)) {
            $this->histories[] = $object;
            $object->setUser($this);
        }

        return $this;
    }

    public function removeHistory(History $object): self
    {
        $this->histories->removeElement($object);

        return $this;
    }
}
