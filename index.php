

<?php
// Код можно запустить в своем локалхосте или просто открыть ссылку https://bitrix24-moderation-report.onrender.com/

declare(strict_types=1);
session_start();

const REPORT_TZ = 'Europe/Moscow';
const REPORT_START = '2026-09-01 00:00:00';
const REPORT_END = '2026-10-01 00:00:00';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function decodeJsonFile(string $contents, string $label): array {
    try {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new RuntimeException("$label: некорректный JSON (" . $e->getMessage() . ").");
    }
    if (!is_array($data)) {
        throw new RuntimeException("$label: ожидался JSON-объект.");
    }
    // REST *.list format: {"result":[...], "next":..., "total":...}
    if (!isset($data['result']) || !is_array($data['result'])) {
        throw new RuntimeException("$label: не найден массив result, ожидаемый в ответе REST API.");
    }
    return $data['result'];
}

function parseDate(?string $value): ?DateTimeImmutable {
    if ($value === null || trim($value) === '') {
        return null;
    }
    try {
        // ISO-8601 offset is respected; later comparisons use Moscow time.
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone(REPORT_TZ));
    } catch (Exception $e) {
        return null;
    }
}

function normalizeReasonKey(string $reason): string {
    // Unicode lowercase for Russian text without requiring the mbstring extension.
    $cyrillic = [
        'А'=>'а','Б'=>'б','В'=>'в','Г'=>'г','Д'=>'д','Е'=>'е','Ё'=>'ё','Ж'=>'ж','З'=>'з',
        'И'=>'и','Й'=>'й','К'=>'к','Л'=>'л','М'=>'м','Н'=>'н','О'=>'о','П'=>'п','Р'=>'р',
        'С'=>'с','Т'=>'т','У'=>'у','Ф'=>'ф','Х'=>'х','Ц'=>'ц','Ч'=>'ч','Ш'=>'ш','Щ'=>'щ',
        'Ъ'=>'ъ','Ы'=>'ы','Ь'=>'ь','Э'=>'э','Ю'=>'ю','Я'=>'я'
    ];
    return strtolower(strtr($reason, $cyrillic));
}

function canonicalReturnReason(string $reason): array {
    $reason = preg_replace('/\s+/u', ' ', trim($reason)) ?? trim($reason);
    if ($reason === '') {
        return ['key' => '__missing__', 'label' => 'Причина не указана'];
    }

    $key = normalizeReasonKey($reason);
    // These source phrases describe the same issue: the application icon is missing.
    $iconAliases = [
        'нет иконки',
        'нет иконки приложения',
        'отсутствует иконка приложения',
    ];
    if (in_array($key, $iconAliases, true)) {
        return ['key' => 'нет иконки приложения', 'label' => 'Нет иконки приложения'];
    }

    return ['key' => $key, 'label' => $reason];
}

function median(array $values): ?float {
    if (!$values) return null;
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = intdiv($n, 2);
    return ($n % 2) ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

function makeReport(array $pageContents, string $usersContents): array {
    $warnings = [];
    $allApplications = [];
    $sourceCount = 0;

    foreach ($pageContents as $i => $contents) {
        $rows = decodeJsonFile($contents, 'Страница заявок ' . ($i + 1));
        $sourceCount += count($rows);
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['ID'])) {
                $warnings[] = 'В одной из страниц есть запись без ID; запись пропущена.';
                continue;
            }
            $id = (string)$row['ID'];
            if (isset($allApplications[$id])) {
                if ($allApplications[$id] === $row) {
                    $warnings[] = "Заявка $id встречается повторно и учтена один раз.";
                    continue;
                }
                $warnings[] = "У заявки $id найдены разные записи; использована первая запись.";
                continue;
            }
            $allApplications[$id] = $row;
        }
    }

    $users = decodeJsonFile($usersContents, 'Файл пользователей');
    $names = [];
    foreach ($users as $user) {
        if (!is_array($user) || !isset($user['ID'])) continue;
        $names[(string)$user['ID']] = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
    }

    $stats = [];
    $getStats = function(string $moderatorId) use (&$stats, &$names): array {
        if (!isset($stats[$moderatorId])) {
            $name = $names[$moderatorId] ?? '';
            if ($name === '') {
                $name = "Неизвестный (ID $moderatorId)";
            }
            $stats[$moderatorId] = [
                'moderator_id' => $moderatorId,
                'name' => $name,
                'closed' => 0,
                'returned' => 0,
                'durations' => [],
                'in_progress' => 0,
                'return_reasons' => [],
            ];
        }
        return $stats[$moderatorId];
    };

    // Include all listed moderators, even if they have no matching applications.
    foreach ($names as $id => $_name) $getStats((string)$id);

    $start = new DateTimeImmutable(REPORT_START, new DateTimeZone(REPORT_TZ));
    $end = new DateTimeImmutable(REPORT_END, new DateTimeZone(REPORT_TZ));

    foreach ($allApplications as $row) {
        $moderatorId = (string)($row['MODERATOR_ID'] ?? '');
        if ($moderatorId === '') {
            $warnings[] = 'Заявка ' . (string)($row['ID'] ?? '?') . ' не содержит MODERATOR_ID и пропущена.';
            continue;
        }
        $status = (string)($row['STATUS'] ?? '');
        $s = &$stats[$moderatorId];
        if (!isset($s)) {
            $getStats($moderatorId);
            $s = &$stats[$moderatorId];
        }

        if ($status === 'in_progress') {
            $s['in_progress']++;
        }

        if (!in_array($status, ['approved', 'returned'], true)) {
            unset($s);
            continue;
        }

        $closed = parseDate(isset($row['CLOSED']) ? (string)$row['CLOSED'] : null);
        if ($closed === null) {
            $warnings[] = 'У закрытой заявки ' . (string)($row['ID'] ?? '?') . ' отсутствует или некорректна дата CLOSED.';
            unset($s);
            continue;
        }
        if ($closed < $start || $closed >= $end) {
            unset($s);
            continue;
        }

        $s['closed']++;
        if ($status === 'returned') {
            $s['returned']++;
            $canonicalReason = canonicalReturnReason((string)($row['RETURN_REASON'] ?? ''));
            $reasonKey = $canonicalReason['key'];
            $reason = $canonicalReason['label'];
            if (!isset($s['return_reasons'][$reasonKey])) {
                $s['return_reasons'][$reasonKey] = ['reason' => $reason, 'count' => 0];
            }
            $s['return_reasons'][$reasonKey]['count']++;
        }

        $created = parseDate(isset($row['CREATED']) ? (string)$row['CREATED'] : null);
        if ($created === null) {
            $warnings[] = 'У заявки ' . (string)($row['ID'] ?? '?') . ' отсутствует или некорректна дата CREATED; длительность не рассчитана.';
        } else {
            $seconds = $closed->getTimestamp() - $created->getTimestamp();
            if ($seconds < 0) {
                $warnings[] = 'У заявки ' . (string)($row['ID'] ?? '?') . ' CLOSED раньше CREATED; длительность исключена из медианы.';
            } else {
                $s['durations'][] = $seconds / 3600;
            }
        }
        unset($s);
    }

    foreach ($stats as &$s) {
        $closed = $s['closed'];
        $s['return_share'] = $closed > 0 ? $s['returned'] / $closed : null;
        $s['median_hours'] = median($s['durations']);
        $reasonRows = array_values($s['return_reasons']);
        usort($reasonRows, static function(array $a, array $b): int {
            $byCount = $b['count'] <=> $a['count'];
            if ($byCount !== 0) return $byCount;
            return strcasecmp($a['reason'], $b['reason']);
        });
        $s['top_return_reasons'] = array_slice($reasonRows, 0, 3);
        unset($s['durations'], $s['return_reasons']);
    }
    unset($s);

    usort($stats, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
    return [
        'rows' => array_values($stats),
        'warnings' => array_values(array_unique($warnings)),
        'source_count' => $sourceCount,
        'unique_count' => count($allApplications),
    ];
}

function csvForReport(array $rows): string {
    $stream = fopen('php://temp', 'r+');
    // UTF-8 BOM helps Excel recognize Cyrillic text.
    fwrite($stream, "\xEF\xBB\xBF");
    fputcsv($stream, ['ID модератора', 'Модератор', 'Закрыто за сентябрь', 'Доля возвратов', 'Медиана обработки, часы', 'В работе', 'Причина возврата №1', 'Количество №1', 'Причина возврата №2', 'Количество №2', 'Причина возврата №3', 'Количество №3']);
    foreach ($rows as $row) {
        $csvRow = [
            $row['moderator_id'],
            $row['name'],
            $row['closed'],
            $row['return_share'] === null ? '' : number_format($row['return_share'] * 100, 2, '.', '') . '%',
            $row['median_hours'] === null ? '' : number_format($row['median_hours'], 2, '.', ''),
            $row['in_progress'],
        ];
        for ($i = 0; $i < 3; $i++) {
            $reason = $row['top_return_reasons'][$i] ?? null;
            $csvRow[] = $reason['reason'] ?? '';
            $csvRow[] = $reason['count'] ?? '';
        }
        fputcsv($stream, $csvRow);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    return $csv;
}

$error = null;
$report = $_SESSION['report'] ?? null;
if (isset($_GET['download']) && isset($_SESSION['report']['rows'])) {
    $csv = csvForReport($_SESSION['report']['rows']);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="moderation-report-september-2026.csv"');
    echo $csv;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pageContents = [];
        $usersContents = '';

        if (($_POST['source'] ?? '') === 'sample') {
            foreach ([1, 2, 3] as $page) {
                $path = __DIR__ . "/data/applications_page_$page.json";
                if (!is_file($path)) throw new RuntimeException("Не найден файл $path");
                $pageContents[] = file_get_contents($path);
            }
            $usersPath = __DIR__ . '/data/users.json';
            if (!is_file($usersPath)) throw new RuntimeException('Не найден data/users.json');
            $usersContents = file_get_contents($usersPath);
        } else {
            $files = $_FILES['applications'] ?? null;
            if (!$files || !isset($files['name']) || count(array_filter($files['name'], fn($n) => $n !== '')) !== 3) {
                throw new RuntimeException('Выберите ровно три JSON-файла со страницами заявок.');
            }
            foreach ($files['tmp_name'] as $i => $tmp) {
                if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
                    throw new RuntimeException('Не удалось загрузить один из файлов заявок.');
                }
                $pageContents[] = file_get_contents($tmp);
            }
            if (!isset($_FILES['users']) || $_FILES['users']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['users']['tmp_name'])) {
                throw new RuntimeException('Выберите файл users.json.');
            }
            $usersContents = file_get_contents($_FILES['users']['tmp_name']);
        }

        $report = makeReport($pageContents, $usersContents);
        $_SESSION['report'] = $report;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Отчёт по модерации — сентябрь 2026</title>
<style>
:root{color-scheme:light;--ink:#172033;--muted:#64748b;--line:#e2e8f0;--accent:#2457d6;--bg:#f5f7fb}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1120px;margin:38px auto;padding:0 20px}.header{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:24px}
h1{font-size:28px;line-height:1.2;margin:0 0 8px}.muted{color:var(--muted)}.period{white-space:nowrap;border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 12px;font-size:13px}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;padding:22px;margin:18px 0;box-shadow:0 4px 16px #0f172a08}
h2{font-size:18px;margin:0 0 14px}.help{font-size:13px;color:var(--muted);margin:5px 0 15px}
.fields{display:grid;grid-template-columns:1fr 1fr;gap:16px}.field{border:1px dashed #cbd5e1;border-radius:12px;padding:14px}.field label{display:block;font-weight:600;margin-bottom:8px}.field input{width:100%;font:inherit}
.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}button,.button{border:0;border-radius:9px;padding:11px 16px;font:600 14px system-ui;cursor:pointer;text-decoration:none;display:inline-block}
.primary{background:var(--accent);color:white}.secondary{background:#eef2ff;color:#233d91}.download{background:#0f766e;color:#fff}
.error{background:#fff1f2;border:1px solid #fecdd3;color:#9f1239;padding:12px 14px;border-radius:10px}
.notice{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:12px 14px;border-radius:10px;font-size:14px}
.table-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;white-space:nowrap}th,td{text-align:left;padding:12px 13px;border-bottom:1px solid var(--line)}th{font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--muted);background:#f8fafc}tbody tr:last-child td{border-bottom:0}.num{text-align:right;font-variant-numeric:tabular-nums}
.metrics{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px}.metric{background:#f8fafc;border:1px solid var(--line);padding:10px 13px;border-radius:10px}.metric strong{display:block;font-size:20px}.metric span{color:var(--muted);font-size:12px}
.warning-list{margin:8px 0 0;padding-left:22px}.warning-list li{margin:5px 0}.foot{font-size:12px;color:var(--muted);margin:18px 0 30px}
@media(max-width:680px){main{margin:22px auto}.header{display:block}.period{display:inline-block;margin-top:10px}.fields{grid-template-columns:1fr}.panel{padding:16px}h1{font-size:24px}}
</style>
</head>
<body>
<main>
  <div class="header">
    <div><h1>Отчёт по модерации</h1><div class="muted">Показатели модераторов по заявкам Маркета Битрикс24</div></div>
    <div class="period">1–30 сентября 2026 · Москва (UTC+3)</div>
  </div>

  <section class="panel">
    <h2>1. Загрузите исходные данные</h2>
    <p class="help">Нужны три файла applications_page_*.json и файл users.json в формате выгрузки REST API Bitrix24.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="source" value="upload">
      <div class="fields">
        <div class="field"><label for="applications">Страницы заявок — выберите 3 файла</label><input id="applications" type="file" name="applications[]" accept=".json,application/json" multiple required><p class="help">applications_page_1.json, _2.json, _3.json</p></div>
        <div class="field"><label for="users">Список модераторов</label><input id="users" type="file" name="users" accept=".json,application/json" required><p class="help">users.json</p></div>
      </div>
      <div class="actions"><button class="primary" type="submit">Построить отчёт</button></div>
    </form>
    <form method="post" class="actions"><input type="hidden" name="source" value="sample"><button class="secondary" type="submit">Проверить на приложенных тестовых данных</button></form>
  </section>

  <?php if ($error !== null): ?><div class="error"><?= h($error) ?></div><?php endif; ?>

  <?php if (is_array($report) && isset($report['rows'])): ?>
  <section class="panel">
    <h2>2. Результат</h2>
    <div class="metrics">
      <div class="metric"><strong><?= count($report['rows']) ?></strong><span>Модераторов в отчёте</span></div>
      <div class="metric"><strong><?= (int)$report['source_count'] ?></strong><span>Записей в исходных файлах</span></div>
      <div class="metric"><strong><?= (int)$report['unique_count'] ?></strong><span>Уникальных заявок после проверки ID</span></div>
    </div>
    <div class="notice">Доля возвратов = returned / (approved + returned) за сентябрь. Типичное время — медиана длительности корректных закрытых заявок, попавших в сентябрь по московскому времени.</div>
    <div class="table-wrap" style="margin-top:16px">
      <table>
        <thead><tr><th>Модератор</th><th class="num">Закрыто</th><th class="num">Доля возвратов</th><th class="num">Медиана, ч</th><th class="num">В работе</th><th>Топ-3 причины возврата (количество)</th></tr></thead>
        <tbody>
        <?php foreach ($report['rows'] as $row): ?>
          <tr>
            <td><?= h($row['name']) ?></td>
            <td class="num"><?= (int)$row['closed'] ?></td>
            <td class="num"><?= $row['return_share'] === null ? '—' : number_format($row['return_share'] * 100, 2, ',', ' ') . '%' ?></td>
            <td class="num"><?= $row['median_hours'] === null ? '—' : number_format($row['median_hours'], 2, ',', ' ') ?></td>
            <td class="num"><?= (int)$row['in_progress'] ?></td>
            <td><?php if (!$row['top_return_reasons']): ?>—<?php else: ?><ol style="margin:0;padding-left:20px"><?php foreach ($row['top_return_reasons'] as $reason): ?><li><?= h($reason['reason']) ?> <strong>(<?= (int)$reason['count'] ?>)</strong></li><?php endforeach; ?></ol><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="actions"><a class="button download" href="?download=1">Скачать CSV</a></div>
  </section>
  <?php if ($report['warnings']): ?>
  <section class="panel"><h2>Предупреждения по данным (<?= count($report['warnings']) ?>)</h2><p class="help">Программа сообщает об обнаруженных проблемах, а не скрывает их.</p><ul class="warning-list"><?php foreach ($report['warnings'] as $warning): ?><li><?= h($warning) ?></li><?php endforeach; ?></ul></section>
  <?php endif; ?>
  <?php endif; ?>
  <p class="foot">Локальный инструмент для тестового задания. Данные обрабатываются PHP на том сервере, где запущено приложение. Топ-3 причин возврата рассчитывается отдельно для каждого модератора по закрытым в сентябре заявкам со статусом returned.</p>
</main>
</body>
</html>
