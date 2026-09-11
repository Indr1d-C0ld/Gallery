-- Schema Gallery (allineato al DB in produzione – 2026-09-10)
CREATE TABLE IF NOT EXISTS images (
  id         INTEGER PRIMARY KEY,
  short      TEXT UNIQUE NOT NULL,
  filename   TEXT NOT NULL,
  mime       TEXT NOT NULL,
  size       INTEGER NOT NULL,
  width      INTEGER,
  height     INTEGER,
  title      TEXT,
  alt        TEXT,
  delkey     TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  folder     TEXT DEFAULT ''
);

CREATE INDEX IF NOT EXISTS idx_images_created        ON images(created_at DESC);
CREATE INDEX IF NOT EXISTS idx_images_folder_created ON images(folder, created_at DESC);

-- La ricerca full-text (tabella virtuale images_fts + trigger) è in fts5_setup.sql
-- ed è opzionale: senza di essa index.php/admin ricadono su LIKE.
