<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Http\Request;
use Core\Http\Response;

/**
 * Mitgliederbereich: Anmeldung, Sitzung, Zugriffsregeln.
 *
 *  - Sitzung: dieselbe Sitzung wie die Verwaltung (Cookie cms_sess, HttpOnly, SameSite=Lax), aber eigener Schlüssel 'member'
 *    (id + auth_ver) – nie 'uid', Mitglieder kommen also nie in die Verwaltung. Abmelden entfernt nur diesen Schlüssel.
 *    Die Sitzung startet erst mit der Anmeldung; Besucher ohne Anmeldung bekommen weiterhin kein Cookie.
 *  - Geschützte Seite = Seite mit Eintrag in members_areas oder deren Unterseite (nächster geschützter Vorfahr entscheidet).
 *    Gruppen '' = alle aktiven Mitglieder, sonst mindestens eine der Gruppen. Unbekannte Gruppen-IDs gewähren niemandem Zugriff.
 *  - Durchsetzung im Core (Core\PageAccess): kein Seiten-Cache, private Antwort, nicht in Menü/Sitemap/Suche/llms.txt.
 */
final class Members
{
    public const NAME = 'members';
    public const FEATURE = 'members';
    public const PERM = 'members.manage';
    public const PASSKEYS = 'members_passkeys';
    public const BASE = '/mitglieder';
    public const INVITE_TTL = 86400 * 14;
    public const LINK_TTL = 900;
    /** So lange gilt eine Bestätigung für Änderungen an den Zugangsdaten (Sekunden) */
    public const CONFIRM_TTL = 600;

    private static ?array $current = null;
    private static bool $loaded = false;
    private static ?array $parents = null;

    public static function asset(string $path): string
    {
        $x = \Core\Extensions::active()[self::NAME] ?? null;
        return $x ? $x->asset($path) : '';
    }

    /**
     * Adresse im Mitgliederbereich. Mehrsprachig wie die Formularseiten des Cores: Sprache als ?lang=xx (Standardsprache ohne),
     * Texte der Seiten mit lt() (lang/site/{lang}.php), Einstellungstexte je Sprache (settings()).
     */
    public static function url(string $sub = '', array $query = []): string
    {
        $l = \Core\Lang::current();
        if (\Core\Lang::multi() && $l !== \Core\Lang::default()) $query = ['lang' => $l] + $query;
        $q = array_filter($query, fn($v) => $v !== null && $v !== '');
        return url(self::BASE . ($sub !== '' ? '/' . ltrim($sub, '/') : '')) . ($q ? '?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /** Sprache der Anfrage setzen (?lang=, Feld _lang, sonst Seite, von der aus aufgerufen wurde) – wie Core\FormController */
    public static function useLang(Request $r): void
    {
        (new \Core\Http\Controllers\FormController())->useLang($r);
        if (!isset($r->query['lang']) && !isset($r->post['_lang']) && is_string($to = $r->query['ziel'] ?? $r->post['ziel'] ?? null)
            && preg_match('~^/([a-z]{2}(?:-[a-z]{2})?)(?:/|$)~', $to, $m) && \Core\Lang::valid($m[1])) {
            app()->lang = $m[1];   // Ziel /en/… → englische Anmeldeseite
        }
    }

    /** Code in der Sprache eines Mitglieds ausführen (E-Mails aus der Verwaltung) */
    public static function inLang(?string $lang, callable $fn): mixed
    {
        $old = app()->lang;
        if ($lang && \Core\Lang::valid($lang)) app()->lang = $lang;
        try {
            return $fn();
        } finally {
            app()->lang = $old;
        }
    }

    // ------------------------------------------------------------------ Einstellungen

    /** @return array{apply:bool, apply_text:string, notify:string, intro:string, home:int, magic:bool} */
    public static function settings(): array
    {
        $s = app()->settings;
        // Texte je Sprache: members.intro.en, Rückfall auf die Standardsprache
        $tr = function (string $k) use ($s): string {
            $l = \Core\Lang::current();
            $v = $l !== \Core\Lang::default() ? trim((string) $s->get("members.$k.$l", '')) : '';
            return $v !== '' ? $v : (string) $s->get("members.$k", '');
        };
        return [
            'apply' => (bool) $s->get('members.apply', false),
            'apply_text' => $tr('apply_text'),
            'notify' => (string) $s->get('members.notify', ''),
            'intro' => $tr('intro'),
            'home' => (int) $s->get('members.home', 0),
            'magic' => (bool) $s->get('members.magic', true),
        ];
    }

    // ------------------------------------------------------------------ Sitzung

    /** Angemeldetes, aktives Mitglied dieser Anfrage (oder null) */
    public static function current(): ?array
    {
        if (self::$loaded) return self::$current;
        self::$loaded = true;
        $sess = app()->session;
        if (!$sess->started()) return null;   // kein Cookie → nie angemeldet
        $s = $sess->get('member');
        if (!is_array($s) || empty($s['id'])) return null;
        $m = Repo::member((int) $s['id']);
        if (!$m || $m['status'] !== 'active' || (int) $m['auth_ver'] !== (int) ($s['v'] ?? 0)) {
            $sess->forget('member');
            return null;
        }
        $m['groups'] = Repo::memberGroups((int) $m['id']);
        return self::$current = $m;
    }

    /** Sitzung starten (Routen des Mitgliederbereichs; Besucher-Seiten starten sie nur bei vorhandenem Cookie) */
    public static function startSession(Request $r): void
    {
        $s = app()->session;
        if (!$s->started()) $s->start($r->isSecure());
    }

    public static function login(array $m, Request $r): void
    {
        self::startSession($r);
        app()->session->regenerate();   // keine Sitzungsfixierung
        $c = app()->session->get('member_confirmed');
        if (is_array($c) && (int) ($c['id'] ?? 0) !== (int) $m['id']) app()->session->forget('member_confirmed');
        app()->session->set('member', ['id' => (int) $m['id'], 'v' => (int) $m['auth_ver'], 'at' => time()]);
        Repo::updateMember((int) $m['id'], ['last_login_at' => now(), 'lang' => \Core\Lang::current()]);
        self::$loaded = false;
        self::$current = null;
    }

    /** Hat das angemeldete Mitglied in den letzten 10 Minuten bestätigt, dass es selbst am Gerät ist? */
    public static function confirmed(): bool
    {
        $m = self::current();
        $c = app()->session->get('member_confirmed');
        return $m && is_array($c) && (int) ($c['id'] ?? 0) === (int) $m['id'] && time() - (int) ($c['at'] ?? 0) <= self::CONFIRM_TTL;
    }

    public static function confirm(): void
    {
        if ($m = self::current()) app()->session->set('member_confirmed', ['id' => (int) $m['id'], 'at' => time()]);
    }

    public static function logout(): void
    {
        $s = app()->session;
        if ($s->started()) {
            $s->forget('member_confirmed');
            $s->forget('member');
            $s->regenerate();
        }
        self::$loaded = true;
        self::$current = null;
    }

    /** Nach Änderungen an Regeln (Verwaltung, Tests) */
    public static function reset(): void
    {
        self::$parents = null;
        self::$loaded = false;
        self::$current = null;
        \Core\PageAccess::reset();
    }

    // ------------------------------------------------------------------ Zugriff

    /** Gruppen (CSV) des geschützten Bereichs, zu dem die Seite gehört – null = frei */
    public static function areaFor(array $page): ?string
    {
        $areas = Repo::areas();
        if (!$areas) return null;
        if (self::$parents === null) {
            self::$parents = [];
            foreach (app()->db->fetchAll('SELECT id, parent_id FROM pages') as $p) self::$parents[(int) $p['id']] = (int) ($p['parent_id'] ?? 0);
        }
        $scopes = Repo::scopes();
        $id = (int) ($page['id'] ?? 0);
        $self = $id;
        $seen = [];
        while ($id && !isset($seen[$id])) {
            // Regel der Seite selbst gilt immer; die eines Vorfahren nur, wenn sie für Unterseiten gilt (scope tree)
            if (isset($areas[$id]) && ($id === $self || ($scopes[$id] ?? 'tree') === 'tree')) return $areas[$id];
            $seen[$id] = true;
            $id = self::$parents[$id] ?? 0;
        }
        return null;
    }

    /** Core\PageAccess 'entry': Profile nach Wahl der Mitglieder, andere geschützte Tabellen öffentlich gar nicht */
    public static function filterEntry(array $table, array $entry, string $audience): ?array
    {
        if (Profiles::isTable($table)) return Profiles::filter($entry, $audience);
        return $audience === 'public' ? null : $entry;
    }

    /** Gruppen (CSV) einer geschützten Datentabelle – null = frei */
    public static function tableGroups(array $table): ?string
    {
        return Repo::tableRules()[(string) ($table['handle'] ?? '')] ?? null;
    }

    /** Darf das Mitglied einen Bereich mit diesen Gruppen sehen? */
    public static function allowed(?array $m, string $groups): bool
    {
        if (!$m || $m['status'] !== 'active') return false;
        $need = array_filter(array_map('intval', explode(',', $groups)));
        if (!$need) return true;
        return (bool) array_intersect($need, $m['groups'] ?? Repo::memberGroups((int) $m['id']));
    }

    /** Core\PageAccess: 'allow' – angemeldet und berechtigt → true; nicht angemeldet → Anmeldung; sonst Hinweis „kein Zugriff“ */
    public static function allow(array $page, Request $r, ?array $table = null): bool|Response
    {
        $m = self::current();
        if (!$m) {
            $to = '/' . ltrim($r->path, '/');
            return Response::redirect(self::url('anmelden', ['ziel' => $to]), 302);
        }
        // Seite (falls geschützt) UND Tabelle (falls geschützt) müssen passen
        $area = self::areaFor($page);
        $tg = $table ? self::tableGroups($table) : null;
        if (($area === null || self::allowed($m, $area)) && ($tg === null || self::allowed($m, $tg))) return true;
        return (new SiteController())->message(lt('Kein Zugriff'), lt('Dieser Bereich ist für Ihr Konto nicht freigegeben. Bitte wenden Sie sich an die Betreiber der Website.'), 403);
    }

    /** Core\MediaPools (geschützter Pool): angemeldet und aktiv → ja; direkter Aufruf ohne Anmeldung → Anmeldeseite, sonst nein */
    public static function mediaAllowed(Request $r): bool|Response
    {
        if (self::current()) return true;
        $nav = ($r->server['HTTP_SEC_FETCH_DEST'] ?? '') === 'document' || str_contains((string) ($r->server['HTTP_ACCEPT'] ?? ''), 'text/html');
        return $nav ? Response::redirect(self::url('anmelden', ['ziel' => '/' . ltrim($r->path, '/')]), 302) : false;
    }

    /** Ziel nach der Anmeldung: nur Pfade dieser Website */
    public static function safeTarget(mixed $to): string
    {
        $to = is_string($to) ? trim($to) : '';
        if ($to === '' || $to[0] !== '/' || str_starts_with($to, '//') || str_contains($to, '\\') || preg_match('~[\x00-\x1f]~', $to)) {
            $home = self::settings()['home'];
            $p = $home ? \Core\Pages::find($home) : null;
            return $p ? \Core\Pages::url($p) : self::url();
        }
        return $to;
    }

    /** Für Mitglieder freigegebene Seiten (Übersicht /mitglieder) – nur Startseiten der Bereiche */
    public static function areaPages(?array $m): array
    {
        $out = [];
        foreach (Repo::areas() as $pid => $groups) {
            $p = \Core\Pages::find((int) $pid);
            if (!$p || $p['status'] !== 'published' || !self::allowed($m, $groups)) continue;
            if (\Core\Lang::multi() && \Core\Lang::norm((string) ($p['lang'] ?? '')) !== \Core\Lang::current()) continue;
            $out[] = $p;
        }
        usort($out, fn($a, $b) => strcasecmp((string) $a['title'], (string) $b['title']));
        return $out;
    }
}
