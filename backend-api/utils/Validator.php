<?php
/**
 * Validator.php — Input validation utility
 *
 * Usage:
 *   $v = new Validator($_POST);
 *   $v->required(['name','email','password'])
 *     ->email('email')
 *     ->min('password', 8)
 *     ->phone('phone');
 *
 *   if ($v->fails()) {
 *       Response::validationError($v->errors());
 *   }
 */

declare(strict_types=1);

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        // Sanitize all string inputs on entry
        $this->data = array_map(function ($v) {
            return is_string($v) ? trim(strip_tags($v)) : $v;
        }, $data);
    }

    // ── Fluent rule methods ───────────────────────────────────────────────────

    public function required(array $fields): static
    {
        foreach ($fields as $field) {
            if (empty($this->data[$field]) && $this->data[$field] !== '0' && $this->data[$field] !== 0) {
                $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        return $this;
    }

    public function email(string $field): static
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field][] = 'Please enter a valid email address.';
        }
        return $this;
    }

    public function phone(string $field): static
    {
        if (!empty($this->data[$field])) {
            $phone = preg_replace('/[^0-9]/', '', $this->data[$field]);
            if (strlen($phone) < 10 || strlen($phone) > 13) {
                $this->errors[$field][] = 'Please enter a valid phone number.';
            }
        }
        return $this;
    }

    public function min(string $field, int $length): static
    {
        if (!empty($this->data[$field]) && strlen((string) $this->data[$field]) < $length) {
            $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . " must be at least {$length} characters.";
        }
        return $this;
    }

    public function max(string $field, int $length): static
    {
        if (!empty($this->data[$field]) && strlen((string) $this->data[$field]) > $length) {
            $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$length} characters.";
        }
        return $this;
    }

    public function numeric(string $field): static
    {
        if (!empty($this->data[$field]) && !is_numeric($this->data[$field])) {
            $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' must be a number.';
        }
        return $this;
    }

    public function positive(string $field): static
    {
        if (!empty($this->data[$field]) && (float) $this->data[$field] <= 0) {
            $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' must be greater than zero.';
        }
        return $this;
    }

    public function in(string $field, array $allowed): static
    {
        if (!empty($this->data[$field]) && !in_array($this->data[$field], $allowed, true)) {
            $this->errors[$field][] = ucfirst(str_replace('_', ' ', $field)) . ' must be one of: ' . implode(', ', $allowed) . '.';
        }
        return $this;
    }

    public function url(string $field): static
    {
        if (!empty($this->data[$field]) && !filter_var($this->data[$field], FILTER_VALIDATE_URL)) {
            $this->errors[$field][] = 'Please enter a valid URL.';
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message = 'Invalid format.'): static
    {
        if (!empty($this->data[$field]) && !preg_match($pattern, (string) $this->data[$field])) {
            $this->errors[$field][] = $message;
        }
        return $this;
    }

    // ── Result ────────────────────────────────────────────────────────────────

    public function fails(): bool
    {
        return !empty($this->errors);
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Returns cleaned/sanitised input data (only the requested fields).
     */
    public function only(array $fields): array
    {
        return array_intersect_key($this->data, array_flip($fields));
    }

    /**
     * Get a single validated value (or default if not present).
     */
    public function get(string $field, mixed $default = null): mixed
    {
        return $this->data[$field] ?? $default;
    }

    /**
     * Get all validated data.
     */
    public function all(): array
    {
        return $this->data;
    }
}
