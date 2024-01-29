<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\Entity;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;

class Issue extends AbstractEntity
{
    // поле Key
    protected string $keyId;

    // Эпик Key - задача может быть без эпика.
    protected string $epicId = '';

    protected DateTimeInterface $createdAt;
    protected DateTimeInterface $updatedAt;

    // Имеется ли данная задача в текущем спринте
    protected bool $isCurrentSprint = false;

    protected string $statusName = 'undefined';
    protected DateTimeInterface $statusUpdatedAt;

    // Название задачи
    protected string $summary;

    //поле assign
    protected User $developer;

    // Тестировщик
    protected User $tester;

    // Первоначальная оценка разработчика - количество секунд на разработку (обновляется только при статусе OPEN)
    protected int $developerEstimated = 0;

    //Оставшееся время на разработку - количество секунд для завершения этапа разработки (обновляется при каждой синхронизации)
    protected int $developerRemaining = 0;

    //Время списанное любым разработчиком при работе с данной задачей (пересчитывается при каждой синхронизации)
    protected int $developerLogged = 0;

    // Первоначальная оценка тестировщика - количество секунд на тестирование (обновляется только при статусе OPEN)
    protected int $testerEstimated = 0;

    // Оставшееся время на тестирование - количество секунд для завершения этапа тестирования (обновляется при каждой синхронизации)
    protected int $testerRemaining = 0;

    //Время списанное любым тестировщиком при работе с данной задачей (пересчитывается при каждой синхронизации)
    protected int $testerLogged = 0;

    protected string $componentName = '';
    protected string $repositoryName = '';

    /**
     * @var ArrayCollection|History[]
     */
    protected iterable $histories;

    public function __construct()
    {
        $this->statusUpdatedAt = new DateTimeImmutable();
        $this->histories = new ArrayCollection();
    }

    public function getSummary(): string
    {
        return $this->summary;
    }

    public function setSummary(string $summary): self
    {
        $this->summary = $summary;

        return $this;
    }

    public function getComponentName(): string
    {
        return $this->componentName;
    }

    public function setComponentName(string $componentName): self
    {
        $this->componentName = $componentName;

        return $this;
    }

    public function getDeveloperEstimated(): int
    {
        return $this->developerEstimated;
    }

    public function setDeveloperEstimated(int $developerEstimated): self
    {
        $this->developerEstimated = $developerEstimated;

        return $this;
    }

    public function getDeveloperRemaining(): int
    {
        return $this->developerRemaining;
    }

    public function setDeveloperRemaining(int $developerRemaining): self
    {
        $this->developerRemaining = $developerRemaining;
        return $this;
    }

    public function getDeveloperLogged(): int
    {
        return $this->developerLogged;
    }

    public function setDeveloperLogged(int $developerLogged): self
    {
        $this->developerLogged = $developerLogged;
        return $this;
    }

    public function getTesterEstimated(): int
    {
        return $this->testerEstimated;
    }

    public function setTesterEstimated(int $testerEstimated): self
    {
        $this->testerEstimated = $testerEstimated;

        return $this;
    }

    public function getTesterRemaining(): int
    {
        return $this->testerRemaining;
    }

    public function setTesterRemaining(int $testerRemaining): self
    {
        $this->testerRemaining = $testerRemaining;
        return $this;
    }

    public function getTesterLogged(): int
    {
        return $this->testerLogged;
    }

    public function setTesterLogged(int $testerLogged): self
    {
        $this->testerLogged = $testerLogged;
        return $this;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getTester(): User
    {
        return $this->tester;
    }

    public function setTester(User $tester): self
    {
        $this->tester = $tester;

        return $this;
    }

    public function getDeveloper(): User
    {
        return $this->developer;
    }

    public function setDeveloper(User $developer): self
    {
        $this->developer = $developer;

        return $this;
    }

    public function getRepositoryName(): string
    {
        return $this->repositoryName;
    }

    public function setRepositoryName(string $repositoryName): self
    {
        $this->repositoryName = $repositoryName;

        return $this;
    }

    public function getStatusName(): string
    {
        return $this->statusName;
    }

    public function getStatusUpdatedAt(): DateTimeInterface
    {
        return $this->statusUpdatedAt;
    }

    public function setStatusUpdatedAt(DateTimeInterface $statusUpdatedAt): self
    {
        $this->statusUpdatedAt = $statusUpdatedAt;
        return $this;
    }

    public function setStatusName(string $statusName): self
    {
        $this->statusName = $statusName;

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
            $object->setIssue($this);
        }

        return $this;
    }

    public function removeHistory(History $object): self
    {
        $this->histories->removeElement($object);

        return $this;
    }

    public function isCurrentSprint(): bool
    {
        return $this->isCurrentSprint;
    }

    public function setIsCurrentSprint(bool $isCurrentSprint): self
    {
        $this->isCurrentSprint = $isCurrentSprint;
        return $this;
    }

    public function getEpicId(): string
    {
        return $this->epicId;
    }

    public function setEpicId(string $epicId): self
    {
        $this->epicId = $epicId;
        return $this;
    }
}
