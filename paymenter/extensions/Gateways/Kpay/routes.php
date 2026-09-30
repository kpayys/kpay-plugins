<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\Kpay\Kpay;

// KPay 的异步通知是 GET 请求
Route::get('/extensions/gateways/kpay/notify', [Kpay::class, 'notify'])->name('extensions.gateways.kpay.notify');
