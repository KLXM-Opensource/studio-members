# Changelog

## 0.1.3 – 2026-10-09

- Warnung, wenn eine geschützte Datentabelle auf öffentlichen Seiten eingebunden ist (Besucher sehen dort eine leere Liste):
  in Mitglieder → Geschützte Seiten bei der Tabelle und als Hinweis in `php bin/console health` bzw. der Übersicht.
  Typisch nach einem Wechsel der Profiltabelle (`members.table`), wenn der Schutz an der alten Tabelle hängen bleibt.

## 0.1.2 – 2026-10-08

- Selbsttest: Prüfung „nie in user_passkeys“ vergleicht die Kern-Tabelle vor und nach dem Test statt die Mitglieds-ID (Fehlalarm, wenn ein Verwaltungskonto mit gleicher Nummer einen Passkey hat).

## 0.1.1 (2026-10-06)

- Akzent und Rundung aus dem Kit-Vertrag des Kerns (`--kit-accent`, `--kit-radius`): Anmeldung, Konto und Profil passen zu
  jedem Kit (bisher nur zu fluid-artigen Kits, sonst Blau); ältere Kits über ihre Variablen bzw. Blau als Rückfall.

## 0.1.0 (2026-10-06)

- Geschützte Seiten (diese Seite und Unterseiten oder nur diese Seite) und Datentabellen, freigegeben für alle Mitglieder oder Gruppen
- Anmeldung mit Passwort, Passkey oder Anmelde-Link per E-Mail; Einladungen (14 Tage) und Anträge mit Freischaltung
- Mitgliederprofile als Datentabelle „Mitglieder“ (eine Datenbasis); Mitglieder pflegen ihr Profil und wählen je Feld:
  öffentlich, nur Mitglieder, nur Redaktion; optionale Links (Website, LinkedIn, Instagram, Facebook, Mastodon, Bluesky)
- Profilfoto (zugeschnitten, ohne Metadaten, nur für Angemeldete)
- Änderungen an Passwort und Passkeys erst nach Bestätigung (Passwort, Passkey oder E-Mail-Link), Hinweis-Mail nach jeder Änderung
- Geschützte Pools der Mediathek: Dateien nur für angemeldete Mitglieder
- Mehrsprachig (?lang=, Texte je Sprache, E-Mails in der Sprache des Mitglieds); Deutsch und Englisch
