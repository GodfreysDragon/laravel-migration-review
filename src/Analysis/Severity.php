<?php

namespace GodfreysDragon\MigrationReview\Analysis;

enum Severity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function escalate(): self
    {
        return match ($this) {
            self::Low => self::Medium,
            self::Medium, self::High => self::High,
        };
    }

    public static function fromLoose(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtolower(trim($value)));
    }
}
