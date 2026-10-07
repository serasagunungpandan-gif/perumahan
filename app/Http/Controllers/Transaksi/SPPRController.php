<?php

namespace App\Http\Controllers\Transaksi;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Pengaturan\HakAksesController;
use App\Models\Customer;
use App\Models\SPPR;
use App\Traits\LogAktivitasTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\TemplateProcessor;
use Yajra\DataTables\Facades\DataTables;
use Carbon\Carbon;

class SPPRController extends Controller
{
    use LogAktivitasTrait;

    public function index(Request $request)
    {
        $permissions = HakAksesController::getUserPermissions();

        if ($request->ajax()) {
            $data = SPPR::with(['customer.kavling', 'customer.marketing'])->orderBy('id', 'desc');

            return DataTables::of($data)
                ->addIndexColumn()
                ->addColumn('customer_nama', function ($row) {
                    return $row->nama;
                })
                ->addColumn('lokasi_unit', function ($row) {
                    return $row->customer?->kavling?->kode_kavling ?? '-';
                })
                ->addColumn('nama_marketing', function ($row) {
                    return $row->customer?->marketing?->nama_marketing ?? '-';
                })
                ->addColumn('action', function ($row) use ($permissions) {
                    $cetakUrl = route('sppr.cetak', $row->id);
                    $editUrl = route('sppr.edit', $row->id);
                    $deleteUrl = route('sppr.destroy', $row->id);

                    $btn = '<div class="d-flex justify-content-center">';
                    if ($permissions['edit']) {
                        $btn .= '<button class="btn btn-info btn-sm mx-1 edit-button"
                                data-id="' . e($row->id) . '"
                                data-url="' . e($editUrl) . '">Edit</button>';
                    }
                    $btn .= '<a href="' . e($cetakUrl) . '" target="_blank" class="btn btn-dark btn-sm mx-1">Cetak</a>';
                    if ($permissions['hapus']) {
                        $btn .= '<form action="' . e($deleteUrl) . '" method="POST" style="display:inline;">
                        ' . csrf_field() . method_field('DELETE') . '
                        <button type="submit" class="delete-button btn btn-danger btn-sm mx-1">Hapus</button>
                        </form>';
                    }
                    $btn .= '</div>';
                    return $btn;
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        $customerList = Customer::orderBy('nama_lengkap')->get();

        return view('admin.transaksi.sppr.index', compact('permissions', 'customerList'));
    }

    public function getCustomerDetail($id)
    {
        $customer = Customer::with(['lokasi', 'kavling'])->findOrFail($id);

        return response()->json(['status' => 'success', 'data' => array_merge([
            'nama' => $customer->nama_lengkap,
            'alamat' => $customer->alamat_ktp ?? $customer->alamat_domisili ?? '',
            'nik' => $customer->nik,
            'no_telp' => $customer->no_telp,
            'lokasi_unit' => $customer->kavling?->kode_kavling ?? '',
            'harga_jual' => $customer->hrg_jual ?? 0,
        ], $this->kavlingData($customer))]);
    }

    private function kavlingData(Customer $customer): array
    {
        $kavling = $customer->kavling;
        if ($customer->lokasi?->is_cluster) {
            $blok = $kavling?->cluster ?? '';
            $no = $kavling?->no ?? '';
        } else {
            $parts = explode('-', $kavling?->kode_kavling ?? '', 2);
            $blok = $parts[0];
            $no = $parts[1] ?? '';
        }

        return [
            'blok' => $blok,
            'no' => $no,
            'luas_bangunan' => $kavling?->luas_bangunan ?? 0,
            'luas_tanah' => $kavling?->luas_tanah ?? 0,
        ];
    }

    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'id_customer' => 'required|integer|exists:customer,id',
            'no_sppr' => 'required|string|max:100',
            'nama' => 'required|string|max:255',
            'alamat' => 'required|string',
            'nik' => 'required|string|max:20',
            'no_telp' => 'required|string|max:20',
            'harga_jual' => 'required|integer|min:0',
            'nominal_dp' => 'required|integer|min:0',
            'asumsi_plafon_kpr' => 'required|integer|min:0',
            'penandatangan' => 'nullable|string|max:255',
        ]);
        $customer = Customer::with(['lokasi', 'kavling'])->findOrFail($data['id_customer']);
        if (!$customer->kavling) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'id_customer' => 'Customer belum memiliki data kavling. Lengkapi kavling terlebih dahulu.',
            ]);
        }

        // Ukuran dan unit selalu berasal dari menu kavling, bukan input browser.
        return array_merge($data, $this->kavlingData($customer));
    }

    public function store(Request $request)
    {
        $data = $this->validatedData($request);
        $sppr = SPPR::create($data);
        $this->logCreate('SPPR', $sppr->id);

        return response()->json(['status' => 'success']);
    }

    public function edit($id)
    {
        $sppr = SPPR::with(['customer.lokasi', 'customer.kavling'])->findOrFail($id);
        $data = $sppr->toArray();
        $data['lokasi_unit'] = $sppr->customer?->kavling?->kode_kavling ?? '';
        if ($sppr->customer) {
            $data = array_merge($data, $this->kavlingData($sppr->customer));
        }

        return response()->json(['status' => 'success', 'data' => $data]);
    }

    public function update(Request $request, $id)
    {
        $sppr = SPPR::findOrFail($id);
        $sppr->update($this->validatedData($request));
        $this->logEdit('SPPR', $sppr->id);

        return response()->json(['status' => 'success']);
    }

    public function destroy($id)
    {
        $sppr = SPPR::findOrFail($id);

        $this->logDelete('SPPR', $sppr->id);
        $sppr->delete();

        return response()->json(['status' => 'success']);
    }


    public function cetak($id)
    {
        $sppr = SPPR::with(['customer.lokasi', 'customer.kavling'])->findOrFail($id);
        abort_unless($sppr->customer?->kavling, 422, 'Data kavling customer belum tersedia.');
        $unit = $this->kavlingData($sppr->customer);
        $templatePath = public_path('templates/template_sppr/template_sppr.docx');
        abort_unless(file_exists($templatePath), 404, 'Template SPPR tidak ditemukan.');

        // Mengganti teks saja: tabel, tab stop, kop, dan format Word tetap dari template.
        \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);
        $template = new TemplateProcessor($templatePath);
        $tanggal = $sppr->created_at ? Carbon::parse($sppr->created_at) : Carbon::now();
        $bulanRomawi = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $nomor = $sppr->no_sppr ?: str_pad($sppr->id, 3, '0', STR_PAD_LEFT);
        if (!str_contains($nomor, '/')) {
            $nomor .= '/SPR-PSR/' . $bulanRomawi[$tanggal->month - 1] . '/' . $tanggal->year;
        }
        // Template menyediakan tiga baris alamat di dalam sel identitas.
        $alamat = preg_replace('/\s+/u', ' ', trim($sppr->alamat));
        $barisAlamat = explode("\n", wordwrap($alamat, 32, "\n", false), 3);
        $fmt = fn ($value) => number_format((int) $value, 0, ',', '.');
        $template->setValues([
            'no_surat' => $nomor,
            'nama' => $sppr->nama,
            'nama_ttd' => mb_strtoupper($sppr->nama),
            'alamat_1' => $barisAlamat[0] ?? '',
            'alamat_2' => $barisAlamat[1] ?? '',
            'alamat_3' => str_replace("\n", ' ', $barisAlamat[2] ?? ''),
            'nik' => $sppr->nik,
            'no_telp' => $sppr->no_telp,
            'luas_bangunan' => (string) $unit['luas_bangunan'],
            'luas_tanah' => (string) $unit['luas_tanah'],
            'blok_no' => $unit['blok'] . $unit['no'],
            'harga_jual' => $fmt($sppr->harga_jual),
            'nominal_dp' => $fmt($sppr->nominal_dp),
            'asumsi_plafon_kpr' => $fmt($sppr->asumsi_plafon_kpr),
            'penandatangan' => $sppr->penandatangan ?: 'RIKI KRIESNA,SE',
            'tanggal_surat' => $tanggal->locale('id')->isoFormat('D MMMM YYYY'),
        ]);
        $tempFile = tempnam(sys_get_temp_dir(), 'sppr_');
        $template->saveAs($tempFile);

        return response()->download($tempFile, 'SPPR_' . Str::slug($sppr->nama) . '.docx')
            ->deleteFileAfterSend(true);
    }
}
