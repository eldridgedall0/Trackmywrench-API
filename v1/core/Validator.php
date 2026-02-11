<?php
/**
 * GarageMinder Mobile API - Input Validator
 */

namespace GarageMinder\API\Core;

class Validator
{
    private array $errors = [];

    /**
     * Validate and return clean value, or add error
     */
    public function required(string $field, $value, string $label = null): self
    {
        $label = $label ?? $field;
        if ($value === null || $value === '') {
            $this->errors[$field] = "{$label} is required";
        }
        return $this;
    }

    public function string(string $field, $value, int $minLen = 0, int $maxLen = 255): self
    {
        if ($value === null || $value === '') return $this;
        if (!is_string($value)) {
            $this->errors[$field] = "{$field} must be a string";
        } elseif (strlen($value) < $minLen) {
            $this->errors[$field] = "{$field} must be at least {$minLen} characters";
        } elseif (strlen($value) > $maxLen) {
            $this->errors[$field] = "{$field} must be at most {$maxLen} characters";
        }
        return $this;
    }

    public function integer(string $field, $value, ?int $min = null, ?int $max = null): self
    {
        if ($value === null || $value === '') return $this;
        if (!is_numeric($value) || (int)$value != $value) {
            $this->errors[$field] = "{$field} must be an integer";
        } else {
            $intVal = (int) $value;
            if ($min !== null && $intVal < $min) {
                $this->errors[$field] = "{$field} must be at least {$min}";
            }
            if ($max !== null && $intVal > $max) {
                $this->errors[$field] = "{$field} must be at most {$max}";
            }
        }
        return $this;
    }

    public function email(string $field, $value): self
    {
        if ($value === null || $value === '') return $this;
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = "{$field} must be a valid email address";
        }
        return $this;
    }

    public function inArray(string $field, $value, array $allowed): self
    {
        if ($value === null || $value === '') return $this;
        if (!in_array($value, $allowed, true)) {
            $this->errors[$field] = "{$field} must be one of: " . implode(', ', $allowed);
        }
        return $this;
    }

    public function boolean(string $field, $value): self
    {
        if ($value === null || $value === '') return $this;
        if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            $this->errors[$field] = "{$field} must be a boolean";
        }
        return $this;
    }

    /**
     * Check if validation passed
     */
    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Throw response if validation failed
     */
    public function throwIfFailed(): void
    {
        if ($this->fails()) {
            Response::error(
                'Validation failed',
                422,
                'VALIDATION_ERROR',
                $this->errors
            );
        }
    }

    /**
     * Sanitize a string for safe database storage
     */
    public static function sanitize(?string $value): ?string
    {
        if ($value === null) return null;
        return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize an integer
     */
    public static function sanitizeInt($value): ?int
    {
        if ($value === null || $value === '') return null;
        return (int) $value;
    }
}
