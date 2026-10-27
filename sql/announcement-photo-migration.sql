-- Run once against an existing database to enable announcement photos.
ALTER TABLE announcements
  ADD COLUMN image_path VARCHAR(255) NULL AFTER category;
