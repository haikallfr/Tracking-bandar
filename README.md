# Broker Summary Otomatis

Dashboard PHP sederhana untuk menarik broker summary saham Indonesia secara otomatis dari endpoint web Stockbit yang membutuhkan Bearer token akun pengguna.

## Jalankan server lokal

1. Jalankan:

```bash
cd "/Applications/MAMP/htdocs/tracking bandar"
chmod +x start-local.sh
./start-local.sh
```

2. Buka `http://127.0.0.1:8099/`.
3. Isi `Bearer token Stockbit`.
4. Isi watchlist, lalu klik `Simpan Pengaturan`.
5. Klik `Refresh Sekarang`.

## Auto refresh

Browser akan refresh berkala sesuai angka menit yang disimpan.

Untuk refresh dari cron:

```bash
*/15 * * * * TRACKING_BANDAR_BASE_URL="http://127.0.0.1:8099" /opt/homebrew/bin/php "/Applications/MAMP/htdocs/tracking bandar/cron/fetch.php" >> /tmp/tracking-bandar.log 2>&1
```

Sesuaikan path PHP bila berbeda.

## Penyimpanan

- Pengaturan dan cache lokal tersimpan di `storage/app.sqlite`
- Token disimpan lokal apa adanya

## Catatan penting

- Endpoint broker summary Stockbit menolak akses tanpa autentikasi.
- Tidak ada API key publik resmi yang saya temukan untuk broker summary saham Indonesia.
- Karena itu aplikasi ini memakai Bearer token akun Anda sendiri, bukan API key publik gratis.
- Jika token expired, buka dashboard lalu tempel token baru.

## Ambil token Stockbit

Setelah Anda login di `https://stockbit.com`, buka DevTools browser pada halaman Stockbit lalu jalankan:

```js
const decode = (value) => JSON.parse(atob(value));
console.log({
  accessToken: decode(localStorage.getItem('at')),
  accessTokenExpiry: Number(decode(localStorage.getItem('ate'))),
  refreshToken: decode(localStorage.getItem('ar')),
  refreshTokenExpiry: Number(decode(localStorage.getItem('are'))),
  accessUser: decode(localStorage.getItem('au')),
});
```

Key yang dipakai web mereka:

- `at`: access token
- `ate`: access token expiry
- `ar`: refresh token
- `are`: refresh token expiry
- `au`: user access
