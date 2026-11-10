<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PeminjamanResource;
use App\Models\Buku;
use App\Models\Peminjaman;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PeminjamanApiController extends Controller
{
    use ApiResponse;

    /** GET /api/v1/peminjamans?status=&nim= */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->query('per_page', 10), 1), 50);

        $status = $request->query('status');
        if ($status !== null && !in_array($status, ['dipinjam', 'dikembalikan'], true)) {
            return $this->problemResponse(
                title: 'Bad Request',
                detail: "Nilai status harus 'dipinjam' atau 'dikembalikan'.",
                status: 400,
                errorSlug: 'invalid-status-filter'
            );
        }

        $paginator = Peminjaman::query()
            ->with('buku')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->query('nim'), fn ($q, $nim) => $q->where('nim_peminjam', $nim))
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        return $this->successResponse(
            data: PeminjamanResource::collection($paginator->items()),
            message: 'Daftar transaksi peminjaman berhasil diambil.',
            meta: [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total_items'  => $paginator->total(),
                'total_pages'  => $paginator->lastPage(),
                'links' => [
                    'first' => $paginator->url(1),
                    'prev'  => $paginator->previousPageUrl(),
                    'next'  => $paginator->nextPageUrl(),
                    'last'  => $paginator->url($paginator->lastPage()),
                ],
            ]
        );
    }

    /** POST /api/v1/peminjamans */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'buku_id'       => 'required|integer|exists:bukus,id',
            'nim_peminjam'  => 'required|string|max:15',
            'nama_peminjam' => 'required|string|max:150',
            'durasi_hari'   => 'required|integer|min:1|max:30',
        ]);

        return DB::transaction(function () use ($validated) {
            // Kunci baris buku (SELECT ... FOR UPDATE) agar tidak ada race condition stok
            $buku = Buku::where('id', $validated['buku_id'])->lockForUpdate()->first();

            if (!$buku) {
                return $this->problemResponse(
                    'Resource Not Found', 'Buku tidak ditemukan.', 404, 'book-not-found'
                );
            }

            $nim = strtoupper(trim($validated['nim_peminjam']));

            // Mahasiswa masih meminjam buku yang sama dan belum dikembalikan -> 409
            $masihPinjam = Peminjaman::where('buku_id', $buku->id)
                ->where('nim_peminjam', $nim)
                ->where('status', 'dipinjam')
                ->exists();

            if ($masihPinjam) {
                return $this->problemResponse(
                    title: 'Duplicate Active Loan Conflict',
                    detail: "NIM '{$nim}' masih meminjam buku '{$buku->judul}' dan belum mengembalikannya.",
                    status: 409,
                    errorSlug: 'duplicate-active-loan'
                );
            }

            // Stok habis -> 422
            if ($buku->stok < 1) {
                return $this->problemResponse(
                    title: 'Book Out Of Stock',
                    detail: 'Stok buku sedang habis.',
                    status: 422,
                    errorSlug: 'stock-exhausted'
                );
            }

            $buku->decrement('stok');

            $tanggalPinjam = Carbon::today();

            $peminjaman = Peminjaman::create([
                'kode_pinjam'         => $this->generateKode($tanggalPinjam),
                'buku_id'             => $buku->id,
                'nim_peminjam'        => $nim,
                'nama_peminjam'       => $validated['nama_peminjam'],
                'tanggal_pinjam'      => $tanggalPinjam->toDateString(),
                'tanggal_jatuh_tempo' => $tanggalPinjam->copy()->addDays($validated['durasi_hari'])->toDateString(),
                'status'              => 'dipinjam',
            ]);

            $peminjaman->load('buku');

            return $this->createdResponse(
                data: new PeminjamanResource($peminjaman),
                locationUrl: url("/api/v1/peminjamans/{$peminjaman->id}"),
                message: "Peminjaman berhasil dicatat. Sisa stok buku: {$buku->stok}."
            );
        });
    }

    /** PATCH /api/v1/peminjamans/{id}/kembalikan */
    public function kembalikan(string $id): JsonResponse
    {
        return DB::transaction(function () use ($id) {
            $peminjaman = Peminjaman::where('id', $id)->lockForUpdate()->first();

            if (!$peminjaman) {
                return $this->problemResponse(
                    'Resource Not Found', "Peminjaman dengan ID '{$id}' tidak ditemukan.", 404, 'loan-not-found'
                );
            }

            if ($peminjaman->status === 'dikembalikan') {
                return $this->problemResponse(
                    title: 'Loan Already Returned',
                    detail: 'Buku pada peminjaman ini sudah dikembalikan sebelumnya.',
                    status: 409,
                    errorSlug: 'loan-already-returned'
                );
            }

            $buku = Buku::where('id', $peminjaman->buku_id)->lockForUpdate()->first();

            $peminjaman->update([
                'status'          => 'dikembalikan',
                'tanggal_kembali' => Carbon::today()->toDateString(),
            ]);

            if ($buku) {
                $buku->increment('stok');
            }

            $peminjaman->load('buku');

            return $this->successResponse(
                data: new PeminjamanResource($peminjaman),
                message: 'Buku berhasil dikembalikan.'
            );
        });
    }

    private function generateKode(Carbon $tanggal): string
    {
        do {
            $kode = 'PJM-' . $tanggal->format('Ymd') . '-' . strtoupper(Str::random(4));
        } while (Peminjaman::where('kode_pinjam', $kode)->exists());

        return $kode;
    }
}