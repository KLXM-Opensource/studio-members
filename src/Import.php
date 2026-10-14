<?php
// SPDX-License-Identifier: MIT
declare(strict_types=1);

namespace Klxm\Members;

use Core\Data\Entries;
use Core\Data\Tables;

/**
 * Mitglieder aus einer CSV-Datei übernehmen (Verwaltung → Mitglieder → Importieren): Vorlage, Vorschau, Ausführen.
 * Spalten (Kopfzeile, Groß-/Kleinschreibung egal): E-Mail (Pflicht), Name – oder Vorname + Nachname –, Gruppen (mit Komma oder |
 * getrennt) und beliebige Profilfelder (Bezeichnung oder Kurzname wie unter Daten → Mitglieder). Trennzeichen ; , oder Tab,
 * UTF-8 oder Windows-1252 (Excel). Neue Mitglieder entstehen ohne Zugang; eingeladen wird optional (höchstens INVITE_BATCH je Schritt).
 */
final class Import
{
    public const MAX_ROWS = 2000;
    public const MAX_BYTES = 2_000_000;
    private const EMAIL = ['e-mail', 'email', 'e-mail-adresse', 'emailadresse', 'mail'];
    private const NAME = ['name', 'vollständiger name', 'vollstaendiger name', 'anzeigename'];
    private const FIRST = ['vorname', 'first name', 'firstname'];
    private const LAST = ['nachname', 'last name', 'lastname', 'familienname'];
    private const GROUPS = ['gruppen', 'gruppe', 'groups', 'group'];

    /** Profilfelder, die der Import füllen darf (ohne E-Mail und Name) */
    public static function fields(): array
    {
        return array_values(array_filter(Profiles::table()['fields'], fn($f) => in_array($f['type'], Profiles::EDITABLE_TYPES, true)
            && !in_array($f['name'], ['email', 'name'], true)));
    }

    /** Vorlage (Semikolon, UTF-8 mit BOM – öffnet in Excel korrekt) */
    public static function template(): string
    {
        $head = ['E-Mail', 'Name', 'Gruppen', ...array_map(fn($f) => Tables::label($f), self::fields())];
        $ex = ['erika@example.org', 'Erika Musterfrau', implode(', ', array_slice(array_values(Repo::groupNames()), 0, 1)) ?: 'Vorstand', ...array_fill(0, count(self::fields()), '')];
        $line = fn(array $r) => implode(';', array_map(fn($v) => preg_match('~[;"\n]~', (string) $v) ? '"' . str_replace('"', '""', (string) $v) . '"' : (string) $v, $r));
        return "\xEF\xBB\xBF" . $line($head) . "\r\n" . $line($ex) . "\r\n";
    }

    /**
     * CSV lesen und prüfen. @return array{rows: list<array{line:int, email:string, name:string, groups:list<string>, fields:array, status:string, note:string}>,
     *   columns: array<string,string>, unknown: list<string>, error: ?string}
     * status: new | update | invalid | duplicate
     */
    public static function parse(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES) return ['rows' => [], 'columns' => [], 'unknown' => [], 'error' => __('Die Datei ist zu groß (höchstens 2 MB).')];
        if (str_starts_with($csv, "\xEF\xBB\xBF")) $csv = substr($csv, 3);
        if (!mb_check_encoding($csv, 'UTF-8')) $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1252');
        $first = strtok($csv, "\n") ?: '';
        $delim = array_search(max($c = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")]), $c, true) ?: ';';
        $h = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $head = fgetcsv($h, 0, $delim, '"', '');
        if (!$head) return ['rows' => [], 'columns' => [], 'unknown' => [], 'error' => __('Die Datei ist leer.')];
        // Spalten zuordnen
        $norm = fn(string $s) => mb_strtolower(trim(preg_replace('~\s+~u', ' ', $s)));
        $byField = [];
        foreach (self::fields() as $f) { $byField[$norm(Tables::label($f))] = $f['name']; $byField[$norm($f['name'])] = $f['name']; }
        $map = [];
        $unknown = [];
        foreach ($head as $i => $col) {
            $n = $norm((string) $col);
            $key = match (true) {
                in_array($n, self::EMAIL, true) => 'email', in_array($n, self::NAME, true) => 'name',
                in_array($n, self::FIRST, true) => 'first', in_array($n, self::LAST, true) => 'last',
                in_array($n, self::GROUPS, true) => 'groups', isset($byField[$n]) => 'f:' . $byField[$n],
                default => null,
            };
            if ($key === null) { if ($n !== '') $unknown[] = (string) $col; continue; }
            $map[$i] = $key;
        }
        if (!in_array('email', $map, true)) {
            fclose($h);
            return ['rows' => [], 'columns' => [], 'unknown' => $unknown, 'error' => __('Keine Spalte „E-Mail“ gefunden – bitte die Vorlage verwenden.')];
        }
        $rows = [];
        $seen = [];
        $line = 1;
        while (($r = fgetcsv($h, 0, $delim, '"', '')) !== false) {
            $line++;
            if ($r === [null] || !array_filter($r, fn($v) => trim((string) $v) !== '')) continue;
            if (count($rows) >= self::MAX_ROWS) { fclose($h); return ['rows' => $rows, 'columns' => self::columns($map, $head), 'unknown' => $unknown,
                'error' => __('Höchstens {n} Zeilen je Datei – bitte aufteilen.', ['n' => self::MAX_ROWS])]; }
            $v = ['email' => '', 'name' => '', 'first' => '', 'last' => '', 'groups' => ''];
            $fields = [];
            foreach ($map as $i => $k) {
                $val = trim((string) ($r[$i] ?? ''));
                if (str_starts_with($k, 'f:')) { if ($val !== '') $fields[substr($k, 2)] = mb_substr($val, 0, 5000); }
                else $v[$k] = $val;
            }
            $email = mb_strtolower($v['email']);
            $name = $v['name'] !== '' ? $v['name'] : trim($v['first'] . ' ' . $v['last']);
            $groups = array_values(array_filter(array_map('trim', preg_split('~[,|]~', $v['groups']) ?: [])));
            $status = !filter_var($email, FILTER_VALIDATE_EMAIL) ? 'invalid' : (isset($seen[$email]) ? 'duplicate' : (Profiles::byEmail($email) ? 'update' : 'new'));
            if ($status !== 'invalid') $seen[$email] = true;
            $rows[] = ['line' => $line, 'email' => $email, 'name' => mb_substr($name, 0, 190), 'groups' => $groups, 'fields' => $fields, 'status' => $status,
                'note' => $status === 'invalid' ? __('ungültige E-Mail-Adresse') : ($status === 'duplicate' ? __('doppelt in der Datei') : '')];
        }
        fclose($h);
        return ['rows' => $rows, 'columns' => self::columns($map, $head), 'unknown' => $unknown, 'error' => null];
    }

    /** Erkannte Spalten: Überschrift in der Datei → Bedeutung */
    private static function columns(array $map, array $head): array
    {
        $labels = ['email' => __('E-Mail'), 'name' => __('Name'), 'first' => __('Vorname'), 'last' => __('Nachname'), 'groups' => __('Gruppen')];
        $out = [];
        foreach ($map as $i => $k) {
            $f = str_starts_with($k, 'f:') ? Tables::field(Profiles::table(), substr($k, 2)) : null;
            $out[(string) $head[$i]] = $f ? Tables::label($f) : ($labels[$k] ?? $k);
        }
        return $out;
    }

    /**
     * Übernehmen. $o: update (vorhandene ergänzen statt überspringen), create_groups (unbekannte Gruppen anlegen), invite (neue gleich einladen).
     * @return array{created:int, updated:int, skipped:int, failed:list<string>, invited:int, invite_failed:list<string>, new_groups:list<string>}
     */
    public static function apply(array $rows, array $o): array
    {
        $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => [], 'invited' => 0, 'invite_failed' => [], 'new_groups' => []];
        $groupIds = [];
        foreach (Repo::groupNames() as $gid => $gname) $groupIds[mb_strtolower($gname)] = (int) $gid;
        $resolve = function (array $names) use (&$groupIds, &$res, $o): array {
            $ids = [];
            foreach ($names as $n) {
                $k = mb_strtolower($n);
                if (!isset($groupIds[$k]) && !empty($o['create_groups'])) { $groupIds[$k] = Repo::saveGroup(0, mb_substr($n, 0, 120), ''); $res['new_groups'][] = $n; }
                if (isset($groupIds[$k])) $ids[] = $groupIds[$k];
            }
            return $ids;
        };
        $new = [];
        foreach ($rows as $r) {
            if (!in_array($r['status'], ['new', 'update'], true)) { $res['skipped']++; continue; }
            $p = Profiles::byEmail($r['email']);
            if ($p && empty($o['update'])) { $res['skipped']++; continue; }
            if ($p) {
                $id = (int) $p['id'];
                $data = $r['fields'] + ($r['name'] !== '' && $r['name'] !== (string) $p['name'] ? ['name' => $r['name']] : []);
                if ($data) {
                    [, $err] = Entries::save(Profiles::table(), $id, $data, __('Mitglieder-Import'));
                    if ($err) { $res['failed'][] = $r['email'] . ' (' . implode(' ', $err) . ')'; continue; }
                }
                if ($r['groups']) Repo::setGroups($id, array_merge(Repo::memberGroups($id), $resolve($r['groups'])));   // ergänzen, nichts wegnehmen
                $res['updated']++;
                continue;
            }
            $id = Profiles::create($r['email'], $r['name']);
            if (is_array($id)) { $res['failed'][] = $r['email'] . ' (' . implode(' ', $id) . ')'; continue; }
            if ($r['fields']) Entries::save(Profiles::table(), $id, $r['fields'], __('Mitglieder-Import'));
            if ($r['groups']) Repo::setGroups($id, $resolve($r['groups']));
            $new[] = $id;
            $res['created']++;
        }
        if (!empty($o['invite']) && $new && \Core\Mailer::ready()) {
            foreach (array_slice($new, 0, AdminController::INVITE_BATCH) as $id) {
                Repo::ensureAccess($id, 'invited');
                $m = Repo::member($id);
                if ($err = Mail::invite($m)) $res['invite_failed'][] = $m['email'] . ' (' . $err . ')';
                else $res['invited']++;
            }
        }
        return $res;
    }
}
