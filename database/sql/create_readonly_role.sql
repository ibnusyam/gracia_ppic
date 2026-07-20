-- =====================================================================
-- Membuat role READ-ONLY khusus aplikasi Reporting MRP Gracia.
--
-- Kenapa: saat ini .env memakai superuser `postgres` tanpa password (berisiko).
-- Jalankan skrip ini SEKALI di server DB legacy (192.168.0.185), sebagai superuser,
-- lalu arahkan PGSQL_USERNAME/PGSQL_PASSWORD di .env ke role `mrp_web`.
--
-- Contoh menjalankan:
--   psql -h 192.168.0.185 -U postgres -d gracia_mrp -f create_readonly_role.sql
--
-- CATATAN: role ini HANYA bisa SELECT. Aplikasi tidak pernah menulis ke DB legacy.
-- =====================================================================

-- 1) Ganti 'ganti_ini' dengan password yang kuat sebelum menjalankan.
CREATE ROLE mrp_web LOGIN PASSWORD 'ganti_ini';

-- 2) Izin koneksi + baca skema public.
GRANT CONNECT ON DATABASE gracia_mrp TO mrp_web;
GRANT USAGE ON SCHEMA public TO mrp_web;

-- 3) Izin SELECT untuk semua tabel yang sudah ada.
GRANT SELECT ON ALL TABLES IN SCHEMA public TO mrp_web;

-- 4) Izin SELECT otomatis untuk tabel baru yang dibuat kemudian.
--    (Dijalankan sebagai pemilik objek default; jalankan sebagai superuser/owner.)
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO mrp_web;

-- (Opsional) jika beberapa view/report butuh baca sequence:
-- GRANT SELECT ON ALL SEQUENCES IN SCHEMA public TO mrp_web;

-- Selesai. Setelah ini set di .env aplikasi:
--   PGSQL_USERNAME=mrp_web
--   PGSQL_PASSWORD=<password yang tadi>
