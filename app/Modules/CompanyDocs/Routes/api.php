<?php

use App\Modules\CompanyDocs\Controllers\Api\CompanyDocumentController;
use Illuminate\Support\Facades\Route;

// CompanyDocs (API): documentos de la empresa en la app.
// Convenciones de middleware en Core/Routes/api.php.
Route::middleware(['api.tenant', 'permission:company_docs.view_own'])->prefix('company-documents')->group(function () {
    Route::get('/',                 [CompanyDocumentController::class, 'index']);
    Route::get('{id}',              [CompanyDocumentController::class, 'show'])->whereNumber('id');
    Route::get('{id}/file',         [CompanyDocumentController::class, 'file'])->whereNumber('id')->middleware('throttle:60,1');
    Route::post('{id}/acknowledge', [CompanyDocumentController::class, 'acknowledge'])->whereNumber('id')->middleware('throttle:30,1');
});
