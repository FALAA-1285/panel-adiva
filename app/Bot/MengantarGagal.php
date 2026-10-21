<?php

namespace App\Bot;

use RuntimeException;

/**
 * Mengantar did not do what was asked (refused, unreachable, nothing found). The message
 * is written for the admin and never contains the API key.
 */
class MengantarGagal extends RuntimeException {}
