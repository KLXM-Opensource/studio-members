<?php
// SPDX-License-Identifier: MIT
// KLXM Studio – Mitgliederbereich. Copyright (C) 2026 KLXM and contributors (see LICENSE)
declare(strict_types=1);

namespace Klxm\Members;

use Core\Mailer;

/** E-Mails des Mitgliederbereichs (reiner Text, Sprache der Website): Einladung, Anmelde-Link, Antrag, Freischaltung */
final class Mail
{
    private static function link(string $sub, array $q = []): string
    {
        return abs_url(Members::url($sub, $q));
    }

    /** Einladung (auch nach Freischaltung eines Antrags): Link zum Festlegen von Passwort bzw. Passkey */
    public static function invite(array $m): ?string
    {
        return Members::inLang($m['lang'] ?? null, function () use ($m): ?string {
            $token = Repo::token((int) $m['id'], 'invite', Members::INVITE_TTL);
            Repo::updateMember((int) $m['id'], ['invited_at' => now()]);
            $site = site_name();
            $text = lt('Guten Tag {name},', ['name' => $m['name'] ?: $m['email']]) . "\n\n"
                . lt('Sie haben Zugang zum Mitgliederbereich von {site}. Über diesen Link legen Sie Ihr Passwort fest oder richten einen Passkey ein (gültig 14 Tage):', ['site' => $site]) . "\n\n"
                . self::link('einladung/' . $token) . "\n\n"
                . lt('Danach melden Sie sich hier an: {url}', ['url' => self::link('anmelden')]) . "\n\n"
                . lt('Wenn Sie diese E-Mail nicht erwartet haben, können Sie sie ignorieren.');
            return Mailer::send(lt('Ihr Zugang zum Mitgliederbereich – {site}', ['site' => $site]), $text, [(string) $m['email']]);
        });
    }

    /** Anmelde-Link (ohne Passwort), 15 Minuten gültig, einmal verwendbar */
    public static function loginLink(array $m, string $target): ?string
    {
        return Members::inLang($m['lang'] ?? null, function () use ($m, $target): ?string {
            $token = Repo::token((int) $m['id'], 'link', Members::LINK_TTL);
            $text = lt('Guten Tag {name},', ['name' => $m['name'] ?: $m['email']]) . "\n\n"
                . lt('mit diesem Link melden Sie sich im Mitgliederbereich von {site} an (15 Minuten gültig, nur einmal verwendbar):', ['site' => site_name()]) . "\n\n"
                . self::link('link/' . $token, ['ziel' => $target]) . "\n\n"
                . lt('Sie haben keinen Link angefordert? Dann können Sie diese E-Mail ignorieren – ohne den Link meldet sich niemand an.');
            return Mailer::send(lt('Anmelde-Link – {site}', ['site' => site_name()]), $text, [(string) $m['email']]);
        });
    }

    /** Bestätigung für Änderungen an den Zugangsdaten (15 Minuten, einmal verwendbar) */
    public static function confirmLink(array $m): ?string
    {
        return Members::inLang($m['lang'] ?? null, function () use ($m): ?string {
            $token = Repo::token((int) $m['id'], 'confirm', Members::LINK_TTL);
            $text = lt('Guten Tag {name},', ['name' => $m['name'] ?: $m['email']]) . "\n\n"
                . lt('Sie möchten Passwort oder Passkeys Ihres Zugangs bei {site} ändern. Bitte bestätigen Sie das mit diesem Link (15 Minuten gültig, nur einmal verwendbar):', ['site' => site_name()]) . "\n\n"
                . self::link('bestaetigen/' . $token) . "\n\n"
                . lt('Das waren nicht Sie? Dann ignorieren Sie diese E-Mail und ändern Sie zur Sicherheit Ihr Passwort.');
            return Mailer::send(lt('Änderung bestätigen – {site}', ['site' => site_name()]), $text, [(string) $m['email']]);
        });
    }

    /** Hinweis nach jeder Änderung an den Zugangsdaten */
    public static function credentialsChanged(array $m, string $what): ?string
    {
        return Members::inLang($m['lang'] ?? null, function () use ($m, $what): ?string {
            $text = lt('Guten Tag {name},', ['name' => $m['name'] ?: $m['email']]) . "\n\n"
                . lt('an Ihrem Zugang bei {site} wurde gerade etwas geändert: {what} ({date}).', ['site' => site_name(), 'what' => $what, 'date' => fmt()->datetime(time())]) . "\n\n"
                . lt('Das waren nicht Sie? Dann wenden Sie sich bitte sofort an die Betreiber der Website.');
            return Mailer::send(lt('Ihre Zugangsdaten wurden geändert – {site}', ['site' => site_name()]), $text, [(string) $m['email']]);
        });
    }

    /** Neuer Antrag → Adresse aus den Einstellungen (sonst Empfänger der Website) */
    public static function applicationNotice(array $m): ?string
    {
        $to = array_filter(array_map('trim', explode(',', Members::settings()['notify'])), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        $text = __('Neuer Antrag auf Zugang zum Mitgliederbereich:') . "\n\n"
            . __('Name: {name}', ['name' => $m['name']]) . "\n" . __('E-Mail: {email}', ['email' => $m['email']]) . "\n"
            . ((string) ($m['application'] ?? '') !== '' ? "\n" . $m['application'] . "\n" : '')
            . "\n" . __('Prüfen und freischalten: {url}', ['url' => abs_url(url('/admin/mitglieder') . '?status=pending')]);
        return Mailer::send(__('Antrag Mitgliederbereich: {name}', ['name' => $m['name'] ?: $m['email']]), $text, $to ?: null, ['reply_to' => (string) $m['email']]);
    }

    /** Antrag abgelehnt (optional, nur wenn die Redaktion es so wählt) */
    public static function declined(array $m): ?string
    {
        return Members::inLang($m['lang'] ?? null, function () use ($m): ?string {
            $text = lt('Guten Tag {name},', ['name' => $m['name'] ?: $m['email']]) . "\n\n"
                . lt('Ihr Antrag auf Zugang zum Mitgliederbereich von {site} wurde leider nicht freigegeben. Bei Fragen antworten Sie einfach auf diese E-Mail.', ['site' => site_name()]);
            return Mailer::send(lt('Ihr Antrag – {site}', ['site' => site_name()]), $text, [(string) $m['email']]);
        });
    }
}
