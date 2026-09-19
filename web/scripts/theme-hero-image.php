<?php

/**
 * @file
 * Siapkan aset foto hero theme dari gambar sumber.
 *
 * Alur: potong (buang bagian yang tidak dipakai) → kompres ke WebP → tulis ke
 * images/hero-boneka.webp. Gambar sumber (images/hero-boneka.png) tetap
 * disimpan di theme supaya aset bisa dibuat ulang tanpa file dari luar repo.
 *
 * Pakai:
 *   docker compose exec drupal php scripts/theme-hero-image.php
 *   docker compose exec drupal php scripts/theme-hero-image.php <sumber.png> <tujuan.webp> [kualitas]
 *
 * Catatan: WebP didukung semua browser modern; PNG sumber hanya dipakai untuk
 * membuat ulang, tidak direferensikan oleh CSS/HTML theme.
 */

declare(strict_types=1);

$theme = dirname(__DIR__) . '/web/themes/custom/blank_theme/images';
$src = $argv[1] ?? $theme . '/hero-boneka.png';
$dst = $argv[2] ?? $theme . '/hero-boneka.webp';
$quality = (int) ($argv[3] ?? 90);

// Potongan untuk hero: buang papan "Good Things Happen Here" di kanan atas
// (y < 290) dan dinding kosong di kiri (x < 260) supaya beruang mengisi frame
// hero. Ubah di sini bila memakai foto lain.
$crop_x = 260;
$crop_y = 290;

if (!function_exists('imagewebp')) {
  fwrite(STDERR, "GD tanpa dukungan WebP.\n");
  exit(1);
}

$image = imagecreatefrompng($src);
if (!$image) {
  fwrite(STDERR, "Gagal membaca $src\n");
  exit(1);
}
imagepalettetotruecolor($image);

$width = imagesx($image);
$height = imagesy($image);
printf("sumber: %s (%dx%d)\n", $src, $width, $height);

$crop_w = $width - $crop_x;
$crop_h = $height - $crop_y;
$cropped = imagecreatetruecolor($crop_w, $crop_h);
imagecopy($cropped, $image, 0, 0, $crop_x, $crop_y, $crop_w, $crop_h);
printf("potong: %dx%d (rasio %.2f)\n", $crop_w, $crop_h, $crop_w / $crop_h);

if (!imagewebp($cropped, $dst, $quality)) {
  fwrite(STDERR, "Gagal menulis $dst\n");
  exit(1);
}

printf("tulis: %s (%s bytes, kualitas %d)\n", $dst, number_format((float) filesize($dst)), $quality);
printf("sumber PNG: %s bytes\n", number_format((float) filesize($src)));

imagedestroy($image);
imagedestroy($cropped);
