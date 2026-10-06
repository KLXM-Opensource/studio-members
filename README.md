# KLXM Studio – Mitgliederbereich (`members`)

Geschützte Bereiche für Websites mit [KLXM Studio](https://studio.klxm.de): Seiten, Datentabellen und Medien nur für angemeldete
Mitglieder – mit Anmeldung per Passwort, **Passkey** oder Anmelde-Link, Einladungen, Anträgen, Gruppen und Profilen.

## Installation

```bash
composer require klxm/studio-members        # oder Repository nach extensions/members klonen
php bin/console extensions:publish
```

Aktivieren je Website unter **Administration → Funktionen & Erweiterungen** oder `'extensions' => ['members']` in
`config/sites/{key}.php`. Benötigt KLXM Studio ≥ 1.0.0 mit `Core\PageAccess` und geschützten Medien-Pools.

## Funktionen

| Bereich | Was |
|---|---|
| Geschützte Seiten | Verwaltung → Mitglieder → Geschützte Seiten: Seite im Struktur-Browser wählen, gilt für die Seite und ihre Unterseiten oder nur für die Seite; freigegeben für alle Mitglieder oder Gruppen. Auch in den Seiteneinstellungen (Karte „Mitgliederbereich“) und im Seitenbaum (Schloss). |
| Geschützte Datentabellen | Detailseiten verlangen die Anmeldung; auf öffentlichen Seiten bleiben Listen, Kalender und Feeds der Tabelle leer. |
| Anmeldung | `/mitglieder/anmelden`: Passkey, Passwort oder Anmelde-Link (15 Min., einmal). Sitzung getrennt von der Verwaltung (Mitglieder kommen nie in `/admin`). |
| Einladungen & Anträge | Redaktion lädt ein (Link 14 Tage); optional „Zugang beantragen“ (`/mitglieder/zugang`) mit Freischaltung durch die Redaktion. |
| Gruppen | z. B. Vorstand, Presse – für Seiten und Tabellen. |
| Profile | Datentabelle „Mitglieder“ (Name, E-Mail, Kurze Vita, optional Website, LinkedIn, Instagram, Facebook, Mastodon, Bluesky + eigene Felder) – eine Datenbasis, Import/Export unter Daten. Mitglieder pflegen ihr Profil unter „Mein Konto“ und wählen je Feld: **öffentlich**, **nur Mitglieder**, **nur Redaktion**. Zugangsdaten (Status, Passwort-Hash, Passkeys, Tokens) liegen getrennt und nie in Export, API, MCP oder Listen. |
| Profilfoto | quadratisch, ohne Metadaten, nur für Angemeldete sichtbar. |
| Zugangsdaten ändern | Passwort/Passkeys erst nach Bestätigung (Passwort, Passkey oder E-Mail-Link, 10 Min.); Hinweis-Mail nach jeder Änderung, andere Geräte werden abgemeldet. |
| Geschützte Medien | Geschützter Pool der Mediathek (Grundeinstellungen → Geteilte Medien → „Geschützt“): Dateien nur für angemeldete Mitglieder. |
| Mehrsprachig | Sprache per `?lang=`, Texte der Einstellungen je Sprache, E-Mails in der Sprache des Mitglieds. |

## Sicherheit

- Passwörter mit `password_hash`, konstante Laufzeit bei unbekannter Adresse, Mengenbegrenzung je IP und Adresse
- Einmal-Links nur als SHA-256 gespeichert, mit Ablauf; GET-Aufrufe aus E-Mails verbrauchen nichts (Mail-Scanner)
- CSRF-Token an allen POSTs, `Cache-Control: private, no-store` für alle Seiten des Bereichs
- Passkeys (WebAuthn L2) über `Core\Passkeys` mit eigener Tabelle `members_passkeys`
- Selbsttest: `php bin/console members:selftest`

## Lizenz

MIT – siehe [LICENSE](LICENSE).
