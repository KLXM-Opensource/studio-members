<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

/**
 * php bin/console members:selftest – Zugriffsregeln (Gruppen, Vererbung, Core\PageAccess, Menü/Sitemap-Filter), Ziel nach
 * der Anmeldung, Einmal-Links, Dateiprüfung, getrennte Passkey-Tabelle. Läuft in einer Transaktion, die zurückgerollt wird.
 */
final class SelfTest
{
    public static function run(): int
    {
        $fail = 0;
        $eq = function (string $name, mixed $got, mixed $want) use (&$fail): void {
            $ok = $got === $want;
            if (!$ok) $fail++;
            echo ($ok ? '  ✓ ' : '  ✗ ') . $name . ($ok ? '' : ' – erwartet ' . var_export($want, true) . ', erhalten ' . var_export($got, true)) . "\n";
        };

        echo "Ziel nach der Anmeldung\n";
        $eq('eigener Pfad bleibt', Members::safeTarget('/intern/protokolle?x=1'), '/intern/protokolle?x=1');
        $eq('fremde Domain (//) → Übersicht', Members::safeTarget('//evil.example/x'), Members::safeTarget(''));
        $eq('absolute URL → Übersicht', Members::safeTarget('https://evil.example/'), Members::safeTarget(''));
        $eq('Backslash → Übersicht', Members::safeTarget('/\\evil.example'), Members::safeTarget(''));

        echo "Gruppen\n";
        $m = ['id' => 1, 'status' => 'active', 'groups' => [2, 5]];
        $eq('alle Mitglieder (leer)', Members::allowed($m, ''), true);
        $eq('passende Gruppe', Members::allowed($m, '3,5'), true);
        $eq('fremde Gruppe', Members::allowed($m, '3'), false);
        $eq('gelöschte Gruppe gewährt niemandem Zugriff', Members::allowed($m, '999'), false);
        $eq('gesperrt', Members::allowed(['status' => 'blocked', 'groups' => []] + $m, ''), false);
        $eq('ohne Anmeldung', Members::allowed(null, ''), false);

        $db = app()->db;
        $pdo = $db->pdo;
        Profiles::table();   // Datentabelle „Mitglieder“ vor der Transaktion anlegen (DDL)
        $pdo->beginTransaction();
        try {
            echo "Einmal-Links\n";
            $corePk = (int) $db->fetchValue('SELECT COUNT(*) FROM user_passkeys');   // Vergleichswert: Mitglieder legen dort nie etwas an
            $id = Repo::createMember('selftest-' . bin2hex(random_bytes(3)) . '@example.org', 'Test', 'active');
            $eq('Mitglied = Eintrag der Datentabelle', is_int($id) && Profiles::find($id) !== null, true);
            $eq('keine Zugangsdaten in der Datentabelle', array_intersect(array_keys(Profiles::find($id) ?? []), ['password_hash', 'auth_ver', 'status_access']), []);

            echo "Sichtbarkeit des Profils\n";
            $e = array_replace(Profiles::find($id), ['vita' => 'Vita']);
            $eq('aktiv, Name nur Mitglieder → öffentlich unsichtbar', Profiles::filter($e, 'public'), null);
            $mem = Profiles::filter($e, 'members');
            $eq('für Mitglieder sichtbar', $mem !== null && $mem['vita'] === 'Vita', true);
            $eq('E-Mail nicht (nur Redaktion)', $mem !== null && $mem['email'] === null, true);
            Repo::updateMember($id, ['visibility' => json_encode(['name' => 'public', 'vita' => 'members', 'email' => 'public'])]);
            Profiles::reset();
            $pub = Profiles::filter($e, 'public');
            $eq('Name öffentlich → öffentlich sichtbar', $pub !== null && $pub['name'] === 'Test', true);
            $eq('Vita nur Mitglieder → öffentlich leer', $pub !== null && $pub['vita'] === null, true);
            $eq('E-Mail öffentlich gewählt', $pub !== null && $pub['email'] !== null, true);
            Repo::updateMember($id, ['status' => 'blocked']);
            Profiles::reset();
            $eq('gesperrt → nirgends', Profiles::filter($e, 'members'), null);
            Repo::updateMember($id, ['status' => 'active']);
            $t = Repo::token($id, 'link', 60);
            $row = Repo::findToken($t, 'link');
            $eq('Token gefunden', $row !== null, true);
            $eq('nur Hash gespeichert', (bool) $db->fetchValue('SELECT COUNT(*) FROM members_tokens WHERE token_hash = ?', [$t]), false);
            $eq('andere Art passt nicht', Repo::findToken($t, 'invite'), null);
            $eq('einmal verwendbar', Repo::useToken((int) $row['id']), true);
            $eq('zweites Mal nicht', Repo::useToken((int) $row['id']), false);
            $eq('verbraucht nicht mehr gültig', Repo::findToken($t, 'link'), null);
            $t2 = Repo::token($id, 'link', -10);
            $eq('abgelaufen ungültig', Repo::findToken($t2, 'link'), null);
            $eq('Unsinn ungültig', Repo::findToken("x' OR 1=1 --", 'link'), null);

            echo "Passkeys\n";
            $eq('eigene Tabelle', (int) $db->fetchValue('SELECT COUNT(*) FROM ' . Members::PASSKEYS) >= 0, true);
            // Mitglieder-IDs und Konten der Verwaltung sind getrennte Nummernkreise – geprüft wird, dass die Kern-Tabelle unverändert bleibt
            $eq('nie in user_passkeys', (int) $db->fetchValue('SELECT COUNT(*) FROM user_passkeys'), $corePk);

            echo "Geschützte Seiten\n";
            $parent = $db->fetch("SELECT p.* FROM pages p WHERE EXISTS (SELECT 1 FROM pages c WHERE c.parent_id = p.id) AND p.type = 'page' AND p.is_home = 0 LIMIT 1");
            if (!$parent) {
                echo "  – übersprungen (keine Seite mit Unterseiten)\n";
            } else {
                $child = $db->fetch('SELECT * FROM pages WHERE parent_id = ? LIMIT 1', [(int) $parent['id']]);
                Repo::setArea((int) $parent['id'], []);
                $eq('Seite geschützt', Members::areaFor($parent), '');
                $eq('Unterseite erbt', Members::areaFor($child), '');
                $eq('Core\PageAccess::restricted', \Core\PageAccess::restricted($child), true);
                $eq('aus Listen entfernt', in_array((int) $child['id'], array_map(fn($p) => (int) $p['id'], \Core\PageAccess::visible([$parent, $child])), true), app()->auth->check());
                $eq('nicht im Menü', in_array((int) $parent['id'], self::ids(\Core\Pages::menu()), true), false);
                Repo::setArea((int) $parent['id'], [], 'page');
                $eq('„nur diese Seite“: Seite geschützt', Members::areaFor($parent), '');
                $eq('„nur diese Seite“: Unterseite frei', Members::areaFor($child), null);
                Repo::setArea((int) $parent['id'], []);
                $g = Repo::saveGroup(0, 'Selbsttest', '');
                Repo::setArea((int) $child['id'], [$g]);
                $eq('eigene Regel der Unterseite gilt', Members::areaFor($child), (string) $g);
                Repo::setArea((int) $parent['id'], null);
                Repo::setArea((int) $child['id'], null);
                $eq('Schutz aufgehoben', Members::areaFor($child), null);
                $eq('Core\PageAccess frei', \Core\PageAccess::restricted($child), false);
            }
            echo "Datentabellen\n";
            $t = \Core\Data\Tables::content()[0] ?? null;
            if (!$t) {
                echo "  – übersprungen (keine Datentabelle)\n";
            } else {
                Repo::setTableRule((string) $t['handle'], []);
                $eq('Tabelle geschützt (Core\\PageAccess)', \Core\PageAccess::tableRestricted($t), true);
                $eq('Gruppen der Tabelle', Members::tableGroups($t), '');
                $eq('Konsole sieht weiter alle Einträge', \Core\PageAccess::hidesTable($t), false);
                Repo::setTableRule((string) $t['handle'], null);
                $eq('Tabelle frei', \Core\PageAccess::tableRestricted($t), false);
            }
        } finally {
            $pdo->rollBack();
            Members::reset();
        }
        echo $fail ? "\n$fail Prüfungen fehlgeschlagen\n" : "\nAlle Prüfungen bestanden\n";
        return $fail ? 1 : 0;
    }

    private static function ids(array $menu): array
    {
        $out = [];
        foreach ($menu as $n) {
            $out[] = (int) $n['id'];
            $out = array_merge($out, self::ids($n['children'] ?? []));
        }
        return $out;
    }
}
