<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Http\Response;

/**
 * Profilfoto der Mitglieder: hochgeladen im Konto, mittig quadratisch zugeschnitten, auf 256 px verkleinert und neu als WebP
 * gespeichert (dabei fallen Standort und andere Metadaten weg). Ablage storage/…/members/avatars – nicht öffentlich;
 * sichtbar nur für angemeldete Mitglieder und die Redaktion (GET /mitglieder/avatar/{id}/{version}). Ohne Foto: Initialen.
 */
final class Avatar
{
    public const SIZE = 256;
    public const MAX_BYTES = 10 << 20;

    public static function dir(): string
    {
        return site()->storage('members/avatars');
    }

    /** @return string|null Fehlertext oder null */
    public static function store(array $m, array $up): ?string
    {
        if (($up['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($up['tmp_name'] ?? ''))) {
            return lt('Das Foto wurde nicht vollständig hochgeladen.');
        }
        if ((int) ($up['size'] ?? 0) > self::MAX_BYTES) return lt('Das Foto ist zu groß (höchstens 10 MB).');
        $info = @getimagesize((string) $up['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            return lt('Bitte ein Foto im Format JPG, PNG oder WebP wählen.');
        }
        if ($info[0] * $info[1] > 40_000_000) return lt('Das Foto hat zu viele Bildpunkte.');
        $src = @imagecreatefromstring((string) file_get_contents((string) $up['tmp_name']));
        if (!$src) return lt('Das Foto konnte nicht gelesen werden.');
        $src = self::orient($src, (string) $up['tmp_name'], $info[2]);
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);
        $dir = self::dir();
        if (!is_dir($dir) && !@mkdir($dir, 0770, true)) return lt('Das Foto konnte nicht gespeichert werden.');
        $name = (int) $m['id'] . '-' . bin2hex(random_bytes(8)) . '.webp';
        $ok = imagewebp($dst, $dir . '/' . $name, 82);
        imagedestroy($dst);
        if (!$ok) return lt('Das Foto konnte nicht gespeichert werden.');
        self::remove($m);
        Repo::updateMember((int) $m['id'], ['avatar' => $name]);
        return null;
    }

    /** Hochkant fotografiert: EXIF-Ausrichtung anwenden (nur JPEG) */
    private static function orient(\GdImage $img, string $file, int $type): \GdImage
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) return $img;
        $o = (int) (@exif_read_data($file)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        return $rot ? (imagerotate($img, $rot, 0) ?: $img) : $img;
    }

    public static function remove(array $m): void
    {
        $f = self::file($m);
        if ($f) @unlink($f);
        Repo::updateMember((int) $m['id'], ['avatar' => null]);
    }

    public static function file(array $m): ?string
    {
        $n = (string) ($m['avatar'] ?? '');
        if (!preg_match('~^\d+-[a-f0-9]{16}\.webp$~', $n)) return null;
        $f = self::dir() . '/' . $n;
        return is_file($f) ? $f : null;
    }

    public static function url(array $m): ?string
    {
        $n = (string) ($m['avatar'] ?? '');
        return $n !== '' ? Members::url('avatar/' . (int) $m['id'] . '/' . substr(md5($n), 0, 8)) : null;
    }

    public static function initials(array $m): string
    {
        $name = trim((string) ($m['name'] ?? '')) ?: (string) strtok((string) $m['email'], '@');
        $parts = preg_split('~[\s.\-_]+~u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        $i = mb_strtoupper(mb_substr($parts[0], 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));
        return $i !== '' ? $i : '?';
    }

    /** Foto oder Initialen (dekorativ – der Name steht daneben) */
    public static function html(array $m, string $class = 'mb-avatar', int $size = 64): string
    {
        $url = self::url($m);
        if ($url) return '<img class="' . e($class) . '" src="' . e($url) . '" alt="" width="' . $size . '" height="' . $size . '" loading="lazy" decoding="async">';
        return '<span class="' . e($class) . ' ' . e($class) . '--initials" aria-hidden="true">' . e(self::initials($m)) . '</span>';
    }

    public static function send(array $m): Response
    {
        $f = self::file($m) ?? throw new \Core\Http\HttpException(404);
        return (new Response((string) file_get_contents($f), 200, ['Content-Type' => 'image/webp']))
            ->header('Cache-Control', 'private, max-age=86400')
            ->header('X-Content-Type-Options', 'nosniff')
            ->header('X-Robots-Tag', 'noindex');
    }
}
