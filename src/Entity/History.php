<?php

declare(strict_types=1);

namespace YaPro\IssueTracker\Entity;

use DateTimeInterface;

// В один момент времени может быть создано более 1-ой записи, например списание времени и установка remaining time
class History extends AbstractEntity
{
    protected DateTimeInterface $createdAt;

    // Пользователь, который выполнил действие
    protected User $user;

    // Задача в которой пользователь выполнил действие
    protected Issue $issue;

    // Имя действия (обычно это поле, которое изменилось)
    protected string $fieldName;

    // Значение поля (например количество секунд "затраченных", "оставшихся для решения задачи")
    protected string $fieldValue;

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function setFieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    public function getFieldValue(): string
    {
        return $this->fieldValue;
    }

    public function setFieldValue(string $fieldValue): self
    {
        $this->fieldValue = $fieldValue;

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

    public function getIssue(): Issue
    {
        return $this->issue;
    }

    public function setIssue(Issue $issue): self
    {
        $this->issue = $issue;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }
}
