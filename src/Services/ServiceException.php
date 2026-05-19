<?php

namespace App\Services;

class ServiceException extends \RuntimeException
{
    private int $status;
    private string $codeName;
    private array $details;

    public function __construct(string $codeName, string $message, int $status, array $details = [])
    {
        parent::__construct($message);
        $this->status = $status;
        $this->codeName = $codeName;
        $this->details = $details;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function codeName(): string
    {
        return $this->codeName;
    }

    public function details(): array
    {
        return $this->details;
    }
}
