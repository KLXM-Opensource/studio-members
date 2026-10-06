<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Data\Entries;
use Core\Data\Tables;

/**
 * Profile der Mitglieder = Einträge einer Datentabelle (Standard „Mitglieder“, Kurzname mitglieder) – eine Datenbasis: Name,
 * E-Mail und beliebige weitere Felder pflegt die Redaktion unter Daten (Import/Export, Listen auf geschützten Seiten).
 * Die Tabelle ist automatisch geschützt. Zugangsdaten stehen NICHT darin, sondern in members_access (Schlüssel = Eintrags-ID).
 * Lesen per SQL direkt (Entries::query blendet geschützte Tabellen für Besucher aus – die Anmeldung muss sie trotzdem finden).
 */
final class Profiles
{
    public const DEFAULT = 'mitglieder';

    private static ?array $table = null;

    public static function handle(): string
    {
        $h = (string) app()->settings->get('members.table', self::DEFAULT);
        return preg_match('~^[a-z][a-z0-9_]{1,40}$~', $h) ? $h : self::DEFAULT;
    }

    /** Tabelle der Profile – legt sie beim ersten Bedarf an (Name, E-Mail) */
    public static function table(): array
    {
        if (self::$table !== null) return self::$table;
        $t = Tables::findContent(self::handle());
        if (!$t) {
            [$def, $errors] = Tables::validate([
                'name' => 'Mitglieder', 'handle' => self::handle(), 'singular' => 'Mitglied', 'icon' => 'users',
                'description' => 'Profile des Mitgliederbereichs. Zugang (Einladung, Passwort, Passkeys, Gruppen) unter Mitglieder.',
                'fields' => [
                    ['name' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'in_list' => true, 'searchable' => true, 'width' => 'half'],
                    ['name' => 'email', 'label' => 'E-Mail', 'type' => 'email', 'required' => true, 'in_list' => true, 'searchable' => true, 'width' => 'half'],
                    ['name' => 'vita', 'label' => 'Kurze Vita', 'type' => 'textarea', 'help' => 'Ein paar Sätze über Sie – z. B. Funktion, Schwerpunkte, Kontaktwunsch.'],
                ],
                'settings' => ['kind' => 'content', 'route' => '', 'title_field' => 'name'],
            ]);
            if ($errors) throw new \RuntimeException('Tabelle „Mitglieder“: ' . implode(' ', $errors));
            Tables::create($def);
            Tables::flush();
            $t = Tables::findContent(self::handle()) ?? throw new \RuntimeException('Tabelle „Mitglieder“ fehlt.');
            Repo::setTableRule((string) $t['handle'], []);   // geschützt: nur Mitglieder (z. B. Mitgliederverzeichnis auf geschützter Seite)
        }
        return self::$table = $t;
    }

    public static function reset(): void
    {
        self::$table = null;
        self::$access = null;
    }

    private static function sql(string $where, array $args): array
    {
        $t = self::table();
        return Tables::db($t)->fetchAll("SELECT * FROM {$t['table']} WHERE $where", $args);
    }

    public static function find(int $id): ?array
    {
        return self::sql('id = ?', [$id])[0] ?? null;
    }

    public static function byEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') return null;
        return self::sql('LOWER(email) = ? ORDER BY id', [$email])[0] ?? null;
    }

    /** @return array<int, array> id → Profil */
    public static function all(): array
    {
        $out = [];
        foreach (self::sql('1 = 1 ORDER BY LOWER(name), id', []) as $p) $out[(int) $p['id']] = $p;
        return $out;
    }

    /** @return int|array ID oder Fehler (Feldname → Text) */
    public static function create(string $email, string $name): int|array
    {
        [$id, $errors] = Entries::save(self::table(), null, ['name' => trim($name) ?: mb_strtolower(trim($email)), 'email' => mb_strtolower(trim($email)), 'status' => 'published'],
            __('Mitgliederbereich'));
        return $errors ?: (int) $id;
    }

    /** Name/E-Mail ändern (über Entries::save – Versionen, Ereignisse wie in der Datenverwaltung) */
    public static function update(int $id, array $data): array
    {
        $in = array_intersect_key($data, ['name' => 1, 'email' => 1]);
        if (isset($in['email'])) $in['email'] = mb_strtolower(trim((string) $in['email']));
        if (!$in) return [];
        [, $errors] = Entries::save(self::table(), $id, $in, __('Mitgliederbereich'));
        return $errors;
    }

    public static function delete(int $id): void
    {
        Entries::delete(self::table(), $id);
    }

    /** Feldtypen, die Mitglieder selbst bearbeiten können */
    public const EDITABLE_TYPES = ['text', 'textarea', 'url', 'tel', 'email', 'select', 'date', 'link'];
    /** Stufen der Sichtbarkeit je Feld (vom Mitglied gewählt) */
    public const LEVELS = ['public', 'members', 'private'];

    /** Felder, die Mitglieder im Konto pflegen (Einstellung members.editable, Standard: alle geeigneten außer E-Mail) */
    public static function editableFields(): array
    {
        $want = array_filter(explode(',', (string) app()->settings->get('members.editable', '')));
        $out = [];
        foreach (self::table()['fields'] as $f) {
            if (!in_array($f['type'], self::EDITABLE_TYPES, true) || $f['name'] === 'email') continue;
            if ($want ? in_array($f['name'], $want, true) : true) $out[] = $f;
        }
        return $out;
    }

    /** Vorgabe der Sichtbarkeit: Name und Vita für Mitglieder, E-Mail nur Redaktion, sonst Mitglieder */
    public static function defaultLevel(string $field): string
    {
        return $field === 'email' ? 'private' : 'members';
    }

    /** @return array<string, string> Feld → public|members|private */
    public static function visibility(array $m): array
    {
        $v = json_decode((string) ($m['visibility'] ?? ''), true);
        $out = [];
        foreach (self::table()['fields'] as $f) {
            $l = is_array($v) ? (string) ($v[$f['name']] ?? '') : '';
            $out[$f['name']] = in_array($l, self::LEVELS, true) ? $l : self::defaultLevel($f['name']);
        }
        return $out;
    }

    /**
     * Core\PageAccess 'entry': Profil für das Publikum kürzen. Nur aktive Zugänge erscheinen; öffentlich nur, wenn das Mitglied
     * seinen Namen veröffentlicht hat – dann nur die öffentlichen Felder; für Mitglieder alles außer „Nur Redaktion“.
     */
    private static ?array $access = null;

    public static function filter(array $entry, string $audience): ?array
    {
        if (self::$access === null) {
            self::$access = [];
            foreach (app()->db->fetchAll('SELECT entry_id, status, visibility FROM members_access') as $a) self::$access[(int) $a['entry_id']] = $a;
        }
        $a = self::$access[(int) ($entry['id'] ?? 0)] ?? null;
        if (!$a || $a['status'] !== 'active') return null;
        $vis = self::visibility($a);
        $ok = $audience === 'public' ? ['public'] : ['public', 'members'];
        if ($audience === 'public' && !in_array($vis['name'] ?? 'members', $ok, true)) return null;
        foreach ($vis as $field => $level) if (!in_array($level, $ok, true) && array_key_exists($field, $entry)) $entry[$field] = null;
        return $entry;
    }

    public static function isTable(array $t): bool
    {
        return ($t['handle'] ?? '') === self::handle();
    }
}
