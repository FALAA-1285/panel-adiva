<?php

namespace App\Bot;

use RuntimeException;

/**
 * WAHA did not take the message (unreachable, session down, refused).
 */
class PengirimanGagal extends RuntimeException {}
