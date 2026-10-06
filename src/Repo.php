<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Db\Table;

/**
 * Datenbank der Erweiterung (Datenbank der Website, Tabellen mit Präfix members_). Mitglieder sind NIE Zeilen in `users` –
 * jede Zeile dort darf in die Verwaltung.
 *
 *  members_access         Zugang je Profil (Profil = Eintrag der Datentabelle „Mitglieder“, Profiles): entry_id, Status
 *                         invited|pending|active|blocked, Passwort-Hash (optional), auth_ver, Foto – Name/E-Mail stehen im Profil
 *  members_groups         Gruppen (z. B. Mitglieder, Vorstand, Presse)
 *  members_member_groups  Zuordnung Mitglied ↔ Gruppe
 *  members_tokens         Einmal-Links (Einladung, Anmelde-Link) – nur SHA-256 des Tokens, mit Ablauf
 *  members_areas          geschützte Seiten: page_id + Gruppen ('' = alle Mitglieder) + scope tree (mit Unterseiten) | page (nur diese)
 *  members_tables         geschützte Datentabellen (Detailseiten + Einträge in Listen öffentlicher Seiten): handle + Gruppen
 *  Geschützte Dateien liegen in einem geschützten Pool der Mediathek (Core\MediaPools, Zugriff über Extension::mediaAccess).
 *  members_passkeys       Passkeys der Mitglieder (Core\Passkeys mit eigener Tabelle)
 */
final class Repo
{
    public const STATUSES = ['invited', 'pending', 'active', 'blocked'];

    /** @return array<string, callable(Table): mixed> */
    public static function tables(): array
    {
        return [
            'members_access' => fn(Table $t) => $t->id()
                ->column('entry_id', 'int', ['null' => false])
                ->column('password_hash', 'string', ['length' => 255])
                ->column('status', 'string', ['length' => 12, 'default' => 'invited'])
                ->column('note', 'text')
                ->column('application', 'text')
                ->column('avatar', 'string', ['length' => 64])
                ->column('lang', 'string', ['length' => 10])
                ->column('visibility', 'text')
                ->column('auth_ver', 'int', ['default' => 1])
                ->column('created_at', 'datetime')->column('invited_at', 'datetime')
                ->column('approved_at', 'datetime')->column('last_login_at', 'datetime')
                ->unique('entry_id')->index('status'),
            'members_groups' => fn(Table $t) => $t->id()
                ->column('name', 'string', ['length' => 80, 'null' => false])
                ->column('description', 'string', ['length' => 191, 'default' => ''])
                ->column('sort', 'int', ['default' => 0]),
            'members_member_groups' => fn(Table $t) => $t->id()
                ->column('member_id', 'int', ['null' => false])->column('group_id', 'int', ['null' => false])
                ->unique(['member_id', 'group_id'])->index('group_id'),
            'members_tokens' => fn(Table $t) => $t->id()
                ->column('member_id', 'int', ['null' => false])
                ->column('kind', 'string', ['length' => 12, 'null' => false])
                ->column('token_hash', 'string', ['length' => 64, 'null' => false])
                ->column('created_at', 'datetime')->column('expires_at', 'datetime')->column('used_at', 'datetime')
                ->unique('token_hash')->index(['member_id', 'kind']),
            'members_areas' => fn(Table $t) => $t->id()
                ->column('page_id', 'int', ['null' => false])
                ->column('groups', 'string', ['length' => 191, 'default' => ''])
                ->column('scope', 'string', ['length' => 8, 'default' => 'tree'])
                ->column('created_at', 'datetime')
                ->unique('page_id'),
            'members_tables' => fn(Table $t) => $t->id()
                ->column('handle', 'string', ['length' => 64, 'null' => false])
                ->column('groups', 'string', ['length' => 191, 'default' => ''])
                ->column('created_at', 'datetime')
                ->unique('handle'),
        ];
    }

    private static function db(): \Core\Database
    {
        return app()->db;
    }

    // ------------------------------------------------------------------ Mitglieder

    /** Mitglied = Profil (Datentabelle) + Zugang; id = Eintrags-ID. Ohne Zugangszeile: status 'none' (nur Profil) */
    private static function merge(array $profile, ?array $access): array
    {
        $a = $access ?? ['status' => 'none', 'password_hash' => null, 'note' => '', 'application' => '', 'avatar' => null, 'lang' => null, 'visibility' => null, 'auth_ver' => 1,
            'created_at' => $profile['created_at'] ?? null, 'invited_at' => null, 'approved_at' => null, 'last_login_at' => null];
        unset($a['id'], $a['entry_id']);
        return ['id' => (int) $profile['id'], 'email' => mb_strtolower((string) ($profile['email'] ?? '')), 'name' => (string) ($profile['name'] ?? ''),
            'profile' => $profile] + $a;
    }

    private static function access(int $id): ?array
    {
        return self::db()->fetch('SELECT * FROM members_access WHERE entry_id = ?', [$id]) ?: null;
    }

    public static function member(int $id): ?array
    {
        $p = Profiles::find($id);
        return $p ? self::merge($p, self::access($id)) : null;
    }

    public static function memberByEmail(string $email): ?array
    {
        $p = Profiles::byEmail($email);
        return $p ? self::merge($p, self::access((int) $p['id'])) : null;
    }

    /** @return list<array> mit 'groups' (Liste der Gruppen-IDs); Status 'none' = Profil ohne Zugang */
    public static function members(string $status = '', string $q = '', int $group = 0): array
    {
        $acc = [];
        foreach (self::db()->fetchAll('SELECT * FROM members_access') as $a) $acc[(int) $a['entry_id']] = $a;
        $map = self::groupMap();
        $out = [];
        $q = mb_strtolower(trim($q));
        foreach (Profiles::all() as $id => $p) {
            $m = self::merge($p, $acc[$id] ?? null);
            if ($status !== '' && $m['status'] !== $status) continue;
            if ($q !== '' && !str_contains(mb_strtolower($m['name'] . ' ' . $m['email']), $q)) continue;
            $m['groups'] = $map[$id] ?? [];
            if ($group && !in_array($group, $m['groups'], true)) continue;
            $out[] = $m;
        }
        usort($out, fn($a, $b) => [$a['status'] !== 'pending', mb_strtolower($a['name'] ?: $a['email'])] <=> [$b['status'] !== 'pending', mb_strtolower($b['name'] ?: $b['email'])]);
        return $out;
    }

    /** @return array<string, int> Anzahl je Status (none = Profile ohne Zugang) */
    public static function counts(): array
    {
        $out = array_fill_keys([...self::STATUSES, 'none'], 0);
        try {
            foreach (self::db()->fetchAll('SELECT status, COUNT(*) AS n FROM members_access GROUP BY status') as $r) $out[(string) $r['status']] = (int) $r['n'];
            $out['none'] = max(0, count(Profiles::all()) - array_sum($out));
        } catch (\Throwable) {
        }
        return $out;
    }

    /** @return array<int, list<int>> Mitglied → Gruppen */
    private static function groupMap(): array
    {
        $out = [];
        foreach (self::db()->fetchAll('SELECT member_id, group_id FROM members_member_groups') as $r) $out[(int) $r['member_id']][] = (int) $r['group_id'];
        return $out;
    }

    /** @return list<int> */
    public static function memberGroups(int $id): array
    {
        return array_map('intval', array_column(self::db()->fetchAll('SELECT group_id FROM members_member_groups WHERE member_id = ?', [$id]), 'group_id'));
    }

    /**
     * Mitglied anlegen: vorhandenes Profil (gleiche E-Mail) bekommt einen Zugang, sonst neues Profil + Zugang.
     * @return int|array ID oder Fehler
     */
    public static function createMember(string $email, string $name, string $status, array $groups = [], string $application = ''): int|array
    {
        $p = Profiles::byEmail($email);
        $id = $p ? (int) $p['id'] : Profiles::create($email, $name);
        if (is_array($id)) return $id;
        self::ensureAccess($id, $status, $application);
        if ($groups) self::setGroups($id, $groups);
        return $id;
    }

    /** Zugang zu einem Profil anlegen (z. B. Profil aus der Datenverwaltung einladen) */
    public static function ensureAccess(int $id, string $status = 'invited', string $application = ''): void
    {
        if (self::access($id)) return;
        self::db()->insert('members_access', ['entry_id' => $id, 'status' => $status, 'application' => $application !== '' ? $application : null,
            'auth_ver' => 1, 'created_at' => now()]);
    }

    /** Name/E-Mail → Profil (Datentabelle), alles andere → Zugang */
    public static function updateMember(int $id, array $data): array
    {
        $errors = Profiles::update($id, $data);
        if ($errors) return $errors;
        $allowed = array_intersect_key($data, array_flip(['password_hash', 'status', 'note', 'invited_at', 'approved_at', 'last_login_at', 'auth_ver', 'application', 'avatar', 'lang', 'visibility']));
        if ($allowed) {
            self::ensureAccess($id, (string) ($allowed['status'] ?? 'invited'));
            self::db()->update('members_access', $allowed, 'entry_id = :id', ['id' => $id]);
        }
        return [];
    }

    /** Sitzungen ungültig machen (Sperren, neues Passwort, Passkey entfernt, E-Mail geändert) */
    public static function bumpAuth(int $id): void
    {
        self::db()->query('UPDATE members_access SET auth_ver = auth_ver + 1 WHERE entry_id = ?', [$id]);
    }

    /** @param list<int> $groups */
    public static function setGroups(int $id, array $groups): void
    {
        $db = self::db();
        $valid = array_map('intval', array_column(self::groups(), 'id'));
        $db->query('DELETE FROM members_member_groups WHERE member_id = ?', [$id]);
        foreach (array_unique(array_map('intval', $groups)) as $g) {
            if (in_array($g, $valid, true)) $db->insert('members_member_groups', ['member_id' => $id, 'group_id' => $g]);
        }
    }

    /** Zugang entfernen (Profil bleibt) – auch wenn der Eintrag in der Datenverwaltung gelöscht wurde */
    public static function removeAccess(int $id): void
    {
        $db = self::db();
        if ($a = self::access($id)) Avatar::remove(['id' => $id, 'avatar' => $a['avatar']]);
        $db->query('DELETE FROM members_member_groups WHERE member_id = ?', [$id]);
        $db->query('DELETE FROM members_tokens WHERE member_id = ?', [$id]);
        \Core\Passkeys::deleteAll($db, $id, Members::PASSKEYS);
        $db->query('DELETE FROM members_access WHERE entry_id = ?', [$id]);
    }

    /** Mitglied ganz löschen: Zugang + Profil */
    public static function deleteMember(int $id): void
    {
        self::removeAccess($id);
        if (Profiles::find($id)) Profiles::delete($id);
    }

    // ------------------------------------------------------------------ Gruppen

    /** @return list<array{id:int,name:string,description:string,sort:int,count:int}> */
    public static function groups(): array
    {
        $rows = self::db()->fetchAll('SELECT g.*, (SELECT COUNT(*) FROM members_member_groups mg WHERE mg.group_id = g.id) AS count FROM members_groups g ORDER BY sort, LOWER(name)');
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'description' => (string) $r['description'],
            'sort' => (int) $r['sort'], 'count' => (int) $r['count']], $rows);
    }

    /** @return array<int, string> */
    public static function groupNames(): array
    {
        return array_column(self::groups(), 'name', 'id');
    }

    public static function saveGroup(int $id, string $name, string $description): int
    {
        $data = ['name' => mb_substr(trim($name), 0, 80), 'description' => mb_substr(trim($description), 0, 191)];
        if ($id) {
            self::db()->update('members_groups', $data, 'id = :id', ['id' => $id]);
            return $id;
        }
        return (int) self::db()->insert('members_groups', $data + ['sort' => (int) self::db()->fetchValue('SELECT COALESCE(MAX(sort), 0) + 1 FROM members_groups')]);
    }

    public static function deleteGroup(int $id): void
    {
        self::db()->query('DELETE FROM members_member_groups WHERE group_id = ?', [$id]);
        self::db()->query('DELETE FROM members_groups WHERE id = ?', [$id]);
        // Freigaben, die nur diese Gruppe hatten, würden sonst „alle Mitglieder“ bedeuten – die Gruppe bleibt als unbekannte ID
        // stehen und gewährt niemandem Zugriff (sicherer Rückfall); die Verwaltung zeigt solche Bereiche als „ohne gültige Gruppe“.
    }

    // ------------------------------------------------------------------ Einmal-Links

    /** Neues Token (Klartext zurück, gespeichert nur der Hash); ältere offene Tokens derselben Art verfallen */
    public static function token(int $member, string $kind, int $ttl): string
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $db = self::db();
        $db->query('DELETE FROM members_tokens WHERE member_id = ? AND kind = ? AND used_at IS NULL', [$member, $kind]);
        $db->insert('members_tokens', ['member_id' => $member, 'kind' => $kind, 'token_hash' => hash('sha256', $plain),
            'created_at' => now(), 'expires_at' => date('Y-m-d H:i:s', time() + $ttl)]);
        return $plain;
    }

    /** Gültiges, unbenutztes Token finden (ohne es zu verbrauchen) */
    public static function findToken(string $plain, string $kind): ?array
    {
        if (!preg_match('~^[A-Za-z0-9_-]{30,60}$~', $plain)) return null;
        $row = self::db()->fetch('SELECT * FROM members_tokens WHERE token_hash = ? AND kind = ?', [hash('sha256', $plain), $kind]);
        if (!$row || $row['used_at'] !== null || strtotime((string) $row['expires_at']) < time()) return null;
        return $row;
    }

    public static function useToken(int $id): bool
    {
        return self::db()->query('UPDATE members_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [now(), $id])->rowCount() === 1;
    }

    public static function purgeTokens(): void
    {
        self::db()->query('DELETE FROM members_tokens WHERE expires_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 7)]);
    }

    // ------------------------------------------------------------------ Geschützte Seiten

    private static ?array $areas = null;
    private static ?array $scopes = null;
    private static ?array $tableRules = null;

    /** @return array<int, string> page_id → Gruppen (CSV, '' = alle Mitglieder) – je Anfrage gemerkt */
    public static function areas(): array
    {
        if (self::$areas !== null) return self::$areas;
        try {
            $rows = self::db()->fetchAll('SELECT page_id, groups, scope FROM members_areas');
        } catch (\Throwable) {
            $rows = [];   // Tabelle fehlt (noch nicht angelegt)
        }
        self::$scopes = array_map(fn($v) => $v === 'page' ? 'page' : 'tree', array_column($rows, 'scope', 'page_id'));
        return self::$areas = array_map('strval', array_column($rows, 'groups', 'page_id'));
    }

    /** @return array<int, string> page_id → tree (mit Unterseiten) | page (nur diese Seite) */
    public static function scopes(): array
    {
        self::areas();
        return self::$scopes ?? [];
    }

    /** @param list<int>|null $groups null = Schutz aufheben, [] = alle Mitglieder */
    public static function setArea(int $pageId, ?array $groups, string $scope = 'tree'): void
    {
        $db = self::db();
        $db->query('DELETE FROM members_areas WHERE page_id = ?', [$pageId]);
        if ($groups !== null) {
            $db->insert('members_areas', ['page_id' => $pageId, 'groups' => implode(',', array_unique(array_map('intval', $groups))),
                'scope' => $scope === 'page' ? 'page' : 'tree', 'created_at' => now()]);
        }
        self::$areas = self::$scopes = null;
        Members::reset();
    }

    /** @return array<string, string> Tabelle (handle) → Gruppen (CSV, '' = alle Mitglieder) */
    public static function tableRules(): array
    {
        if (self::$tableRules !== null) return self::$tableRules;
        try {
            return self::$tableRules = array_map('strval', array_column(self::db()->fetchAll('SELECT handle, groups FROM members_tables'), 'groups', 'handle'));
        } catch (\Throwable) {
            return self::$tableRules = [];
        }
    }

    /** @param list<int>|null $groups null = Schutz aufheben */
    public static function setTableRule(string $handle, ?array $groups): void
    {
        self::db()->query('DELETE FROM members_tables WHERE handle = ?', [$handle]);
        if ($groups !== null) self::db()->insert('members_tables', ['handle' => $handle, 'groups' => implode(',', array_unique(array_map('intval', $groups))), 'created_at' => now()]);
        self::$tableRules = null;
        Members::reset();
    }
}
