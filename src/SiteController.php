<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Csrf;
use Core\Http\HttpException;
use Core\Http\Request;
use Core\Http\Response;
use Core\Passkeys;
use Core\RateLimiter;

/**
 * Website: /mitglieder/… – Anmeldung (Passwort, Passkey, Anmelde-Link), Einladung annehmen, Zugang beantragen, Konto,
 * geschützte Dateien. Seiten im Layout des Kits, nie im Seiten-Cache (private, no-store). Alle POSTs mit CSRF-Token der
 * Sitzung; Anmeldeversuche, Anmelde-Links und Anträge mit Mengenbegrenzung je IP (und je Adresse).
 */
final class SiteController
{
    private const MIN_PASSWORD = 10;

    // ------------------------------------------------------------------ Darstellung

    /** Seite im Layout des Kits (Inhalt aus views/site/{view}.php) */
    public function page(string $view, array $vars, string $title, int $status = 200): Response
    {
        $theme = app()->theme;
        $page = ['id' => 0, 'title' => $title, 'slug' => ltrim(Members::BASE, '/'), 'is_home' => 0, 'meta_description' => '', 'noindex' => 1, 'status' => 'published'];
        app()->currentPage = $page;
        $vars += ['title' => $title, 'errors' => [], 'notice' => app()->session->started() ? app()->session->takeFlash() : [], 'member' => Members::current()];
        $content = '<link rel="stylesheet" href="' . e(Members::asset('css/members.css')) . '">'
            . '<div class="wrap mb-wrap">' . \Core\Theme::capture(dirname(__DIR__) . '/views/site/' . $view . '.php', $vars) . '</div>';
        $seo = \Core\Seo::forError(200);
        $seo['title'] = $title;
        $html = $theme->render('layout', ['page' => $page, 'content' => $content, 'seo' => $seo, 'editor' => null, 'toolbar' => null,
            'extraCss' => $theme->conditionalCss(['form'])]);
        return (new \Core\Http\Controllers\SiteController())->respond($html, false, $status, true);
    }

    public function message(string $title, string $text, int $status = 200, array $links = []): Response
    {
        return $this->page('message', ['text' => $text, 'links' => $links], $title, $status);
    }

    private function guard(Request $r): void
    {
        if (!\Core\Extensions::isActive(Members::NAME) || !\Core\Features::on(Members::FEATURE)) throw new HttpException(404);
        Members::useLang($r);
        Members::startSession($r);
    }

    private function csrf(Request $r): bool
    {
        return Csrf::valid($r);
    }

    private function limited(string $what, string $key, int $max, int $window): bool
    {
        $l = new RateLimiter(app()->db);
        $k = 'members:' . $what . ':' . hash_hmac('sha256', $key, app()->key());
        if ($l->tooMany($k, $max, $window)) return true;
        $l->hit($k);
        return false;
    }

    private function ipKey(Request $r): string
    {
        return $r->ip();
    }

    // ------------------------------------------------------------------ Übersicht

    public function index(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 302);
        return $this->page('overview', ['areas' => Members::areaPages($m), 'groups' => Repo::groupNames()], lt('Mitgliederbereich'));
    }

    // ------------------------------------------------------------------ Anmelden

    public function login(Request $r, array $errors = [], string $email = '', int $status = 200): Response
    {
        $this->guard($r);
        $target = Members::safeTarget($r->query['ziel'] ?? $r->post['ziel'] ?? '');
        if (Members::current() && !$errors) return Response::redirect($target, 302);
        return $this->page('login', ['errors' => $errors, 'email' => $email, 'target' => $r->query['ziel'] ?? $r->post['ziel'] ?? '',
            'settings' => Members::settings(), 'passkeys' => Passkeys::available()], lt('Anmelden'), $status);
    }

    public function attempt(Request $r): Response
    {
        $this->guard($r);
        if (!$this->csrf($r)) return $this->login($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], '', 419);
        $email = mb_strtolower(trim($r->str('email')));
        $pw = (string) ($r->post['password'] ?? '');
        if ($this->limited('login', $this->ipKey($r), 8, 900) || ($email !== '' && $this->limited('login-mail', $email, 10, 3600))) {
            return $this->login($r, ['_' => lt('Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten.')], $email, 429);
        }
        $m = $email !== '' ? Repo::memberByEmail($email) : null;
        // Konstante Laufzeit auch bei unbekannter Adresse
        $hash = $m['password_hash'] ?? '$2y$12$yaNnm6RkwijxMHDaR.bX5eCLU2vn8HWPVSNJ0WE.fRaZHEEdIQoim';
        if (!password_verify($pw, (string) $hash) || !$m || $m['status'] !== 'active' || empty($m['password_hash'])) {
            error_log('[members] Anmeldung fehlgeschlagen von ' . $r->ip());
            return $this->login($r, ['_' => lt('E-Mail-Adresse oder Passwort ist falsch.')], $email, 422);
        }
        if (password_needs_rehash((string) $m['password_hash'], PASSWORD_DEFAULT)) Repo::updateMember((int) $m['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        Members::login($m, $r);
        return Response::redirect(Members::safeTarget($r->post['ziel'] ?? ''), 303);
    }

    public function logout(Request $r): Response
    {
        $this->guard($r);
        if ($this->csrf($r)) Members::logout();
        app()->session->flash('success', lt('Sie sind abgemeldet.'));
        return Response::redirect(Members::url('anmelden'), 303);
    }

    // ------------------------------------------------------------------ Anmelde-Link

    public function requestLink(Request $r): Response
    {
        $this->guard($r);
        if (!Members::settings()['magic']) throw new HttpException(404);
        if (!$this->csrf($r)) return $this->login($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], '', 419);
        $email = mb_strtolower(trim($r->str('link_email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return $this->login($r, ['link_email' => lt('Bitte eine gültige E-Mail-Adresse eingeben.')], $email, 422);
        if ($this->limited('link', $this->ipKey($r), 5, 900) || $this->limited('link-mail', $email, 3, 3600)) {
            return $this->login($r, ['_' => lt('Zu viele Anfragen. Bitte versuchen Sie es später erneut.')], $email, 429);
        }
        $m = Repo::memberByEmail($email);
        if ($m && $m['status'] === 'active') {
            $err = Mail::loginLink($m, (string) ($r->post['ziel'] ?? ''));
            if ($err) error_log('[members] Anmelde-Link: ' . $err);
        }
        // Immer dieselbe Antwort – verrät nicht, ob die Adresse ein Konto hat
        return $this->message(lt('E-Mail unterwegs'), lt('Wenn zu {email} ein Zugang besteht, ist ein Anmelde-Link unterwegs. Er ist 15 Minuten gültig. Bitte schauen Sie auch im Spam-Ordner nach.', ['email' => $email]));
    }

    /** Link aus der E-Mail: erst bestätigen (Mail-Scanner rufen Links ab – ein GET verbraucht nichts) */
    public function link(Request $r, string $token): Response
    {
        $this->guard($r);
        $t = Repo::findToken($token, 'link');
        if (!$t) return $this->message(lt('Link ungültig'), lt('Dieser Anmelde-Link ist abgelaufen oder wurde schon verwendet. Fordern Sie einfach einen neuen an.'), 410, [[lt('Zur Anmeldung'), Members::url('anmelden')]]);
        return $this->page('link', ['token' => $token, 'target' => (string) ($r->query['ziel'] ?? '')], lt('Anmelden'));
    }

    public function useLink(Request $r, string $token): Response
    {
        $this->guard($r);
        if (!$this->csrf($r)) return $this->link($r, $token);
        $t = Repo::findToken($token, 'link');
        $m = $t ? Repo::member((int) $t['member_id']) : null;
        if (!$t || !$m || $m['status'] !== 'active' || !Repo::useToken((int) $t['id'])) {
            return $this->message(lt('Link ungültig'), lt('Dieser Anmelde-Link ist abgelaufen oder wurde schon verwendet. Fordern Sie einfach einen neuen an.'), 410, [[lt('Zur Anmeldung'), Members::url('anmelden')]]);
        }
        Members::login($m, $r);
        return Response::redirect(Members::safeTarget($r->post['ziel'] ?? ''), 303);
    }

    // ------------------------------------------------------------------ Einladung

    private function inviteToken(string $token): array
    {
        $t = Repo::findToken($token, 'invite');
        $m = $t ? Repo::member((int) $t['member_id']) : null;
        if (!$t || !$m || !in_array($m['status'], ['invited', 'active'], true)) return [null, null];
        return [$t, $m];
    }

    public function invite(Request $r, string $token, array $errors = []): Response
    {
        $this->guard($r);
        [$t, $m] = $this->inviteToken($token);
        if (!$t) return $this->message(lt('Einladung ungültig'), lt('Diese Einladung ist abgelaufen oder wurde schon angenommen. Bitte fordern Sie bei den Betreibern der Website eine neue an.'), 410, [[lt('Zur Anmeldung'), Members::url('anmelden')]]);
        // Passkey statt Passwort: Registrierung erlaubt, solange diese Einladung offen ist (Sitzung)
        app()->session->set('member_invite', ['id' => (int) $m['id'], 't' => (int) $t['id'], 'at' => time()]);
        return $this->page('invite', ['token' => $token, 'm' => $m, 'errors' => $errors, 'passkeys' => Passkeys::available(), 'min' => self::MIN_PASSWORD], lt('Zugang einrichten'), $errors ? 422 : 200);
    }

    public function acceptInvite(Request $r, string $token): Response
    {
        $this->guard($r);
        if (!$this->csrf($r)) return $this->invite($r, $token, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')]);
        [$t, $m] = $this->inviteToken($token);
        if (!$t) return $this->invite($r, $token);
        $name = trim($r->str('name'));
        $pw = (string) ($r->post['password'] ?? '');
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) $errors['name'] = lt('Bitte Ihren Namen angeben.');
        if ($err = self::passwordError($pw, (string) ($r->post['password2'] ?? ''), (string) $m['email'])) $errors['password'] = $err;
        if ($errors) return $this->invite($r, $token, $errors);
        if (!Repo::useToken((int) $t['id'])) return $this->invite($r, $token);
        Repo::updateMember((int) $m['id'], ['name' => $name, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT), 'status' => 'active']);
        Repo::bumpAuth((int) $m['id']);
        app()->session->forget('member_invite');
        Members::login(Repo::member((int) $m['id']), $r);
        app()->session->flash('success', lt('Willkommen! Ihr Zugang ist eingerichtet.'));
        return Response::redirect(Members::safeTarget(''), 303);
    }

    public static function passwordError(string $pw, string $again, string $email): ?string
    {
        if (mb_strlen($pw) < self::MIN_PASSWORD) return lt('Das Passwort muss mindestens {n} Zeichen haben.', ['n' => self::MIN_PASSWORD]);
        if (strlen($pw) > 200) return lt('Das Passwort ist zu lang.');
        if ($pw !== $again) return lt('Die beiden Passwörter stimmen nicht überein.');
        if (mb_strtolower($pw) === mb_strtolower($email)) return lt('Das Passwort darf nicht die E-Mail-Adresse sein.');
        return null;
    }

    // ------------------------------------------------------------------ Zugang beantragen

    public function apply(Request $r, array $errors = [], array $values = [], int $status = 200): Response
    {
        $this->guard($r);
        $s = Members::settings();
        if (!$s['apply']) throw new HttpException(404);
        if (Members::current()) return Response::redirect(Members::url(), 302);
        return $this->page('apply', ['errors' => $errors, 'values' => $values, 'text' => $s['apply_text']], lt('Zugang beantragen'), $status);
    }

    public function submitApplication(Request $r): Response
    {
        $this->guard($r);
        if (!Members::settings()['apply']) throw new HttpException(404);
        $v = ['name' => trim($r->str('name')), 'email' => mb_strtolower(trim($r->str('email'))), 'message' => trim((string) ($r->post['message'] ?? ''))];
        if (!$this->csrf($r)) return $this->apply($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], $v, 419);
        // Falle für Bots: verstecktes Feld muss leer bleiben
        if (trim((string) ($r->post['website'] ?? '')) !== '') return $this->message(lt('Antrag gesendet'), lt('Vielen Dank! Wir prüfen Ihren Antrag und melden uns per E-Mail.'));
        $errors = [];
        if ($v['name'] === '' || mb_strlen($v['name']) > 120) $errors['name'] = lt('Bitte Ihren Namen angeben.');
        if (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 191) $errors['email'] = lt('Bitte eine gültige E-Mail-Adresse eingeben.');
        if (mb_strlen($v['message']) > 2000) $errors['message'] = lt('Bitte höchstens 2000 Zeichen.');
        if (empty($r->post['consent'])) $errors['consent'] = lt('Bitte bestätigen Sie die Datenschutzhinweise.');
        if ($errors) return $this->apply($r, $errors, $v, 422);
        if ($this->limited('apply', $this->ipKey($r), 3, 3600)) return $this->apply($r, ['_' => lt('Zu viele Anfragen. Bitte versuchen Sie es später erneut.')], $v, 429);
        // Bekannte Adresse: nichts verraten, nichts anlegen
        $known = Repo::memberByEmail($v['email']);
        if (!$known || $known['status'] === 'none') {
            $id = Repo::createMember($v['email'], $v['name'], 'pending', [], $v['message']);
            if (is_int($id)) Repo::updateMember($id, ['lang' => \Core\Lang::current()]);
            if (is_int($id) && ($err = Mail::applicationNotice(Repo::member($id)))) error_log('[members] Antrag-Hinweis: ' . $err);
        }
        return $this->message(lt('Antrag gesendet'), lt('Vielen Dank! Wir prüfen Ihren Antrag und melden uns per E-Mail.'));
    }

    // ------------------------------------------------------------------ Konto

    private function member(): array
    {
        return Members::current() ?? throw new HttpException(403);
    }

    public function account(Request $r, array $errors = [], int $status = 200): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden', ['ziel' => Members::BASE . '/konto']), 302);
        return $this->page('account', ['m' => $m, 'errors' => $errors, 'fields' => Profiles::editableFields(), 'vis' => Profiles::visibility($m), 'keys' => Passkeys::list(app()->db, (int) $m['id'], null, Members::PASSKEYS),
            'groups' => Repo::groupNames(), 'passkeys' => Passkeys::available(), 'min' => self::MIN_PASSWORD, 'confirmed' => Members::confirmed(),
            'mail' => Members::settings()['magic'] || \Core\Mailer::ready()], lt('Mein Konto'), $status);
    }

    public function saveAccount(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 303);
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        $in = (array) ($r->post['p'] ?? []);
        $data = [];
        foreach (Profiles::editableFields() as $f) {
            if (array_key_exists($f['name'], $in)) $data[$f['name']] = is_string($in[$f['name']]) ? mb_substr(trim($in[$f['name']]), 0, 4000) : '';
        }
        if (array_key_exists('name', $data) && $data['name'] === '') return $this->account($r, ['p_name' => lt('Bitte Ihren Namen angeben.')], 422);
        // Sichtbarkeit je Feld (vom Mitglied gewählt)
        $vis = Profiles::visibility($m);
        foreach ((array) ($r->post['v'] ?? []) as $field => $level) {
            if (isset($vis[$field]) && in_array($level, Profiles::LEVELS, true)) $vis[(string) $field] = (string) $level;
        }
        [, $errors] = $data ? \Core\Data\Entries::save(Profiles::table(), (int) $m['id'], $data, lt('Profil (Mitglied)')) : [null, []];
        if ($errors) {
            $out = [];
            foreach ($errors as $k => $v) $out['p_' . $k] = is_string($v) ? $v : lt('Bitte prüfen.');
            return $this->account($r, $out, 422);
        }
        Repo::updateMember((int) $m['id'], ['visibility' => json_encode($vis)]);
        \Core\PageCache::clear();   // öffentliche Profile in Listen aktualisieren
        app()->session->flash('success', lt('Profil gespeichert.'));
        return Response::redirect(Members::url('konto'), 303);
    }

    public function savePassword(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 303);
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        if (!Members::confirmed()) return $this->account($r, ['_' => lt('Bitte bestätigen Sie zuerst, dass Sie es sind.')], 403);
        $pw = (string) ($r->post['password'] ?? '');
        if ($err = self::passwordError($pw, (string) ($r->post['password2'] ?? ''), (string) $m['email'])) return $this->account($r, ['password' => $err], 422);
        Repo::updateMember((int) $m['id'], ['password_hash' => password_hash($pw, PASSWORD_DEFAULT)]);
        Repo::bumpAuth((int) $m['id']);   // andere Sitzungen abmelden …
        Members::login(Repo::member((int) $m['id']), $r);   // … diese bleibt
        self::notify($m, lt('Passwort geändert'));
        app()->session->flash('success', lt('Das Passwort ist geändert. Andere Geräte sind abgemeldet.'));
        return Response::redirect(Members::url('konto'), 303);
    }

    private static function notify(array $m, string $what): void
    {
        if ($err = Mail::credentialsChanged($m, $what)) error_log('[members] Hinweis Änderung: ' . $err);
    }

    // ------------------------------------------------------------------ Bestätigung vor Änderungen an den Zugangsdaten

    /** Mit dem aktuellen Passwort bestätigen */
    public function confirmPassword(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 303);
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        if ($this->limited('confirm', (string) $m['id'], 5, 900)) return $this->account($r, ['confirm' => lt('Zu viele Versuche. Bitte warten Sie 15 Minuten.')], 429);
        if (empty($m['password_hash']) || !password_verify((string) ($r->post['current'] ?? ''), (string) $m['password_hash'])) {
            return $this->account($r, ['confirm' => lt('Das Passwort stimmt nicht.')], 422);
        }
        Members::confirm();
        return Response::redirect(Members::url('konto') . '#zugang', 303);
    }

    /** Bestätigungslink per E-Mail */
    public function confirmMail(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 303);
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        if ($this->limited('confirm-mail', (string) $m['id'], 3, 3600)) return $this->account($r, ['confirm' => lt('Zu viele Anfragen. Bitte versuchen Sie es später erneut.')], 429);
        $err = Mail::confirmLink($m);
        if ($err) {
            error_log('[members] Bestätigungslink: ' . $err);
            return $this->account($r, ['confirm' => lt('Die E-Mail konnte nicht gesendet werden. Bitte später erneut versuchen.')], 500);
        }
        app()->session->flash('success', lt('Wir haben Ihnen einen Bestätigungslink an {email} gesendet. Er ist 15 Minuten gültig.', ['email' => $m['email']]));
        return Response::redirect(Members::url('konto'), 303);
    }

    public function confirmLinkPage(Request $r, string $token): Response
    {
        $this->guard($r);
        $t = Repo::findToken($token, 'confirm');
        if (!$t) return $this->message(lt('Link ungültig'), lt('Dieser Bestätigungslink ist abgelaufen oder wurde schon verwendet.'), 410, [[lt('Mein Konto'), Members::url('konto')]]);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden', ['ziel' => Members::BASE . '/bestaetigen/' . $token]), 302);
        if ((int) $m['id'] !== (int) $t['member_id']) return $this->message(lt('Link ungültig'), lt('Dieser Link gehört zu einem anderen Zugang. Bitte melden Sie sich mit dem richtigen Zugang an.'), 403);
        return $this->page('confirm', ['token' => $token], lt('Änderung bestätigen'));
    }

    public function confirmLink(Request $r, string $token): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m || !$this->csrf($r)) return $this->confirmLinkPage($r, $token);
        $t = Repo::findToken($token, 'confirm');
        if (!$t || (int) $t['member_id'] !== (int) $m['id'] || !Repo::useToken((int) $t['id'])) return $this->confirmLinkPage($r, $token);
        Members::confirm();
        app()->session->flash('success', lt('Bestätigt – Sie können Ihre Zugangsdaten jetzt 10 Minuten lang ändern.'));
        return Response::redirect(Members::url('konto') . '#zugang', 303);
    }

    public function passkeyConfirmOptions(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m || !$this->csrf($r) || !Passkeys::available()) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        $creds = app()->db->fetchAll('SELECT credential_id, transports FROM ' . Members::PASSKEYS . ' WHERE user_id = ? AND rp_id = ?', [(int) $m['id'], Passkeys::rpId()]);
        if (!$creds) return $this->json(['error' => lt('Für diesen Zugang ist hier kein Passkey eingerichtet.')], 422);
        return $this->json(Passkeys::requestOptions('member_confirm', $creds, true));
    }

    public function passkeyConfirm(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m || !$this->csrf($r)) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        $in = (array) ($r->post['credential'] ?? []);
        $hash = Passkeys::credentialHash($in);
        $row = $hash ? Passkeys::find(app()->db, $hash, (int) $m['id'], Members::PASSKEYS) : null;
        if ($err = Passkeys::verify(app()->db, 'member_confirm', $in, $row, self::handle((int) $m['id']), false, Members::PASSKEYS)) return $this->json(['error' => $err], 422);
        Members::confirm();
        return $this->json(['ok' => true, 'redirect' => Members::url('konto') . '#zugang']);
    }

    // ------------------------------------------------------------------ Profilfoto

    public function saveAvatar(Request $r): Response
    {
        $this->guard($r);
        $m = Members::current();
        if (!$m) return Response::redirect(Members::url('anmelden'), 303);
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        if (!empty($r->post['remove'])) {
            Avatar::remove($m);
            app()->session->flash('success', lt('Foto entfernt.'));
            return Response::redirect(Members::url('konto'), 303);
        }
        if ($this->limited('avatar', (string) $m['id'], 20, 3600)) return $this->account($r, ['avatar' => lt('Zu viele Anfragen. Bitte versuchen Sie es später erneut.')], 429);
        if ($err = Avatar::store($m, (array) ($r->files['avatar'] ?? []))) return $this->account($r, ['avatar' => $err], 422);
        app()->session->flash('success', lt('Foto gespeichert.'));
        return Response::redirect(Members::url('konto'), 303);
    }

    /** Profilfoto: nur für angemeldete Mitglieder und die Redaktion */
    public function avatar(Request $r, string $id, string $v = ''): Response
    {
        $this->guard($r);
        if (!app()->auth->check() && !Members::current()) throw new HttpException(404);
        $m = Repo::member((int) $id) ?? throw new HttpException(404);
        if (!app()->auth->check() && $m['status'] !== 'active') throw new HttpException(404);
        return Avatar::send($m);
    }

    // ------------------------------------------------------------------ Passkeys

    private static function handle(int $id): string
    {
        return Passkeys::userHandle('member', $id, app()->key());
    }

    private function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->header('Cache-Control', 'no-store');
    }

    public function passkeyLoginOptions(Request $r): Response
    {
        $this->guard($r);
        if (!$this->csrf($r) || !Passkeys::available()) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        return $this->json(Passkeys::requestOptions('member'));
    }

    public function passkeyLogin(Request $r): Response
    {
        $this->guard($r);
        if (!$this->csrf($r)) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        if ($this->limited('pk', $this->ipKey($r), 20, 900)) return $this->json(['error' => lt('Zu viele Anmeldeversuche. Bitte warten Sie 15 Minuten.')], 429);
        $in = (array) ($r->post['credential'] ?? []);
        $db = app()->db;
        $hash = Passkeys::credentialHash($in);
        $row = $hash ? Passkeys::find($db, $hash, null, Members::PASSKEYS) : null;
        $m = $row ? Repo::member((int) $row['user_id']) : null;
        if (!$m || $m['status'] !== 'active') {
            Passkeys::verify($db, 'member', $in, null, '', false, Members::PASSKEYS);   // Challenge verbrauchen
            return $this->json(['error' => lt('Dieser Passkey ist hier nicht eingerichtet.')], 422);
        }
        if ($err = Passkeys::verify($db, 'member', $in, $row, self::handle((int) $m['id']), true, Members::PASSKEYS)) return $this->json(['error' => $err], 422);
        Members::login($m, $r);
        return $this->json(['ok' => true, 'redirect' => Members::safeTarget($r->post['ziel'] ?? '')]);
    }

    /** Wer darf gerade einen Passkey hinzufügen? Angemeldetes Mitglied oder offene Einladung dieser Sitzung (15 Minuten) */
    private function registrant(): ?array
    {
        if ($m = Members::current()) return Members::confirmed() ? [$m, null] : null;
        $inv = app()->session->get('member_invite');
        if (!is_array($inv) || time() - (int) ($inv['at'] ?? 0) > 900) return null;
        $m = Repo::member((int) $inv['id']);
        $t = app()->db->fetch('SELECT * FROM members_tokens WHERE id = ? AND kind = ? AND used_at IS NULL AND expires_at > ?', [(int) $inv['t'], 'invite', now()]);
        return $m && $t && in_array($m['status'], ['invited', 'active'], true) ? [$m, $t] : null;
    }

    public function passkeyAddOptions(Request $r): Response
    {
        $this->guard($r);
        if (!$this->csrf($r) || !Passkeys::available()) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        $who = $this->registrant();
        if (!$who) return $this->json(['error' => lt('Bitte bestätigen Sie zuerst, dass Sie es sind.')], 403);
        $m = $who[0];
        $exclude = array_column(app()->db->fetchAll('SELECT credential_id FROM ' . Members::PASSKEYS . ' WHERE user_id = ?', [(int) $m['id']]), 'credential_id');
        $name = trim($r->str('name')) ?: (string) $m['name'];
        return $this->json(Passkeys::creationOptions(self::handle((int) $m['id']), (string) $m['email'], $name ?: (string) $m['email'], $exclude, [], 'member_reg'));
    }

    public function passkeyAdd(Request $r): Response
    {
        $this->guard($r);
        if (!$this->csrf($r)) return $this->json(['error' => lt('Die Sitzung ist abgelaufen. Bitte neu laden.')], 419);
        $who = $this->registrant();
        if (!$who) return $this->json(['error' => lt('Bitte bestätigen Sie zuerst, dass Sie es sind.')], 403);
        [$m, $invite] = $who;
        if ($invite) {
            $name = trim($r->str('member_name'));
            if ($name === '' || mb_strlen($name) > 120) return $this->json(['error' => lt('Bitte Ihren Namen angeben.')], 422);
        }
        $res = Passkeys::register(app()->db, (int) $m['id'], (array) ($r->post['credential'] ?? []), trim($r->str('label')), Members::PASSKEYS, 'member_reg');
        if (is_string($res)) return $this->json(['error' => $res], 422);
        if ($invite) {
            // Einladung mit Passkey angenommen: Konto aktiv, angemeldet
            Repo::useToken((int) $invite['id']);
            Repo::updateMember((int) $m['id'], ['name' => $name, 'status' => 'active']);
            app()->session->forget('member_invite');
            Members::login(Repo::member((int) $m['id']), $r);
            app()->session->flash('success', lt('Willkommen! Ihr Zugang ist eingerichtet.'));
            return $this->json(['ok' => true, 'redirect' => Members::safeTarget('')]);
        }
        self::notify($m, lt('Passkey „{name}“ hinzugefügt', ['name' => $res['name']]));
        app()->session->flash('success', lt('Passkey „{name}“ eingerichtet.', ['name' => $res['name']]));
        return $this->json(['ok' => true, 'redirect' => Members::url('konto')]);
    }

    public function passkeyDelete(Request $r, string $id): Response
    {
        $this->guard($r);
        $m = $this->member();
        if (!$this->csrf($r)) return $this->account($r, ['_' => lt('Die Sitzung ist abgelaufen. Bitte erneut versuchen.')], 419);
        if (!Members::confirmed()) return $this->account($r, ['_' => lt('Bitte bestätigen Sie zuerst, dass Sie es sind.')], 403);
        // Letzter Zugangsweg? Ohne Passwort bleibt nur der Anmelde-Link – erlaubt, wenn der eingeschaltet ist
        $left = Passkeys::count(app()->db, (int) $m['id'], null, Members::PASSKEYS) - 1;
        if ($left < 1 && empty($m['password_hash']) && !Members::settings()['magic']) {
            return $this->account($r, ['_' => lt('Legen Sie zuerst ein Passwort fest – sonst können Sie sich nicht mehr anmelden.')], 422);
        }
        if (Passkeys::delete(app()->db, (int) $m['id'], (int) $id, Members::PASSKEYS)) {
            Repo::bumpAuth((int) $m['id']);   // andere Sitzungen beenden …
            Members::login(Repo::member((int) $m['id']), $r);   // … diese bleibt
            self::notify($m, lt('Passkey entfernt'));
            app()->session->flash('success', lt('Passkey entfernt.'));
        }
        return Response::redirect(Members::url('konto'), 303);
    }
}
