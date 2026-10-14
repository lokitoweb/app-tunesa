<?php

use App\Modules\CompanyDocs\Controllers\Web\CompanyDocumentWebController;
use Illuminate\Support\Facades\Route;

// CompanyDocs (panel web): documentos de la empresa, carpetas y lectura
Route::middleware(['auth', 'web.tenant', 'can:company_docs.manage'])->prefix('company-documents')->name('company-docs.')->group(function () {
    Route::get('/', [CompanyDocumentWebController::class, 'index'])->name('index');
    Route::get('/create', [CompanyDocumentWebController::class, 'create'])->name('create');
    Route::post('/', [CompanyDocumentWebController::class, 'store'])->name('store');
    Route::get('/{id}', [CompanyDocumentWebController::class, 'show'])->name('show')->whereNumber('id');
    Route::get('/{id}/edit', [CompanyDocumentWebController::class, 'edit'])->name('edit')->whereNumber('id');
    Route::put('/{id}', [CompanyDocumentWebController::class, 'update'])->name('update')->whereNumber('id');
    Route::post('/{id}/status', [CompanyDocumentWebController::class, 'status'])->name('status')->whereNumber('id');
    Route::delete('/{id}', [CompanyDocumentWebController::class, 'destroy'])->name('destroy')->whereNumber('id');
    Route::get('/{id}/file', [CompanyDocumentWebController::class, 'file'])->name('file')->whereNumber('id');
    Route::get('/{id}/export', [CompanyDocumentWebController::class, 'export'])->name('export')->whereNumber('id');
    Route::get('/folders', [CompanyDocumentWebController::class, 'folders'])->name('folders');
    Route::post('/folders', [CompanyDocumentWebController::class, 'storeFolder'])->name('folders.store');
    Route::put('/folders/{id}', [CompanyDocumentWebController::class, 'updateFolder'])->name('folders.update')->whereNumber('id');
    Route::delete('/folders/{id}', [CompanyDocumentWebController::class, 'destroyFolder'])->name('folders.destroy')->whereNumber('id');
});
