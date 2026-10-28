<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Rodada do envio automático de confirmações (registrada quando o n8n busca as devidas).
 */
class ConfirmationRun extends Model
{
    protected $fillable = ['due_count'];
}
