-- Full-text search per immagini (SQLite FTS5)
CREATE VIRTUAL TABLE IF NOT EXISTS images_fts
USING fts5(
  short UNINDEXED,
  folder,
  title,
  alt,
  filename,
  content='images',
  content_rowid='id',
  tokenize='unicode61 remove_diacritics 2'
);

CREATE TRIGGER IF NOT EXISTS images_ai AFTER INSERT ON images BEGIN
  INSERT INTO images_fts(rowid, short, folder, title, alt, filename)
  VALUES (new.id, new.short, COALESCE(new.folder,''), COALESCE(new.title,''), COALESCE(new.alt,''), COALESCE(new.filename,''));
END;

CREATE TRIGGER IF NOT EXISTS images_ad AFTER DELETE ON images BEGIN
  INSERT INTO images_fts(images_fts, rowid, short, folder, title, alt, filename)
  VALUES ('delete', old.id, old.short, COALESCE(old.folder,''), COALESCE(old.title,''), COALESCE(old.alt,''), COALESCE(old.filename,''));
END;

CREATE TRIGGER IF NOT EXISTS images_au AFTER UPDATE ON images BEGIN
  INSERT INTO images_fts(images_fts, rowid, short, folder, title, alt, filename)
  VALUES ('delete', old.id, old.short, COALESCE(old.folder,''), COALESCE(old.title,''), COALESCE(old.alt,''), COALESCE(old.filename,''));
  INSERT INTO images_fts(rowid, short, folder, title, alt, filename)
  VALUES (new.id, new.short, COALESCE(new.folder,''), COALESCE(new.title,''), COALESCE(new.alt,''), COALESCE(new.filename,''));
END;

-- Backfill iniziale (per record già presenti)
INSERT INTO images_fts(rowid, short, folder, title, alt, filename)
SELECT id, short, COALESCE(folder,''), COALESCE(title,''), COALESCE(alt,''), COALESCE(filename,'')
FROM images
WHERE id NOT IN (SELECT rowid FROM images_fts);

