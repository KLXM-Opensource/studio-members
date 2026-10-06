<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
/**
 * Erweiterung „members“: geschützte Bereiche der Website mit Anmeldung (Passwort, Passkey, Anmelde-Link per E-Mail),
 * Gruppen, Einladungen und Anträgen; Profile als Datentabelle; Dateien geschützter Pools der Mediathek nur für angemeldete Mitglieder.
 *
 * Aktivieren je Website: Administration → Funktionen & Erweiterungen oder 'extensions' => ['members'].
 * Recht: „Mitglieder verwalten“ (members.manage). Doku: README.md.
 * Braucht Core ≥ 1.0.0 mit Core\PageAccess (Extension::pageAccess) und Passkeys mit eigener Tabelle.
 */
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Klxm\\Members\\')) {
        $file = __DIR__ . '/src/' . substr($class, strlen('Klxm\\Members\\')) . '.php';
        if (is_file($file)) require $file;
    }
});

use Klxm\Members\AdminController;
use Klxm\Members\Members;
use Klxm\Members\Repo;
use Klxm\Members\SiteController;

return [
    'name' => 'members',
    'label' => 'Mitgliederbereich – geschützte Seiten und Dateien',
    'version' => '0.1.0',
    'requires' => '>=1.0.0',
    'description' => 'Geschützte Seiten und Datentabellen mit Anmeldung (Passwort, Passkey, Anmelde-Link), Gruppen, Einladungen und Anträgen; Mitgliederprofile; geschützte Medien.',
    'author' => 'KLXM Crossmedia GmbH and contributors',
    'license' => 'MIT',
    'provides' => ['Menüpunkt „Mitglieder“ (Mitglieder, Gruppen, geschützte Seiten, Dateien, Einstellungen)', 'Anmeldung und Konto unter /mitglieder',
        'Dateien geschützter Pools der Mediathek für angemeldete Mitglieder', 'Karte „Mitgliederbereich“ in den Seiteneinstellungen', 'Recht „Mitglieder verwalten“'],
    'commands' => ['members:selftest'],
    'usage' => function (): ?string {
        try {
            $c = Repo::counts();
        } catch (\Throwable) {
            return null;
        }
        $n = array_sum($c);
        return $n ? __('{n} Mitglieder', ['n' => $n]) . ($c['pending'] ? ' · ' . __('{n} Anträge offen', ['n' => $c['pending']]) : '') : null;
    },
    'boot' => function (Core\Extension $x): void {
        if (!method_exists($x, 'pageAccess')) {
            error_log('[members] Core ohne Extension::pageAccess – Erweiterung inaktiv (Core aktualisieren).');
            return;
        }
        $x->feature(Members::FEATURE, 'Mitgliederbereich: geschützte Seiten und Dateien', [Members::PERM]);
        $x->permissions('Mitglieder', [Members::PERM => 'Mitglieder, Gruppen, geschützte Seiten und Dateien verwalten']);
        foreach (Repo::tables() as $name => $define) $x->table($name, $define);
        $x->migration(1, fn(Core\Database $db) => Core\Passkeys::ensureTable($db, Members::PASSKEYS));

        // Zugriffsschutz (Core\PageAccess): kein Seiten-Cache, nicht in Menü/Sitemap/Suche, Anmeldung für Besucher
        $x->pageAccess([
            'restricted' => fn(array $page): bool => Core\Features::on(Members::FEATURE) && Members::areaFor($page) !== null,
            'allow' => fn(array $page, Core\Http\Request $r, ?array $table = null) => Members::allow($page, $r, $table),
            'table' => fn(array $table): bool => Core\Features::on(Members::FEATURE) && Members::tableGroups($table) !== null,
            'entry' => fn(array $table, array $entry, string $audience): ?array => Members::filterEntry($table, $entry, $audience),
        ]);

        // Profile = Datentabelle „Mitglieder“: Zugang je Zeile, Aufräumen beim Löschen, Sitzungen beenden bei neuer E-Mail
        $x->tableActions(fn(array $t): ?array => Klxm\Members\Profiles::isTable($t) ? [
            'actions' => [['label' => __('Zugänge und Anträge'), 'href' => '/admin/mitglieder', 'icon' => 'lock']],
            'row' => [['label' => __('Zugang'), 'href' => '/admin/mitglieder/{id}']],
        ] : null, Members::PERM);
        $x->on('entry.deleted', function (array $table, int $id): void {
            if (Klxm\Members\Profiles::isTable($table)) Repo::removeAccess($id);
        });
        $x->on('entry.saved', function (array $table, array $entry, bool $created, ?array $old): void {
            if (Klxm\Members\Profiles::isTable($table) && $old && mb_strtolower((string) ($old['email'] ?? '')) !== mb_strtolower((string) ($entry['email'] ?? ''))) {
                Repo::bumpAuth((int) $entry['id']);
            }
        });

        // Geschützte Pools der Mediathek (Core\MediaPools): angemeldete Mitglieder dürfen die Dateien abrufen
        $x->mediaAccess(fn(array $ctx, Core\Http\Request $r) => Members::mediaAllowed($r));

        $x->adminPage(['href' => '/admin/mitglieder', 'label' => 'Mitglieder', 'kind' => 'content', 'icon' => 'users', 'perm' => Members::PERM,
            'feature' => Members::FEATURE, 'description' => 'Mitglieder einladen, Anträge freischalten, Seiten und Dateien schützen']);
        $x->adminAssets(fn(string $view) => $view === 'members' ? ['css/members-admin.css'] : []);

        // Seitenbaum: Schloss an geschützten Seiten; Seiteneinstellungen: Karte mit Zugriff
        $x->pageList(function (array $page): array {
            if (!Core\Features::on(Members::FEATURE) || ($g = Members::areaFor($page)) === null) return [];
            return ['badges' => [['label' => __('Mitglieder'), 'icon' => 'lock']],
                'actions' => [['label' => __('Zugriff (Mitglieder)'), 'href' => url('/admin/mitglieder/seite/' . (int) $page['id'])]]];
        }, Members::PERM);
        $x->pagePanel(function (array $page): array {
            if (!Core\Features::on(Members::FEATURE)) return [];
            $g = Members::areaFor($page);
            $own = isset(Repo::areas()[(int) $page['id']]);
            $names = Repo::groupNames();
            $who = $g === null ? '' : (($ids = array_filter(array_map('intval', explode(',', $g)))) ? implode(', ', array_map(fn($i) => $names[$i] ?? '?', $ids)) : __('alle Mitglieder'));
            return ['title' => __('Mitgliederbereich'),
                'text' => $g === null ? __('Öffentlich – für alle Besucher sichtbar.') : ($own ? __('Geschützt für: {who}', ['who' => $who]) : __('Geschützt über eine übergeordnete Seite ({who}).', ['who' => $who])),
                'actions' => [['label' => __('Zugriff festlegen'), 'href' => url('/admin/mitglieder/seite/' . (int) $page['id'])]]];
        }, Members::PERM);

        $x->routes(function (Core\Http\Router $r): void {
            $s = SiteController::class;
            $r->get(Members::BASE, [$s, 'index']);
            $r->get(Members::BASE . '/anmelden', [$s, 'login']);
            $r->post(Members::BASE . '/anmelden', [$s, 'attempt']);
            $r->post(Members::BASE . '/abmelden', [$s, 'logout']);
            $r->post(Members::BASE . '/link', [$s, 'requestLink']);
            $r->get(Members::BASE . '/link/{token}', [$s, 'link']);
            $r->post(Members::BASE . '/link/{token}', [$s, 'useLink']);
            $r->get(Members::BASE . '/einladung/{token}', [$s, 'invite']);
            $r->post(Members::BASE . '/einladung/{token}', [$s, 'acceptInvite']);
            $r->get(Members::BASE . '/zugang', [$s, 'apply']);
            $r->post(Members::BASE . '/zugang', [$s, 'submitApplication']);
            $r->get(Members::BASE . '/konto', [$s, 'account']);
            $r->post(Members::BASE . '/konto', [$s, 'saveAccount']);
            $r->post(Members::BASE . '/konto/passwort', [$s, 'savePassword']);
            $r->post(Members::BASE . '/konto/foto', [$s, 'saveAvatar']);
            $r->post(Members::BASE . '/konto/bestaetigen', [$s, 'confirmPassword']);
            $r->post(Members::BASE . '/konto/bestaetigen/mail', [$s, 'confirmMail']);
            $r->get(Members::BASE . '/bestaetigen/{token}', [$s, 'confirmLinkPage']);
            $r->post(Members::BASE . '/bestaetigen/{token}', [$s, 'confirmLink']);
            $r->post(Members::BASE . '/passkey/confirm-options', [$s, 'passkeyConfirmOptions']);
            $r->post(Members::BASE . '/passkey/confirm', [$s, 'passkeyConfirm']);
            $r->get(Members::BASE . '/avatar/{id}/{v}', [$s, 'avatar']);
            $r->post(Members::BASE . '/passkey/login-options', [$s, 'passkeyLoginOptions']);
            $r->post(Members::BASE . '/passkey/login', [$s, 'passkeyLogin']);
            $r->post(Members::BASE . '/passkey/add-options', [$s, 'passkeyAddOptions']);
            $r->post(Members::BASE . '/passkey/add', [$s, 'passkeyAdd']);
            $r->post(Members::BASE . '/passkey/{id}/entfernen', [$s, 'passkeyDelete']);

            $c = AdminController::class;
            $p = Members::PERM;
            $r->get('/admin/mitglieder', [$c, 'index'], $p);
            $r->post('/admin/mitglieder/einladen', [$c, 'invite'], $p);
            $r->get('/admin/mitglieder/gruppen', [$c, 'groups'], $p);
            $r->post('/admin/mitglieder/gruppen', [$c, 'saveGroup'], $p);
            $r->post('/admin/mitglieder/gruppen/{id}/loeschen', [$c, 'deleteGroup'], $p);
            $r->get('/admin/mitglieder/bereiche', [$c, 'areas'], $p);
            $r->post('/admin/mitglieder/bereiche', [$c, 'addArea'], $p);
            $r->get('/admin/mitglieder/seite/{id}', [$c, 'area'], $p);
            $r->post('/admin/mitglieder/tabelle/{handle}', [$c, 'saveTable'], $p);
            $r->post('/admin/mitglieder/seite/{id}', [$c, 'saveArea'], $p);
            $r->get('/admin/mitglieder/einstellungen', [$c, 'settings'], $p);
            $r->post('/admin/mitglieder/einstellungen', [$c, 'saveSettings'], $p);
            $r->get('/admin/mitglieder/{id}', [$c, 'edit'], $p);
            $r->post('/admin/mitglieder/{id}', [$c, 'save'], $p);
            $r->post('/admin/mitglieder/{id}/einladung', [$c, 'sendInvite'], $p);
            $r->post('/admin/mitglieder/{id}/ablehnen', [$c, 'decline'], $p);
            $r->post('/admin/mitglieder/{id}/loeschen', [$c, 'delete'], $p);
            $r->post('/admin/mitglieder/{id}/zugang-zuruecksetzen', [$c, 'resetAccess'], $p);
            $r->post('/admin/mitglieder/{id}/foto-entfernen', [$c, 'removeAvatar'], $p);
        });

        $x->command('members:selftest', 'Mitgliederbereich: Zugriffsregeln, Tokens, Dateiprüfung, Passkey-Tabelle', fn(array $args): int => Klxm\Members\SelfTest::run());
    },
];
