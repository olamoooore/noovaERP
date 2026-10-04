<?php

namespace App\Accounting\Exceptions;

/**
 * #764 (A4): a statutory-report exporter that cannot produce a real statement
 * must fail loudly instead of returning hard-coded zeros with success. The
 * compliance pipeline maps this to HTTP 501.
 */
class StatutoryReportNotImplemented extends \RuntimeException {}
