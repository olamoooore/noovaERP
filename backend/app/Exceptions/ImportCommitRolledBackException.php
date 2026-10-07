<?php

namespace App\Exceptions;

/**
 * #1037: thrown inside the customer import commit transaction to roll the
 * whole commit back when a row failed while being written. Caught by
 * CustomerImportService::commitBatch(); never leaves the service.
 */
class ImportCommitRolledBackException extends \RuntimeException {}
