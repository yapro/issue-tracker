<?php

namespace YaPro\IssueTracker\Entity;

interface EntityInterface
{
    public function getId(): string;
    public function getTableName(): string;
    public function getData(): array;
}
