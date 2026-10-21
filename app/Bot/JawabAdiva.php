<?php

namespace App\Bot;

use App\Models\CrmCustomer;
use App\Models\User;

/**
 * "Jawab lewat Adiva" (Percakapan): the supervisor's answer to a question Adiva passed on,
 * told to the customer in Adiva's words (once the WhatsApp command JAWAB).
 */
interface JawabAdiva
{
    /**
     * @throws PerintahGagal when it did not reach the customer; its message is shown
     */
    public function sampaikan(CrmCustomer $customer, string $jawaban, User $oleh): void;
}
