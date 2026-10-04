<?php

namespace Modules\OCRBookkeeping\Helpers;

/**
 * @deprecated Issue #878 / SOP §4 — the implementation moved to the
 *             platform-owned {@see \App\Support\TenantUserHelper}. This
 *             alias keeps existing module imports working; new code must
 *             use the platform class.
 */
class TenantUserHelper extends \App\Support\TenantUserHelper {}
