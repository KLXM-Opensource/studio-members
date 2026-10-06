<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Fields;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Pages;
use Core\Theme;

/**
 * Verwaltung → Mitglieder (Recht members.manage): Mitglieder (einladen, Anträge freischalten, Gruppen, sperren, löschen),
 * Gruppen, geschützte Seiten, geschützte Dateien (Ordner mit Gruppen), Einstellungen.
 */
final class AdminController extends \Core\Http\Controllers\Admin\AdminController
{
    private function guard(Request $r): array
    {
        if (!\Core\Extensions::isActive(Members::NAME) || !\Core\Features::on(Members::FEATURE)) throw new HttpException(404);
        $u = $this->auth($r, Members::PERM);
        Repo::purgeTokens();
        return $u;
    }

    private function page(string $view, array $vars = [], int $status = 200): Response
    {
        $vars += ['errors' => [], 'tab' => $view];
        $vars['flash'] = app()->session->takeFlash();
        $vars['css'] = [];
        $vars['user'] = app()->auth->user();
        $vars['counts'] = Repo::counts();
        $dir = dirname(__DIR__) . '/views/admin/';
        $content = Theme::capture($dir . '_nav.php', $vars) . Theme::capture($dir . $view . '.php', $vars);
        $html = Theme::capture(ROOT . '/app/Admin/views/layout.php', $vars + ['content' => $content, 'view' => 'members', 'title' => $vars['title'] ?? __('Mitglieder')]);
        return self::secure(new Response($html, $status));
    }

    private function to(string $path, string $type, string $msg): Response
    {
        app()->session->flash($type, $msg);
        return Response::redirect(url($path), 303);
    }

    private static function groupOptions(): array
    {
        return array_map('strval', Repo::groupNames());
    }

    // ------------------------------------------------------------------ Mitglieder

    public static function inviteFields(): array
    {
        return [
            ['name' => 'email', 'label' => __('E-Mail-Adresse'), 'type' => 'email', 'required' => true, 'width' => 'half'],
            ['name' => 'name', 'label' => __('Name'), 'type' => 'text', 'max' => 120, 'width' => 'half'],
            ['name' => 'groups', 'label' => __('Gruppen'), 'type' => 'multiselect', 'options' => self::groupOptions(),
                'help' => __('Ohne Gruppe sieht das Mitglied nur Bereiche, die für „alle Mitglieder“ freigegeben sind.')],
        ];
    }

    public static function memberFields(): array
    {
        return [
            ['name' => 'name', 'label' => __('Name'), 'type' => 'text', 'max' => 120, 'width' => 'half'],
            ['name' => 'email', 'label' => __('E-Mail-Adresse'), 'type' => 'email', 'required' => true, 'width' => 'half'],
            ['name' => 'status', 'label' => __('Status'), 'type' => 'select', 'width' => 'half', 'options' => array_diff_key(self::statusLabels(), ['none' => 1])],
            ['name' => 'groups', 'label' => __('Gruppen'), 'type' => 'multiselect', 'options' => self::groupOptions()],
            ['name' => 'note', 'label' => __('Interne Notiz'), 'type' => 'textarea', 'max' => 2000, 'help' => __('Nur in der Verwaltung sichtbar.')],
        ];
    }

    public static function statusLabels(): array
    {
        return ['none' => __('Ohne Zugang'), 'invited' => __('Eingeladen'), 'pending' => __('Antrag offen'), 'active' => __('Aktiv'), 'blocked' => __('Gesperrt')];
    }

    public function index(Request $r): Response
    {
        $this->guard($r);
        $status = (string) ($r->query['status'] ?? '');
        $q = trim((string) ($r->query['q'] ?? ''));
        $group = (int) ($r->query['gruppe'] ?? 0);
        return $this->page('members', ['title' => __('Mitglieder'), 'rows' => Repo::members($status, $q, $group), 'status' => $status, 'q' => $q,
            'group' => $group, 'groups' => Repo::groupNames(), 'values' => [], 'mailOk' => \Core\Mailer::ready()]);
    }

    public function invite(Request $r): Response
    {
        $this->guard($r);
        [$v, $errors] = Fields::sanitize(self::inviteFields(), (array) ($r->post['f'] ?? []));
        $email = mb_strtolower(trim((string) ($v['email'] ?? '')));
        if (!$errors && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = __('Bitte eine gültige E-Mail-Adresse eingeben.');
        if (!$errors && ($o = Repo::memberByEmail($email)) && $o['status'] !== 'none') $errors['email'] = __('Zu dieser Adresse gibt es schon einen Zugang.');
        if ($errors) {
            return $this->page('members', ['title' => __('Mitglieder'), 'rows' => Repo::members(), 'status' => '', 'q' => '', 'group' => 0,
                'groups' => Repo::groupNames(), 'values' => $v, 'errors' => $errors, 'mailOk' => \Core\Mailer::ready()], 422);
        }
        $id = Repo::createMember($email, (string) ($v['name'] ?? ''), 'invited', array_map('intval', (array) ($v['groups'] ?? [])));
        if (is_array($id)) return $this->to('/admin/mitglieder', 'error', implode(' ', $id));
        $err = Mail::invite(Repo::member($id));
        return $err ? $this->to('/admin/mitglieder/' . $id, 'error', __('Mitglied angelegt, aber die Einladung konnte nicht gesendet werden: {err}', ['err' => $err]))
            : $this->to('/admin/mitglieder', 'success', __('Einladung an {email} gesendet.', ['email' => $email]));
    }

    public function edit(Request $r, string $id, array $values = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        $values = $values ?: ['name' => $m['name'], 'email' => $m['email'], 'status' => $m['status'], 'note' => (string) $m['note'],
            'groups' => array_map('strval', Repo::memberGroups((int) $m['id']))];
        return $this->page('member', ['title' => ($m['name'] ?: $m['email']) . ' · ' . __('Mitglied'), 'm' => $m, 'values' => $values, 'errors' => $errors,
            'keys' => \Core\Passkeys::list(app()->db, (int) $m['id'], null, Members::PASSKEYS), 'tab' => 'members'], $status);
    }

    public function save(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        [$v, $errors] = Fields::sanitize(self::memberFields(), (array) ($r->post['f'] ?? []));
        $email = mb_strtolower(trim((string) ($v['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = __('Bitte eine gültige E-Mail-Adresse eingeben.');
        elseif (($o = Repo::memberByEmail($email)) && (int) $o['id'] !== (int) $m['id']) $errors['email'] = __('Zu dieser Adresse gibt es schon ein Mitglied.');
        if ($errors) return $this->edit($r, $id, $v, $errors, 422);
        $status = $m['status'] === 'none' ? 'none' : (string) $v['status'];
        $data = ['name' => (string) $v['name'], 'email' => $email] + ($m['status'] === 'none' ? [] : ['status' => $status, 'note' => (string) ($v['note'] ?? '')]);
        if ($err = Repo::updateMember((int) $m['id'], $data)) return $this->edit($r, $id, $v, $err, 422);
        Repo::setGroups((int) $m['id'], array_map('intval', (array) ($v['groups'] ?? [])));
        // Gesperrt oder Adresse geändert → laufende Sitzungen beenden
        if ($status !== $m['status'] || $email !== $m['email']) Repo::bumpAuth((int) $m['id']);
        return $this->to('/admin/mitglieder/' . (int) $m['id'], 'success', __('Gespeichert.'));
    }

    /** Einladung (erneut) senden – auch „Antrag freischalten“ */
    public function sendInvite(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        if ($m['status'] === 'blocked') return $this->to('/admin/mitglieder/' . (int) $m['id'], 'error', __('Gesperrte Mitglieder bekommen keine Einladung.'));
        if ($m['status'] === 'none') {
            Repo::ensureAccess((int) $m['id'], 'invited');
            $m = Repo::member((int) $m['id']);
        }
        if ($m['status'] === 'pending') {
            Repo::updateMember((int) $m['id'], ['status' => 'invited', 'approved_at' => now()]);
            if (isset($r->post['groups'])) Repo::setGroups((int) $m['id'], array_map('intval', (array) $r->post['groups']));
            $m = Repo::member((int) $m['id']);
        }
        $err = Mail::invite($m);
        return $err ? $this->to('/admin/mitglieder/' . (int) $m['id'], 'error', __('Die Einladung konnte nicht gesendet werden: {err}', ['err' => $err]))
            : $this->to(empty($r->post['back']) ? '/admin/mitglieder/' . (int) $m['id'] : '/admin/mitglieder?status=pending', 'success', __('Einladung an {email} gesendet.', ['email' => $m['email']]));
    }

    public function decline(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        if ($m['status'] !== 'pending') throw new HttpException(400);
        if (!empty($r->post['notify'])) {
            $err = Mail::declined($m);
            if ($err) error_log('[members] Ablehnung: ' . $err);
        }
        Repo::deleteMember((int) $m['id']);   // Antrag: Profil und Zugang
        return $this->to('/admin/mitglieder?status=pending', 'success', __('Antrag von {email} abgelehnt und gelöscht.', ['email' => $m['email']]));
    }

    public function delete(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        Repo::deleteMember((int) $m['id']);
        return $this->to('/admin/mitglieder', 'success', __('{email} gelöscht.', ['email' => $m['email']]));
    }

    public function resetAccess(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        \Core\Passkeys::deleteAll(app()->db, (int) $m['id'], Members::PASSKEYS);
        Repo::updateMember((int) $m['id'], ['password_hash' => null]);
        Repo::bumpAuth((int) $m['id']);
        return $this->to('/admin/mitglieder/' . (int) $m['id'], 'success', __('Passwort und Passkeys entfernt, alle Sitzungen beendet. Das Mitglied meldet sich per Anmelde-Link an oder erhält eine neue Einladung.'));
    }

    /** Unpassendes Foto entfernen (Redaktion) */
    public function removeAvatar(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        Avatar::remove($m);
        return $this->to('/admin/mitglieder/' . (int) $m['id'], 'success', __('Foto entfernt.'));
    }

    // ------------------------------------------------------------------ Gruppen

    public function groups(Request $r): Response
    {
        $this->guard($r);
        return $this->page('groups', ['title' => __('Gruppen · Mitglieder'), 'groups' => Repo::groups()]);
    }

    public function saveGroup(Request $r): Response
    {
        $this->guard($r);
        $name = trim($r->str('name'));
        if ($name === '') return $this->to('/admin/mitglieder/gruppen', 'error', __('Bitte einen Namen angeben.'));
        Repo::saveGroup((int) ($r->post['id'] ?? 0), $name, $r->str('description'));
        return $this->to('/admin/mitglieder/gruppen', 'success', __('Gruppe „{name}“ gespeichert.', ['name' => $name]));
    }

    public function deleteGroup(Request $r, string $id): Response
    {
        $this->guard($r);
        Repo::deleteGroup((int) $id);
        Members::reset();
        return $this->to('/admin/mitglieder/gruppen', 'success', __('Gruppe gelöscht.'));
    }

    // ------------------------------------------------------------------ Geschützte Seiten

    public function areas(Request $r): Response
    {
        $this->guard($r);
        $rows = [];
        foreach (Repo::areas() as $pid => $groups) {
            $p = Pages::find((int) $pid);
            if (!$p) continue;
            $rows[] = ['page' => $p, 'groups' => array_filter(array_map('intval', explode(',', $groups))), 'scope' => Repo::scopes()[(int) $pid] ?? 'tree',
                'children' => self::countChildren((int) $pid)];
        }
        usort($rows, fn($a, $b) => strcasecmp((string) $a['page']['title'], (string) $b['page']['title']));
        $tables = array_values(array_filter(\Core\Data\Tables::content(), fn($t) => empty($t['settings']['inbox'])));
        return $this->page('areas', ['title' => __('Geschützte Seiten · Mitglieder'), 'rows' => $rows, 'groups' => Repo::groupNames(), 'tab' => 'areas',
            'tables' => $tables, 'rules' => Repo::tableRules()]);
    }

    private static function countChildren(int $id): int
    {
        $n = 0;
        $walk = function (int $pid) use (&$walk, &$n): void {
            foreach (app()->db->fetchAll('SELECT id FROM pages WHERE parent_id = ?', [$pid]) as $c) {
                $n++;
                $walk((int) $c['id']);
            }
        };
        $walk($id);
        return $n;
    }

    public static function areaFields(): array
    {
        return [
            ['name' => 'protect', 'label' => __('Nur für angemeldete Mitglieder'), 'type' => 'bool',
                'help' => __('Geschützte Seiten erscheinen nicht im Menü, in der Sitemap und in der Suche.')],
            ['name' => 'scope', 'label' => __('Gilt für'), 'type' => 'select', 'default' => 'tree', 'show_if' => ['protect' => true],
                'options' => ['tree' => __('Diese Seite und alle Unterseiten'), 'page' => __('Nur diese Seite')]],
            ['name' => 'groups', 'label' => __('Freigegeben für'), 'type' => 'multiselect', 'options' => self::groupOptions(),
                'help' => __('Keine Auswahl = alle aktiven Mitglieder.')],
        ];
    }

    public function area(Request $r, string $id): Response
    {
        $this->guard($r);
        $p = Pages::find((int) $id) ?? throw new HttpException(404);
        $areas = Repo::areas();
        $own = $areas[(int) $p['id']] ?? null;
        $inherited = $own === null ? Members::areaFor($p) : null;
        $from = null;
        if ($inherited !== null) {
            foreach (array_reverse(Pages::ancestors($p)) as $a) if (isset($areas[(int) $a['id']]) && (Repo::scopes()[(int) $a['id']] ?? 'tree') === 'tree') { $from = $a; break; }
        }
        return $this->page('area', ['title' => $p['title'] . ' · ' . __('Zugriff'), 'p' => $p, 'from' => $from, 'groups' => Repo::groupNames(),
            'values' => ['protect' => $own !== null, 'scope' => Repo::scopes()[(int) $p['id']] ?? 'tree',
                'groups' => $own !== null ? array_values(array_filter(explode(',', $own))) : []], 'tab' => 'areas']);
    }

    public function saveArea(Request $r, string $id): Response
    {
        $this->guard($r);
        $p = Pages::find((int) $id) ?? throw new HttpException(404);
        [$v] = Fields::sanitize(self::areaFields(), (array) ($r->post['f'] ?? []));
        Repo::setArea((int) $p['id'], !empty($v['protect']) ? array_map('intval', (array) ($v['groups'] ?? [])) : null, (string) ($v['scope'] ?? 'tree'));
        \Core\PageCache::clear();   // Menü und Seiten ohne den Bereich neu erzeugen
        return $this->to('/admin/mitglieder/seite/' . (int) $p['id'], 'success', !empty($v['protect']) ? __('„{title}“ ist jetzt geschützt.', ['title' => $p['title']]) : __('Schutz für „{title}“ aufgehoben.', ['title' => $p['title']]));
    }

    public static function addAreaFields(): array
    {
        return [
            ['name' => 'page', 'label' => __('Seite'), 'type' => 'link', 'required' => true, 'help' => __('Im Seitenbaum („Struktur“) wählen.')],
            ['name' => 'scope', 'label' => __('Gilt für'), 'type' => 'select', 'default' => 'tree', 'width' => 'half',
                'options' => ['tree' => __('Diese Seite und alle Unterseiten'), 'page' => __('Nur diese Seite')]],
            ['name' => 'groups', 'label' => __('Freigegeben für'), 'type' => 'multiselect', 'options' => self::groupOptions(), 'help' => __('Keine Auswahl = alle aktiven Mitglieder.')],
        ];
    }

    /** Seite schützen (Auswahl im Struktur-Browser) */
    public function addArea(Request $r): Response
    {
        $this->guard($r);
        [$v] = Fields::sanitize(self::addAreaFields(), (array) ($r->post['f'] ?? []));
        $p = preg_match('~^page:(\d+)$~', (string) ($v['page'] ?? ''), $mm) ? Pages::find((int) $mm[1]) : null;
        if (!$p) return $this->to('/admin/mitglieder/bereiche', 'error', __('Bitte eine Seite der Website wählen.'));
        Repo::setArea((int) $p['id'], array_map('intval', (array) ($v['groups'] ?? [])), (string) ($v['scope'] ?? 'tree'));
        \Core\PageCache::clear();
        return $this->to('/admin/mitglieder/bereiche', 'success', __('„{title}“ ist jetzt geschützt.', ['title' => $p['title']]));
    }

    /** Datentabelle schützen bzw. freigeben */
    public function saveTable(Request $r, string $handle): Response
    {
        $this->guard($r);
        $t = \Core\Data\Tables::findContent($handle) ?? throw new HttpException(404);
        Repo::setTableRule((string) $t['handle'], !empty($r->post['protect']) ? array_map('intval', (array) ($r->post['groups'] ?? [])) : null);
        \Core\PageCache::clear();   // Listen auf öffentlichen Seiten ohne die Einträge neu erzeugen
        return $this->to('/admin/mitglieder/bereiche#daten', 'success', !empty($r->post['protect'])
            ? __('„{name}“ ist jetzt geschützt.', ['name' => $t['name']]) : __('Schutz für „{name}“ aufgehoben.', ['name' => $t['name']]));
    }

    /** Weitere Sprachen der Website (ohne Standardsprache): Code → Name */
    private static function otherLangs(): array
    {
        $out = [];
        foreach (\Core\Lang::all() as $code => $l) if ($code !== \Core\Lang::default()) $out[$code] = is_array($l) ? (string) ($l['name'] ?? $code) : (string) $l;
        return $out;
    }

    public static function settingsFields(): array
    {
        $f = [
            ['type' => 'heading', 'label' => __('Anmeldung')],
            ['name' => 'intro', 'label' => __('Text auf der Anmeldeseite'), 'type' => 'textarea', 'max' => 600, 'help' => __('Optional, z. B. wer Zugang bekommt.')],
            ['name' => 'home', 'label' => __('Nach der Anmeldung zu'), 'type' => 'link',
                'help' => __('Seite aus dem Seitenbaum wählen. Leer = Übersicht mit allen freigegebenen Bereichen.')],
            ['name' => 'magic', 'label' => __('Anmelde-Link per E-Mail erlauben'), 'type' => 'bool', 'help' => __('Anmelden ohne Passwort: ein Link, 15 Minuten gültig, einmal verwendbar.')],
            ['type' => 'heading', 'label' => __('Zugang beantragen')],
            ['name' => 'apply', 'label' => __('Besucher können einen Zugang beantragen'), 'type' => 'bool',
                'help' => __('Auf der Anmeldeseite erscheint „Zugang beantragen“. Neue Anträge prüfen und freischalten Sie unter Mitglieder → „Offene Anträge“.')],
            ['name' => 'apply_text', 'label' => __('Text über dem Antragsformular'), 'type' => 'textarea', 'max' => 1000, 'show_if' => ['apply' => true]],
            ['name' => 'notify', 'label' => __('Hinweis auf neue Anträge an'), 'type' => 'text', 'max' => 400, 'show_if' => ['apply' => true],
                'help' => __('E-Mail-Adressen, durch Komma getrennt. Leer = Empfänger der Website (System → E-Mail-Versand).')],
        ];
        foreach (self::otherLangs() as $code => $name) {
            $f[] = ['type' => 'heading', 'label' => __('Texte auf {lang}', ['lang' => $name])];
            $f[] = ['name' => 'intro_' . $code, 'label' => __('Text auf der Anmeldeseite'), 'type' => 'textarea', 'max' => 600, 'help' => __('Leer = Text der Standardsprache.')];
            $f[] = ['name' => 'apply_text_' . $code, 'label' => __('Text über dem Antragsformular'), 'type' => 'textarea', 'max' => 1000, 'show_if' => ['apply' => true]];
        }
        return $f;
    }

    public function settings(Request $r, array $values = [], array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        if (!$values) {
            $s = app()->settings;
            $values = ['intro' => (string) $s->get('members.intro', ''), 'apply_text' => (string) $s->get('members.apply_text', ''),
                'notify' => (string) $s->get('members.notify', ''), 'magic' => (bool) $s->get('members.magic', true), 'apply' => (bool) $s->get('members.apply', false),
                'home' => ($h = (int) $s->get('members.home', 0)) ? 'page:' . $h : ''];
            foreach (array_keys(self::otherLangs()) as $code) {
                $values['intro_' . $code] = (string) $s->get("members.intro.$code", '');
                $values['apply_text_' . $code] = (string) $s->get("members.apply_text.$code", '');
            }
        }
        return $this->page('settings', ['title' => __('Einstellungen · Mitglieder'), 'values' => $values, 'errors' => $errors, 'tab' => 'settings'], $status);
    }

    public function saveSettings(Request $r): Response
    {
        $this->guard($r);
        [$v, $errors] = Fields::sanitize(self::settingsFields(), (array) ($r->post['f'] ?? []));
        $bad = array_filter(array_map('trim', explode(',', (string) ($v['notify'] ?? ''))), fn($e) => $e !== '' && !filter_var($e, FILTER_VALIDATE_EMAIL));
        if ($bad) $errors['notify'] = __('Ungültige Adresse: {list}', ['list' => implode(', ', $bad)]);
        $home = (string) ($v['home'] ?? '');
        if ($home !== '' && !preg_match('~^page:(\d+)$~', $home)) $errors['home'] = __('Bitte eine Seite der Website wählen.');
        if ($errors) return $this->settings($r, $v, $errors, 422);
        $s = app()->settings;
        $s->set('members.intro', (string) ($v['intro'] ?? ''));
        $s->set('members.home', $home !== '' ? (int) substr($home, 5) : 0);
        $s->set('members.magic', !empty($v['magic']));
        $s->set('members.apply', !empty($v['apply']));
        $s->set('members.apply_text', (string) ($v['apply_text'] ?? ''));
        $s->set('members.notify', (string) ($v['notify'] ?? ''));
        foreach (array_keys(self::otherLangs()) as $code) {
            $s->set("members.intro.$code", (string) ($v['intro_' . $code] ?? ''));
            $s->set("members.apply_text.$code", (string) ($v['apply_text_' . $code] ?? ''));
        }
        return $this->to('/admin/mitglieder/einstellungen', 'success', __('Einstellungen gespeichert.'));
    }
}
