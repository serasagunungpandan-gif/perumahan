<?php

namespace Tests\Feature;

use App\Http\Controllers\Keuangan\PemasukanController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PemasukanCategoryEditTest extends TestCase
{
    public function test_category_changes_are_saved_and_invalid_categories_are_rejected(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('kategori_transaksi', function (Blueprint $table) {
            $table->id();
            $table->string('kategori');
            $table->string('jenis_kategori');
        });
        Schema::create('pemasukan', function (Blueprint $table) {
            $table->id();
            foreach (['tanggal', 'nominal', 'id_bank', 'id_kategori_transaksi', 'keterangan', 'lampiran', 'plafon_sp3k', 'id_customer', 'id_piutang', 'id_hutang'] as $field) {
                $table->string($field)->nullable();
            }
        });
        DB::table('kategori_transaksi')->insert([
            ['id' => 1, 'kategori' => 'Lainnya', 'jenis_kategori' => 'PEMASUKAN'],
            ['id' => 2, 'kategori' => 'Pendapatan Baru', 'jenis_kategori' => 'PEMASUKAN'],
            ['id' => 3, 'kategori' => 'Biaya', 'jenis_kategori' => 'PENGELUARAN'],
            ['id' => 4, 'kategori' => 'Pencairan KPR', 'jenis_kategori' => 'PEMASUKAN'],
        ]);
        DB::table('pemasukan')->insert(['id' => 1, 'tanggal' => '2026-10-13', 'nominal' => 1000, 'id_bank' => 1, 'id_kategori_transaksi' => 1, 'lampiran' => 'existing.pdf']);
        Route::put('/_test/pemasukan/{id}', [PemasukanController::class, 'update']);
        $payload = ['tanggal' => '2026-10-13', 'nominal' => '1.000', 'id_bank' => 1, 'id_kategori_transaksi' => 2];
        $this->putJson('/_test/pemasukan/1', $payload)->assertOk();
        $this->assertDatabaseHas('pemasukan', ['id' => 1, 'id_kategori_transaksi' => 2, 'nominal' => 1000]);
        foreach ([3, 4, 999, ''] as $category) {
            $this->putJson('/_test/pemasukan/1', array_replace($payload, ['id_kategori_transaksi' => $category]))
                ->assertUnprocessable()->assertJsonValidationErrors('id_kategori_transaksi');
        }
        $this->assertDatabaseHas('pemasukan', ['id' => 1, 'id_kategori_transaksi' => 2]);
    }
}
