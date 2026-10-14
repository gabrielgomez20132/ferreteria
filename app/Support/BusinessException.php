<?php

namespace App\Support;

use RuntimeException;

/**
 * Error de regla de negocio: se muestra al usuario como mensaje (no es un fallo del sistema).
 */
class BusinessException extends RuntimeException {}
