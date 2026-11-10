<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BukuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $links = [
            'self' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'GET',
            ],
            'update_full' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PUT',
            ],
            'update_partial' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'PATCH',
            ],
            'delete' => [
                'href'   => url("/api/v1/bukus/{$this->id}"),
                'method' => 'DELETE',
            ],
            'referensi_eksternal' => [
                'href'   => url("/api/v1/bukus/{$this->id}/referensi-eksternal"),
                'method' => 'GET',
            ],
        ];

        // HATEOAS dinamis: link pinjam_buku hanya muncul jika stok > 0
        if ($this->stok > 0) {
            $links['pinjam_buku'] = [
                'href'   => url('/api/v1/peminjamans'),
                'method' => 'POST',
            ];
        }

        return [
            'id'           => $this->id,
            'isbn'         => $this->isbn,
            'judul'        => $this->judul,
            'penulis'      => $this->penulis,
            'penerbit'     => $this->penerbit,
            'tahun_terbit' => (int) $this->tahun_terbit,
            'stok'         => (int) $this->stok,
            'tersedia'     => $this->stok > 0,
            'sinopsis'     => $this->sinopsis,
            'kategori'     => $this->whenLoaded('kategori', fn () => [
                'id'            => $this->kategori->id,
                'kode_kategori' => $this->kategori->kode_kategori,
                'nama_kategori' => $this->kategori->nama_kategori,
            ]),
            'total_peminjaman' => $this->whenCounted('peminjamans'),
            'riwayat_peminjaman' => PeminjamanResource::collection($this->whenLoaded('peminjamans')),
            'updated_at'   => $this->updated_at?->toIso8601String(),
            '_links'       => $links,
        ];
    }
}