<?php
// ============================================================
// video-render.php — FFmpeg'in çalıştığı ayrı sunucuya koyun
// Vercel'den gelen base64 resim + yazıları MP4'e dönüştürür
// ============================================================

$app_password = 'Aslan1905';
$ffmpeg     = 'ffmpeg';
$font_file  = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
$out_dir    = __DIR__ . '/videolar';
$out_url    = 'https://senin-sunucun.com/videolar/';
$scene_count   = 3;
$scene_seconds = 4;
$fps           = 25;

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function wrap_text(string $t, int $width): string {
    $words = preg_split('/\s+/u', trim($t));
    $lines = []; $cur = '';
    foreach ($words as $w) {
        if ($cur !== '' && mb_strlen($cur . ' ' . $w) > $width) { $lines[] = $cur; $cur = $w; }
        else $cur = ($cur === '') ? $w : $cur . ' ' . $w;
    }
    if ($cur !== '') $lines[] = $cur;
    return implode("\n", $lines);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST gerekli'], 405);
@set_time_limit(300);

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (($in['password'] ?? '') !== $app_password) json_out(['error' => 'Yetkisiz'], 401);

$texts  = is_array($in['texts'] ?? null) ? array_values($in['texts']) : [];
$images = is_array($in['images'] ?? null) ? array_values($in['images']) : [];
if (count($texts) < $scene_count || count($images) < $scene_count) {
    json_out(['error' => 'Eksik veri (3 yazı + 3 resim gerekli)'], 400);
}

if (!function_exists('exec')) json_out(['error' => 'exec() kapalı'], 500);
exec(escapeshellarg($ffmpeg) . ' -version 2>&1', $o, $rc);
if ($rc !== 0) json_out(['error' => 'FFmpeg bulunamadı'], 500);
if (!is_file($font_file)) json_out(['error' => 'Font bulunamadı: ' . $font_file], 500);

if (!is_dir($out_dir)) @mkdir($out_dir, 0755, true);
$job = bin2hex(random_bytes(6));
$dir = $out_dir . '/' . $job;
if (!@mkdir($dir, 0755, true)) json_out(['error' => 'Klasör oluşturulamadı'], 500);

for ($i = 0; $i < $scene_count; $i++) {
    $raw = base64_decode((string)$images[$i], true);
    if ($raw === false) json_out(['error' => 'Resim ' . ($i + 1) . ' bozuk'], 400);
    file_put_contents($dir . "/img{$i}.jpg", $raw);
}

$frames = $scene_seconds * $fps;
$list = '';
for ($i = 0; $i < $scene_count; $i++) {
    if (!is_file($dir . "/img{$i}.jpg")) json_out(['error' => 'Sahne ' . ($i + 1) . ' resmi eksik'], 400);
    file_put_contents($dir . "/t{$i}.txt", wrap_text((string)($texts[$i] ?? ''), 22));

    $vf = "scale=1350:2400:force_original_aspect_ratio=increase,crop=1350:2400,"
        . "zoompan=z='min(zoom+0.0007,1.15)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d={$frames}:s=1080x1920:fps={$fps},"
        . "drawbox=x=0:y=1150:w=1080:h=520:color=black@0.5:t=fill,"
        . "drawtext=fontfile='{$font_file}':textfile='t{$i}.txt':fontcolor=white:fontsize=64:line_spacing=14:"
        . "x=(w-text_w)/2:y=1150+(520-text_h)/2:borderw=2:bordercolor=black";

    $cmd = 'cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg($ffmpeg)
         . " -y -i img{$i}.jpg -vf " . escapeshellarg($vf)
         . " -frames:v {$frames} -r {$fps} -an -c:v libx264 -preset veryfast -pix_fmt yuv420p clip{$i}.mp4 2>&1";
    $log = []; exec($cmd, $log, $rc);
    if ($rc !== 0 || !is_file($dir . "/clip{$i}.mp4")) {
        json_out(['error' => 'FFmpeg klip hatası (sahne ' . ($i + 1) . ')', 'detail' => mb_substr(implode("\n", array_slice($log, -8)), 0, 800)], 500);
    }
    $list .= "file 'clip{$i}.mp4'\n";
}
file_put_contents($dir . '/list.txt', $list);

$cmd = 'cd ' . escapeshellarg($dir) . ' && ' . escapeshellarg($ffmpeg)
     . ' -y -f concat -safe 0 -i list.txt -c copy -movflags +faststart video.mp4 2>&1';
$log = []; exec($cmd, $log, $rc);
if ($rc !== 0 || !is_file($dir . '/video.mp4')) json_out(['error' => 'Birleştirme hatası', 'detail' => mb_substr(implode("\n", array_slice($log, -8)), 0, 800)], 500);

json_out(['url' => rtrim($out_url, '/') . '/' . $job . '/video.mp4']);
