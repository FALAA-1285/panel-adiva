<?php

namespace App\Models;

use App\Models\Concerns\ZonaWaktuAplikasi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A reply text an admin saved on Percakapan ("Template").
 *
 * @property int $id
 * @property string $teks {nama} becomes the customer's first name when used
 * @property int|null $user_id
 */
#[Table('template_balasan')]
#[Fillable(['teks', 'user_id'])]
class TemplateBalasan extends Model
{
    use ZonaWaktuAplikasi;
}
