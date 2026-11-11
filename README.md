# SIPUS-Del Minggu 9: Web Services & REST API

| | |
|---|---|
| **Nama** | Grasia Gayatri Simanullang |
| **NIM** | 12S24027 |
| **Kelas / Prodi** | S1 Sistem Informasi |
| **Mata Kuliah** | Pemrograman dan Pengujian Aplikasi Web (12S3101) |
| **Repositori** | `ppw-2026-week9-12S24027` |
| **Branch** | `week9-rest-api` |

Melanjutkan SIPUS-Del Minggu 6 (antarmuka Blade tetap berfungsi) dengan layanan SOAP, REST API v1, dan konsumsi API eksternal.

## 1. Analisis Kebutuhan dan Kelayakan Web Services

| Kebutuhan | Peran | Teknologi | Alasan |
|---|---|---|---|
| Cek bebas pustaka untuk sistem Yudisium lama | Provider | SOAP (XML Envelope, WSDL, `soap:Fault`) | Sistem legacy hanya memahami SOAP |
| Katalog dan sirkulasi untuk Mobile / Kiosk | Provider | REST API v1 (JSON, Sanctum Bearer Token) | Ringkas, stateless, semantik HTTP, mudah dikonsumsi |
| Metadata buku berdasarkan ISBN | Consumer | Laravel `Http` ke OpenLibrary | Bukan domain inti SIPUS-Del |

**SOAP vs REST.** SOAP mewajibkan XML beramplop dan WSDL sehingga payload besar dan parsing lebih berat; dipertahankan hanya untuk integrasi legacy. REST dipilih untuk Mobile/Kiosk karena stateless (token per request), memakai verb dan status code semantik (200, 201, 204, 401, 404, 409, 422), mendukung HATEOAS, dan galat RFC 7807.

## 2. Katalog Endpoint

| Method | URI | Auth | Deskripsi | Status |
|---|---|---|---|---|
| GET | `/soap/sipus.wsdl` | - | Kontrak WSDL | 200 |
| POST | `/soap/sipus` | - | CheckClearanceStatus (SOAP) | 200, 500 (Fault) |
| POST | `/api/v1/auth/login` | - | Terbitkan Bearer Token | 200, 401, 422 |
| GET | `/api/v1/auth/me` | Bearer | Profil pemilik token | 200, 401 |
| POST | `/api/v1/auth/logout` | Bearer | Cabut token | 200, 401 |
| GET | `/api/v1/bukus` | - | Katalog (search, kategori_id, tersedia, sort, per_page) | 200 |
| GET | `/api/v1/bukus/{id}` | - | Detail buku | 200, 404 |
| POST | `/api/v1/bukus` | Bearer | Tambah buku (header `Location`) | 201, 401, 422 |
| PUT | `/api/v1/bukus/{id}` | Bearer | Ganti penuh | 200, 401, 404, 422 |
| PATCH | `/api/v1/bukus/{id}` | Bearer | Ubah sebagian | 200, 400, 401, 404 |
| DELETE | `/api/v1/bukus/{id}` | Bearer | Hapus buku | 204, 401, 404, 409 |
| GET | `/api/v1/bukus/{id}/referensi-eksternal` | - | Metadata OpenLibrary | 200, 404, 502, 504 |
| GET | `/api/v1/peminjamans` | Bearer | Daftar (filter status, nim) | 200, 400, 401 |
| POST | `/api/v1/peminjamans` | Bearer | Catat peminjaman (ACID) | 201, 401, 409, 422 |
| PATCH | `/api/v1/peminjamans/{id}/kembalikan` | Bearer | Kembalikan buku (ACID) | 200, 401, 404, 409 |

Semua galat API memakai format RFC 7807 (`type`, `title`, `status`, `detail`, `instance`). Respons buku dan peminjaman memuat `_links` (HATEOAS) yang berubah sesuai keadaan data.

## 3. Instalasi dan Pengujian Cepat

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve
```

- Akun uji: `pustakawan@del.ac.id` / `password123`
- Kontrak: `docs/openapi-sipus-v1.yaml` (dapat dibuka di editor.swagger.io)
- Koleksi Postman: `docs/SIPUS-Del-APIv1.postman_collection.json`
  1. Import di Postman. Variabel `base_url` sudah berisi `http://localhost:8000/api/v1`.
  2. Jalankan request **03 Login**, salin `access_token`, isikan ke variabel `token` pada collection.
  3. Jalankan request secara berurutan. Request peminjaman (12) harus dijalankan sebelum pengembalian (13).
- Tambahkan header `Accept: application/json` pada semua request API.

## 4. Galeri Bukti Pengujian

Semua gambar ada di `docs/screenshots/`.

### (a) SOAP dan `soap:Fault`

![WSDL](docs/screenshots/soap-wsdl.png)
*GET `/soap/sipus.wsdl`: kontrak layanan.*

![SOAP sukses](docs/screenshots/soap-sukses.png)
*POST `/soap/sipus` dengan NIM valid: 200, berisi `active_loans` dan `is_cleared`.*

![SOAP Fault](docs/screenshots/soap-fault.png)
*`nim_peminjam` kosong: `soap:Fault` dengan `faultcode soap:Client`.*

### (b) Login Bearer Token

![login](docs/screenshots/login-sukses.png)
*POST `/api/v1/auth/login`: 200, token diterbitkan.*

![me](docs/screenshots/me-dengan-token.png)
*GET `/api/v1/auth/me` dengan Bearer Token: 200.*

![tanpa token](docs/screenshots/me-tanpa-token.png)
*GET `/api/v1/auth/me` tanpa token: 401 (RFC 7807).*

![logout](docs/screenshots/logout.png)
*POST `/api/v1/auth/logout`: 200, token dicabut.*

![setelah logout](docs/screenshots/me-setelah-logout.png)
*Token yang sama setelah logout: 401.*

### (c) Katalog terpaginasi + HATEOAS

![katalog](docs/screenshots/katalog-hateoas.png)
*GET `/api/v1/bukus`: setiap item memuat `_links`.*

![meta](docs/screenshots/katalog-meta.png)
*Blok `meta` paginasi.*

### (d) POST buku 201 + Location

![201](docs/screenshots/post-buku-201.png)
*POST `/api/v1/bukus`: 201 Created.*

![location](docs/screenshots/post-buku-location.png)
*Header `Location` menunjuk resource baru.*

### (e) PUT vs PATCH

![put](docs/screenshots/put-tanpa-sinopsis.png)
*PUT tanpa `sinopsis`: atribut yang tidak dikirim direset.*

![patch](docs/screenshots/patch-stok.png)
*PATCH hanya `stok`: atribut lain tidak berubah.*

### (f) Peminjaman: sukses, stok habis, dan konflik

![sukses](docs/screenshots/peminjaman-sukses.png)
*POST `/api/v1/peminjamans`: 201, status `dipinjam`.*

![ganda](docs/screenshots/peminjam-ganda.png)
*Pinjam ganda: 409 Conflict.*

![stok habis](docs/screenshots/stok-habis.png)
*Stok habis: 422 Unprocessable Content.*

![hapus buku dipinjam](docs/screenshots/hapus-buku-yang-sedang-dipinjam.png)
*DELETE buku yang sedang dipinjam: 409 Conflict.*

![pengembalian](docs/screenshots/pengembalian.png)
*PATCH `/kembalikan`: 200, status `dikembalikan`.*

![kembalikan dua kali](docs/screenshots/kembalikan-dua-kali.png)
*Pengembalian kedua: 409 Conflict.*

![stok pulih](docs/screenshots/stok-pulih.png)
*Stok kembali otomatis dan tautan `pinjam_buku` muncul lagi.*

### (g) Latihan GitHub REST API (token disensor)

![github](docs/screenshots/github-patch-user.png)
*PATCH `https://api.github.com/user` dengan Bearer Token: 200. Token disensor dan sudah dicabut.*

### Bukti tambahan: galat RFC 7807

![password salah](docs/screenshots/login-password-salah.png)
*Login password salah: 401.*

![body kosong](docs/screenshots/body-kosong.png)
*Login body kosong: 422 dengan daftar `errors`.*

![url ngawur](docs/screenshots/URL-ngawur.png)
*URL tidak ada: 404 berformat JSON.*

### Consumer OpenLibrary

![referensi](docs/screenshots/referensi-eksternal.png)
*GET `/api/v1/bukus/{id}/referensi-eksternal`.*

### Kontrak OpenAPI

![swagger](docs/screenshots/openapi-swagger.png)
*`docs/openapi-sipus-v1.yaml` di Swagger Editor.*

## 5. Catatan

- Demo jalur 200 OpenLibrary memakai ISBN `9780596517748` (diset via PATCH pada buku 1). ISBN seeder tidak dikenal OpenLibrary sehingga menghasilkan 404; timeout 8 detik menghasilkan 504 berformat RFC 7807.
- Folder `postman/` berisi koleksi dalam mode filesystem Postman; file yang dinilai adalah `docs/SIPUS-Del-APIv1.postman_collection.json`.
- `.env` dan token tidak ikut repositori.
