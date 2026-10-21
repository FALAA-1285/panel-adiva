<?php

use App\Http\Controllers\WahaMasukController;
use Illuminate\Support\Facades\Route;

// WAHA reports every WhatsApp message here when BOT_DRIVER=lokal (docs/kontrak-n8n.md §8b).
// It has no session or CSRF: the HMAC WAHA signs each call with is the check.
Route::post('/webhook/waha', WahaMasukController::class)->name('webhook.waha');
