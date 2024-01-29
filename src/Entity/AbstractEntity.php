<?php

namespace YaPro\IssueTracker\Entity;

use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;

use function explode;
use function is_bool;

abstract class AbstractEntity implements EntityInterface
{
    private string $id = '';

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): self
    {
        $this->id = $id;
        return $this;
    }
    public function getTableName(): string
    {
        $className = explode('\\', static::class);
        return 'it_' . $this->getSnakeCase(end($className));
    }

    public function getData(): array
    {
        $result = [];
        foreach ($this as $key => $value) {
            $key = $this->getSnakeCase($key);
            if ($value instanceof ArrayCollection) {
                continue;
            }
            if ($value instanceof DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }
            if ($value instanceof EntityInterface) {
                $key = $key . '_id';
                $value = $value->getId();
            }
            if (is_bool($value)) {
                $value = $value === true ? 1 : 0;
            }
            $result[$key] = $value;
        }

        return $result;
    }

    function getSnakeCase(string $string): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $string));
    }
}
