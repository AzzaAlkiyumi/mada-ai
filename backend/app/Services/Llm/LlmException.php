<?php

namespace App\Services\Llm;

use RuntimeException;

/**
 * A model service failure. The message is safe to show to the user.
 */
class LlmException extends RuntimeException {}
