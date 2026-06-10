<?php

declare(strict_types=1);

require_once __DIR__ . '/api.php';

class Sunriser8 extends IPSModule
{
    // ─── Lifecycle ────────────────────────────────────────────────────────────

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('host',             'sunriser.fritz.box');
        $this->RegisterPropertyInteger('port',            80);
        $this->RegisterPropertyInteger('update_interval', 30);
        $this->RegisterPropertyInteger('channels',        4);

        $this->RegisterAttributeString('channel_names',   '{}');
        $this->RegisterAttributeString('channel_colors',  '{}');
        $this->RegisterAttributeString('weather_program', '');
        $this->RegisterAttributeString('day_curves',      '{}');

        $this->RegisterTimer('UpdateTimer', 0, 'SR8_UpdateAll($_IPS[\'TARGET\']);');

        // HTML-SDK visualization (no separate HTMLBox variable needed)
        $this->SetVisualizationType(1);

        // Actionable IPS variables (shown as toggle tiles in TileViz alongside the main tile)
        $this->RegisterVariableFloat('Temperature', 'Wassertemperatur', '~Temperature');

        $this->RegisterVariableBoolean('Maintenance', 'Wartungsmodus', '~Switch');
        $this->EnableAction('Maintenance');

        foreach (['Thunder' => 'Gewitter', 'Moon' => 'Mond', 'Clouds' => 'Wolken', 'Rain' => 'Regen'] as $ident => $label) {
            $this->RegisterVariableBoolean($ident, $label, '~Switch');
            $this->EnableAction($ident);
        }

        for ($i = 1; $i <= 8; $i++) {
            $this->RegisterVariableInteger("CH{$i}_Brightness", "Kanal {$i} Helligkeit", '~Intensity.100');
            $this->RegisterVariableString("CH{$i}_Program", "Kanal {$i} Wetterprofil", '');
            $this->EnableAction("CH{$i}_Program");
        }
    }

    public function Destroy(): void
    {
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $host = trim($this->ReadPropertyString('host'));
        if ($host === '') {
            $this->SetStatus(201);
            $this->SetTimerInterval('UpdateTimer', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('update_interval');
        $this->SetTimerInterval('UpdateTimer', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        $this->UpdateAll();
    }

    // ─── HTML-SDK: initial tile render ────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildHTML();
    }

    // ─── IPS action handler ───────────────────────────────────────────────────

    public function RequestAction($ident, $value): void
    {
        try {
            $api = $this->createApi();

            if ($ident === 'Maintenance') {
                $active = (bool) $value;
                $api->setMaintenance($active);
                $this->SetValue('Maintenance', $active);

            } elseif (in_array($ident, ['Thunder', 'Moon', 'Clouds', 'Rain'], true)) {
                $active  = (bool) $value;
                $program = $this->ReadAttributeString('weather_program');
                if ($program !== '') {
                    $api->setWeatherEffect($program, strtolower($ident), $active);
                }
                $this->SetValue($ident, $active);

            } elseif (preg_match('/^CH(\d+)_Program$/', $ident, $m)) {
                $ch      = (int) $m[1];
                $program = trim((string) $value);
                $api->setChannelWeatherProgram($ch, $program);
                $this->SetValue($ident, $program);
            }

            $this->UpdateVisualizationTile($this->buildHTML());
        } catch (Throwable $e) {
            $this->LogMessage('SR8 RequestAction ' . $ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    // ─── Public update ────────────────────────────────────────────────────────

    public function UpdateAll(): void
    {
        try {
            $api      = $this->createApi();
            $channels = $this->ReadPropertyInteger('channels');

            $state  = $api->getState();
            $this->applyState($state);

            $config = $api->getModuleConfig($channels);
            $this->applyConfig($config, $channels);

            $program = $this->ReadAttributeString('weather_program');
            if ($program !== '') {
                $toggles = $api->getWeatherToggles($program);
                foreach (['thunder' => 'Thunder', 'moon' => 'Moon', 'clouds' => 'Clouds', 'rain' => 'Rain'] as $k => $ident) {
                    $this->SetValue($ident, $toggles[$k]);
                }
            }

            $this->UpdateVisualizationTile($this->buildHTML());
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->LogMessage('SR8 UpdateAll: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function createApi(): Sunriser8API
    {
        return new Sunriser8API(
            $this->ReadPropertyString('host'),
            $this->ReadPropertyInteger('port'),
            5
        );
    }

    private function applyState(array $state): void
    {
        $pwms     = $state['pwms'] ?? [];
        $channels = $this->ReadPropertyInteger('channels');

        for ($i = 1; $i <= $channels; $i++) {
            $raw = (int) ($pwms[(string) $i] ?? $pwms[$i] ?? 0);
            $pct = (int) round($raw / 255 * 100);
            $this->SetValue("CH{$i}_Brightness", $pct);
        }

        if (isset($state['maintenance'])) {
            $active = (bool) $state['maintenance'];
            $this->SetValue('Maintenance', $active);
        }

        foreach (['temperature', 'temp', 'water_temp'] as $key) {
            if (isset($state[$key])) {
                $temp = (float) $state[$key];
                $this->SetValue('Temperature', $temp);
                break;
            }
        }
    }

    private function applyConfig(array $config, int $channels): void
    {
        $names  = [];
        $colors = [];

        for ($i = 1; $i <= $channels; $i++) {
            $names[$i]  = (string) ($config["pwm#{$i}#name"]  ?? "Kanal {$i}");
            $colors[$i] = (string) ($config["pwm#{$i}#color"] ?? '#ffffff');

            $prog = (string) ($config["pwm#{$i}#weather"] ?? '');
            $this->SetValue("CH{$i}_Program", $prog);

            if ($i === 1 && $prog !== '') {
                $this->WriteAttributeString('weather_program', $prog);
            }
        }

        $this->WriteAttributeString('channel_names',  json_encode($names));
        $this->WriteAttributeString('channel_colors', json_encode($colors));

        $curves = [];
        for ($i = 1; $i <= $channels; $i++) {
            $raw        = $config["dayplanner#marker#{$i}"] ?? [];
            $curves[$i] = is_array($raw) ? $raw : [];
        }
        $this->WriteAttributeString('day_curves', json_encode($curves));
    }

    // ─── HTML tile (initial render + dynamic updates via handleMessage) ────────

    private function buildHTML(): string
    {
        $channels = $this->ReadPropertyInteger('channels');
        $names    = json_decode($this->ReadAttributeString('channel_names'),  true) ?: [];
        $colors   = json_decode($this->ReadAttributeString('channel_colors'), true) ?: [];
        $curves   = json_decode($this->ReadAttributeString('day_curves'),     true) ?: [];

        $temp        = (float) $this->GetValue('Temperature');
        $maintenance = (bool)  $this->GetValue('Maintenance');
        $thunder     = (bool)  $this->GetValue('Thunder');
        $moon        = (bool)  $this->GetValue('Moon');
        $clouds      = (bool)  $this->GetValue('Clouds');
        $rain        = (bool)  $this->GetValue('Rain');

        // Initial state as JSON for JS
        $initJson = json_encode([
            'temp'        => $temp,
            'maintenance' => $maintenance,
            'Thunder'     => $thunder,
            'Moon'        => $moon,
            'Clouds'      => $clouds,
            'Rain'        => $rain,
        ]);

        // Channel bars HTML
        $barsHtml = '';
        for ($i = 1; $i <= $channels; $i++) {
            $pct   = max(0, min(100, (int) $this->GetValue("CH{$i}_Brightness")));
            $name  = htmlspecialchars($names[$i] ?? "K{$i}", ENT_QUOTES);
            $color = $this->sanitizeColor($colors[$i] ?? '#ffffff');

            $barsHtml .= "<div class='ch'>"
                . "<div class='bar-wrap'><div id='bar{$i}' class='bar-fill' style='height:{$pct}%;background:{$color};'></div></div>"
                . "<div id='pct{$i}' class='ch-pct'>{$pct}%</div>"
                . "<div class='ch-name'>{$name}</div>"
                . "</div>";
        }

        // SVG day curves
        $svgLines = '';
        for ($i = 1; $i <= $channels; $i++) {
            $pts = $this->markersToSvgPoints($curves[$i] ?? []);
            if ($pts !== '') {
                $c = $this->sanitizeColor($colors[$i] ?? '#ffffff');
                $svgLines .= "<polyline points='{$pts}' fill='none' stroke='{$c}' stroke-width='2' opacity='0.85'/>";
            }
        }

        // Weather badges with toggle
        $weatherItems = [
            ['Thunder', '⛈', 'Gewitter', $thunder],
            ['Moon',    '🌙', 'Mond',     $moon],
            ['Clouds',  '☁',  'Wolken',   $clouds],
            ['Rain',    '🌧', 'Regen',    $rain],
        ];
        $badgesHtml = '';
        foreach ($weatherItems as [$key, $icon, $label, $active]) {
            $cls     = $active ? 'badge badge-on' : 'badge badge-off';
            $dataVal = $active ? '1' : '0';
            $text    = htmlspecialchars($icon . ' ' . $label, ENT_QUOTES);
            $badgesHtml .= "<span id='badge_{$key}' class='{$cls}' data-active='{$dataVal}' onclick='toggleEffect(\"{$key}\")'>{$text}</span>";
        }

        $maintCls  = $maintenance ? 'badge badge-warn' : 'badge badge-off';
        $maintData = $maintenance ? '1' : '0';
        $tempStr   = $temp > 0 ? number_format($temp, 1) . ' °C' : '– °C';

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;height:100vh;display:flex;flex-direction:column;padding:10px;gap:8px}
.header{display:flex;justify-content:space-between;align-items:center;font-size:15px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px}
.temp{font-size:13px;color:#7ec8e3}
.channels{display:flex;gap:10px;height:90px;align-items:flex-end}
.ch{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px}
.bar-wrap{width:100%;height:70px;background:#1e2d40;border-radius:4px;display:flex;align-items:flex-end;overflow:hidden}
.bar-fill{width:100%;border-radius:4px;min-height:2px;transition:height .4s}
.ch-pct{font-size:11px;font-weight:700}
.ch-name{font-size:10px;color:#8aa8c8;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%}
.curve-wrap{flex:1;min-height:0}
.curve-wrap svg{width:100%;height:100%}
.curve-labels{display:flex;justify-content:space-between;font-size:10px;color:#4a6a8a;margin-top:2px}
.weather-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;cursor:pointer;user-select:none;transition:all .2s}
.badge-on{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
</style>
</head>
<body>
<div class="header">
  <span>🐠 Aquarium</span>
  <span id="temp_display" class="temp">🌡 {$tempStr}</span>
</div>
<div class="channels">{$barsHtml}</div>
<div class="curve-wrap">
  <svg viewBox="0 0 1440 100" preserveAspectRatio="none">
    <rect width="1440" height="100" fill="#0d1420"/>
    {$svgLines}
  </svg>
  <div class="curve-labels"><span>0:00</span><span>6:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>
</div>
<div class="weather-row">
  {$badgesHtml}
  <span id="badge_Maintenance" class="{$maintCls}" data-active="{$maintData}" onclick="toggleMaintenance()">🔧 Wartung</span>
</div>
<script>
var state = {$initJson};

window.handleMessage = function(data) {
  var key = data.key, val = data.value;
  if (key === 'Temperature') {
    document.getElementById('temp_display').textContent = '🌡 ' + (val > 0 ? val.toFixed(1) + ' °C' : '– °C');
  } else if (key === 'Maintenance') {
    updateBadge('Maintenance', val, val ? '🔧 Wartung aktiv' : '🔧 Wartung', 'badge-warn', 'badge-off');
    state.maintenance = val;
  } else if (['Thunder','Moon','Clouds','Rain'].indexOf(key) >= 0) {
    updateBadge(key, val, null, 'badge-on', 'badge-off');
    state[key] = val;
  } else if (key.indexOf('_Brightness') > 0) {
    var ch = key.replace('CH','').replace('_Brightness','');
    var bar = document.getElementById('bar' + ch);
    var pct = document.getElementById('pct' + ch);
    if (bar) bar.style.height = val + '%';
    if (pct) pct.textContent = val + '%';
  }
};

function updateBadge(key, active, label, clsOn, clsOff) {
  var el = document.getElementById('badge_' + key);
  if (!el) return;
  el.className = 'badge ' + (active ? clsOn : clsOff);
  el.setAttribute('data-active', active ? '1' : '0');
  if (label) el.textContent = label;
}

function toggleEffect(key) {
  var el = document.getElementById('badge_' + key);
  var current = el ? el.getAttribute('data-active') === '1' : false;
  requestAction(key, !current);
}

function toggleMaintenance() {
  var el = document.getElementById('badge_Maintenance');
  var current = el ? el.getAttribute('data-active') === '1' : false;
  requestAction('Maintenance', !current);
}
</script>
</body>
</html>
HTML;
    }

    private function markersToSvgPoints(array $markers): string
    {
        if (count($markers) < 2) return '';
        $points = [];
        for ($i = 0; $i + 1 < count($markers); $i += 2) {
            $points[] = max(0, min(1440, (int) $markers[$i])) . ',' . max(0, min(100, 100 - (int) $markers[$i + 1]));
        }
        usort($points, static fn($a, $b) => (int) explode(',', $a)[0] - (int) explode(',', $b)[0]);
        return implode(' ', $points);
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) return $color;
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/', $color)) return $color;
        return '#ffffff';
    }
}
