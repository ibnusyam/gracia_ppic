<?php

use App\Http\Controllers\MonitoringStokExportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StokForecastExportController;
use App\Http\Controllers\WipBelumProsesExportController;
use App\Livewire\LoginLogTable;
use App\Livewire\StokForecastTable;
use App\Livewire\TollOutManager;
use App\Livewire\UserManager;
use App\Livewire\WipBelumProsesTable;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('reports.stok-forecast');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Laporan
    Route::get('/reports/wip-belum-proses', WipBelumProsesTable::class)->name('reports.wip-belum-proses');
    Route::get('/reports/wip-belum-proses/excel', [WipBelumProsesExportController::class, 'excel'])->name('reports.wip-belum-proses.excel');

    Route::get('/reports/monitoring-stok/excel', [MonitoringStokExportController::class, 'excel'])->name('reports.monitoring-stok.excel');
    Route::get('/reports/monitoring-stok/pdf', [MonitoringStokExportController::class, 'pdf'])->name('reports.monitoring-stok.pdf');

    Route::get('/reports/stok-forecast', StokForecastTable::class)->name('reports.stok-forecast');
    Route::get('/reports/stok-forecast/excel', [StokForecastExportController::class, 'excel'])->name('reports.stok-forecast.excel');
    Route::get('/reports/stok-forecast/pdf', [StokForecastExportController::class, 'pdf'])->name('reports.stok-forecast.pdf');

    // Kelola daftar produk Toll Out (makloon) — dipakai utk highlight di Monitoring Stok.
    Route::get('/reports/toll-out', TollOutManager::class)->name('reports.toll-out');

    // Khusus admin: log aktivitas login & kelola user.
    Route::middleware('can:admin')->group(function () {
        Route::get('/log-login', LoginLogTable::class)->name('log-login');
        Route::get('/users', UserManager::class)->name('users');
    });
});

require __DIR__.'/auth.php';
