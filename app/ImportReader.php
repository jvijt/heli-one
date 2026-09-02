<?php
declare(strict_types=1);

final class ImportReader
{
    public static function read(string $path, string $originalName): array
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return match ($ext) {
            'xlsx' => self::readXlsx($path),
            'csv' => self::readCsv($path),
            default => throw new RuntimeException('Gebruik een .xlsx- of .csv-bestand.'),
        };
    }

    private static function readCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('CSV-bestand kon niet worden geopend.');
        $first = fgets($fh);
        if ($first === false) return [];
        $delims = [',',';','\t'];
        $best = ';'; $bestCount = -1;
        foreach ($delims as $d) {
            $delimiter = $d === '\t' ? "\t" : $d;
            $count = count(str_getcsv($first, $delimiter));
            if ($count > $bestCount) { $bestCount = $count; $best = $delimiter; }
        }
        rewind($fh);
        $rows = [];
        while (($row = fgetcsv($fh, 0, $best)) !== false) {
            $rows[] = array_map(static fn($v) => trim((string)$v), $row);
        }
        fclose($fh);
        return self::normalizeRows($rows);
    }

    private static function readXlsx(string $path): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('De PHP Zip-extensie ontbreekt op de server; XLSX kan niet worden gelezen.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Excelbestand kon niet worden geopend.');
        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);
            if ($xml) foreach ($xml->si as $si) {
                if (isset($si->t)) $shared[] = (string)$si->t;
                else { $text=''; foreach ($si->r as $run) $text .= (string)$run->t; $shared[]=$text; }
            }
        }
        $sheetPath='xl/worksheets/sheet1.xml';
        $workbookXml=$zip->getFromName('xl/workbook.xml');
        $relsXml=$zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml!==false && $relsXml!==false) {
            $wb=simplexml_load_string($workbookXml); $rels=simplexml_load_string($relsXml);
            if ($wb && $rels) {
                $sheets=$wb->sheets->sheet ?? [];
                if (isset($sheets[0])) {
                    $attrs=$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $rid=(string)($attrs['id'] ?? '');
                    foreach ($rels->Relationship as $rel) if ((string)$rel['Id']===$rid) {
                        $target=(string)$rel['Target'];
                        $sheetPath=str_starts_with($target,'/') ? ltrim($target,'/') : 'xl/'.ltrim($target,'/');
                        $sheetPath=str_replace('xl/xl/','xl/',$sheetPath); break;
                    }
                }
            }
        }
        $sheetXml=$zip->getFromName($sheetPath); $zip->close();
        if ($sheetXml===false) throw new RuntimeException('Eerste werkblad kon niet worden gelezen.');
        $xml=simplexml_load_string($sheetXml);
        if (!$xml) throw new RuntimeException('Excel-XML is ongeldig.');
        $rows=[];
        foreach ($xml->sheetData->row as $row) {
            $values=[];
            foreach ($row->c as $cell) {
                $ref=(string)$cell['r']; preg_match('/^[A-Z]+/',$ref,$m); $col=self::columnIndex($m[0]??'A');
                $type=(string)$cell['t']; $value='';
                if ($type==='s') { $idx=(int)$cell->v; $value=$shared[$idx]??''; }
                elseif ($type==='inlineStr') $value=(string)$cell->is->t;
                else $value=(string)$cell->v;
                $values[$col]=trim($value);
            }
            if ($values) { $max=max(array_keys($values)); $dense=[]; for($i=0;$i<=$max;$i++) $dense[]=$values[$i]??''; $rows[]=$dense; }
        }
        return self::normalizeRows($rows);
    }

    private static function columnIndex(string $letters): int
    {
        $n=0; foreach(str_split($letters) as $ch) $n=$n*26+(ord($ch)-64); return max(0,$n-1);
    }

    private static function normalizeRows(array $rows): array
    {
        return array_values(array_filter($rows, static function(array $row): bool { foreach($row as $v) if(trim((string)$v)!=='') return true; return false; }));
    }
}
