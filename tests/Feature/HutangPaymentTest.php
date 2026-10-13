<?php

namespace Tests\Feature;

use App\Http\Controllers\Keuangan\HutangController;
use App\Models\Hutang;
use App\Models\Pengeluaran;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HutangPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Schema::create('hutang', function (Blueprint $table) {
            $table->id();
            foreach ((new Hutang)->getFillable() as $field) {
                if (in_array($field, ['nominal', 'terbayar', 'sisa_bayar'])) $table->bigInteger($field)->default(0);
                else $table->string($field)->nullable();
            }
        });
        Schema::create('pengeluaran', function (Blueprint $table) {
            $table->id();
            foreach ((new Pengeluaran)->getFillable() as $field) $table->string($field)->nullable();
        });
        foreach (['bank', 'metode_bayar'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
            DB::table($name)->insert(['id' => 1]);
        }
        Hutang::create(['nominal' => 10000000000, 'sisa_bayar' => 10000000000, 'status' => 1]);
        Route::post('/_test/hutang/{id}/bayar', [HutangController::class, 'bayar']);
    }

    protected function tearDown(): void
    {
        foreach (DB::table('pengeluaran')->pluck('lampiran') as $file) {
            $path = public_path('assets/keuangan/pengeluaran/'.$file);
            if ($file && is_file($path)) unlink($path);
        }
        parent::tearDown();
    }

    private function payment(string $amount, string $receipt): array
    {
        return [
            'tanggal' => '2026-10-13', 'nominal' => $amount,
            'id_bank' => 1, 'id_metode_bayar' => 1,
            'no_tanda_terima' => $receipt, 'diterima_dari' => 'Pemberi', 'nama_penerima' => 'Penerima',
            'lampiran' => UploadedFile::fake()->create('bukti.pdf', 10, 'application/pdf'),
        ];
    }

    public function test_partial_payment_then_settlement_records_expenses_and_updates_debt(): void
    {
        $this->postJson('/_test/hutang/1/bayar', $this->payment('3.000.000.000', 'PAY-1'))->assertOk();
        $this->assertDatabaseHas('hutang', ['id' => 1, 'terbayar' => 3000000000, 'sisa_bayar' => 7000000000, 'status' => 1]);
        $this->assertDatabaseHas('pengeluaran', ['id_hutang' => 1, 'id_kategori_transaksi' => 3, 'nominal' => 3000000000]);
        $this->postJson('/_test/hutang/1/bayar', $this->payment('7.000.000.000', 'PAY-2'))->assertOk();
        $this->assertDatabaseHas('hutang', ['id' => 1, 'terbayar' => 10000000000, 'sisa_bayar' => 0, 'status' => 2, 'tgl_pelunasan' => '2026-10-13']);
        $this->assertDatabaseCount('pengeluaran', 2);
        $this->postJson('/_test/hutang/1/bayar', $this->payment('1', 'PAY-3'))->assertUnprocessable();
        $this->assertDatabaseCount('pengeluaran', 2);
    }

    public function test_invalid_payment_does_not_change_balance(): void
    {
        foreach (['0', '-1', '10.000.000.001', 'abc'] as $amount) {
            $this->postJson('/_test/hutang/1/bayar', $this->payment($amount, 'INVALID'))->assertUnprocessable()->assertJsonValidationErrors('nominal');
        }
        $this->assertDatabaseCount('pengeluaran', 0);
        $this->assertDatabaseHas('hutang', ['id' => 1, 'terbayar' => 0, 'sisa_bayar' => 10000000000]);
    }
}
