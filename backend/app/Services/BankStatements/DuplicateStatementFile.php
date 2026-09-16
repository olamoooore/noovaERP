<?php

namespace App\Services\BankStatements;

class DuplicateStatementFile extends \RuntimeException
{
    public function __construct(public readonly string $importId)
    {
        parent::__construct('This statement file has already been imported.');
    }
}
