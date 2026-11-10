<?php

namespace App\Services\Documents;

use RuntimeException;

/**
 * A custody rule stopped the action. The message is safe to show to the user.
 */
class CustodyException extends RuntimeException {}
