<?php

namespace App\Core;

class Validator
{
    private array $errors = [];
    private array $data = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Valida um campo específico com uma regra.
     *
     * @param string $field O nome do campo (ex: 'email').
     * @param string $rule A regra de validação (ex: 'required', 'email').
     * @param string $message A mensagem de erro customizada.
     * @return self
     */
    public function validate(string $field, string $rule, string $message): self
    {
        $value = $this->data[$field] ?? null;

        switch ($rule) {
            case 'required':
                if (empty($value)) {
                    $this->errors[$field] = $message;
                }
                break;

            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->errors[$field] = $message;
                }
                break;

            case 'password_strength':
                // Exige no mínimo 8 caracteres, pelo menos uma letra e um número.
                if (!preg_match('/^(?=.*[A-Za-z])(?=.*\d)[A-Za-z\d]{8,}$/', $value)) {
                    $this->errors[$field] = $message;
                }
                break;
            
            // Você pode adicionar mais regras aqui: min_length, max_length, etc.
        }
        return $this;
    }

    /**
     * Verifica se a validação falhou.
     * @return bool
     */
    public function fails(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Retorna a primeira mensagem de erro encontrada.
     * @return string|null
     */
    public function getFirstError(): ?string
    {
        return $this->errors[array_key_first($this->errors)] ?? null;
    }
}