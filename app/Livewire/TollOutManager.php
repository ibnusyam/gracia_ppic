<?php

namespace App\Livewire;

use App\Models\TollOutProduct;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Halaman kelola daftar produk "Toll Out" (makloon).
 *
 * User menambah/menghapus KODE produk. Kode disimpan di SQLite (writable). Nama produk di-lookup
 * dari DB legacy gracia_mrp (READ-ONLY) saat ditambahkan, meniru ekspresi nama di StokForecastReport
 * (nama barang + bentuk sediaan). Daftar ini menandai baris Toll Out di Monitoring Stok.
 */
class TollOutManager extends Component
{
    public string $kode = '';

    /** Pesan info (mis. kode tak ditemukan di master) yang tampil sekali setelah aksi. */
    public string $notice = '';

    public function add(): void
    {
        $this->validate([
            'kode' => 'required|string|max:50',
        ], [], ['kode' => 'kode produk']);

        $kode = strtoupper(trim($this->kode));
        $this->notice = '';

        if (TollOutProduct::where('kode', $kode)->exists()) {
            $this->addError('kode', "Kode $kode sudah terdaftar.");

            return;
        }

        // Lookup nama dari master_barang (read-only). Sama seperti ekspresi nama di StokForecastReport.
        $row = DB::connection('pgsql')->selectOne(
            "SELECT TRIM(b.nama || ' ' || COALESCE(bs.nama, '')) AS nama
             FROM master_barang b
             LEFT JOIN master_bentuk_sediaan bs ON bs.seq = b.bentuk_sediaan_seq
             WHERE upper(trim(b.kode)) = :kode
             LIMIT 1",
            ['kode' => $kode]
        );

        $nama = $row->nama ?? null;

        TollOutProduct::create(['kode' => $kode, 'nama' => $nama]);

        if ($nama === null) {
            $this->notice = "Kode $kode ditambahkan, tetapi tidak ditemukan di master produk — cek kembali penulisannya.";
        }

        $this->reset('kode');
    }

    public function delete(int $id): void
    {
        TollOutProduct::destroy($id);
        $this->notice = '';
    }

    public function render()
    {
        return view('livewire.toll-out-manager', [
            'items' => TollOutProduct::orderBy('kode')->get(),
        ]);
    }
}
