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

        for ($i = 1; $i <= 8; $i++) {
            // 0 = automatisch: das vom Gerät gemeldete pwm#{i}#max verwenden.
            $this->RegisterPropertyInteger("ch{$i}_max_pwm", 0);
        }

        $this->RegisterAttributeString('channel_names',     '{}');
        $this->RegisterAttributeString('channel_colors',    '{}');
        $this->RegisterAttributeString('channel_pwm_raw',   '{}');
        $this->RegisterAttributeString('channel_pwm_max',   '{}');
        $this->RegisterAttributeString('weather_program',   '');
        $this->RegisterAttributeString('weather_programs',  '[]');
        $this->RegisterAttributeString('day_curves',        '{}');
        $this->RegisterAttributeString('connectivity_state', '');

        $this->RegisterTimer('UpdateTimer', 0, 'SR8_UpdateAll($_IPS[\'TARGET\']);');

        // HTML-SDK visualization (no separate HTMLBox variable needed)
        $this->SetVisualizationType(1);

        // Actionable IPS variables (shown as toggle tiles in TileViz alongside the main tile)
        $this->RegisterVariableFloat('Temperature', 'Wassertemperatur', '~Temperature');
        $this->RegisterVariableBoolean('Connectivity', 'Verbindung', '~Switch');

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

        $this->RefreshWeatherPrograms();
        $this->UpdateAll();
    }

    /**
     * Discover weather program names via a full device backup dump.
     * Deliberately NOT called from the 30s UpdateAll poll loop — the
     * SunRiser's embedded webserver struggles under repeated /backup load.
     */
    public function RefreshWeatherPrograms(): void
    {
        $api = $this->createApi();

        // Diagnostic: log what pwm#{i}#max actually comes back as via the same
        // targeted config read (getModuleConfig()) that applyConfig() uses.
        // Run and logged FIRST and independently of the /backup call below —
        // GET /backup has repeatedly failed with an identical "373 bytes
        // missing" truncation on this device, and that shouldn't prevent this
        // diagnostic (or count as a connectivity outage).
        try {
            $channels = $this->ReadPropertyInteger('channels');
            $config   = $api->getModuleConfig($channels);
            $pwmDiag  = [];
            foreach ($config as $k => $v) {
                if (preg_match('/^pwm#\d+#/', $k)) {
                    $pwmDiag[$k] = $v;
                }
            }
            $this->LogMessage('SR8 Diagnose PWM-Keys (getModuleConfig): ' . json_encode($pwmDiag), KL_MESSAGE);
        } catch (Throwable $e) {
            $this->LogMessage('SR8 RefreshWeatherPrograms (Diagnose): ' . $e->getMessage(), KL_ERROR);
        }

        // GET /backup is a much larger payload and appears to fail consistently
        // (not just transiently) on this device — isolate it so it can't stop
        // the diagnostic above from running.
        try {
            $backup = $api->getBackup();
            $this->WriteAttributeString('weather_programs', json_encode($api->getWeatherProgramNames($backup)));
        } catch (Throwable $e) {
            $this->LogMessage('SR8 RefreshWeatherPrograms (Backup): ' . $e->getMessage(), KL_ERROR);
        }
    }

    // ─── HTML-SDK: initial tile render ────────────────────────────────────────

    public function GetVisualizationTile(): string
    {
        return $this->buildHTML();
    }

    // ─── IPS action handler ───────────────────────────────────────────────────

    public function RequestAction($ident, $value): void
    {
        if (!(bool) $this->GetValue('Connectivity')) {
            // Device known offline — skip the doomed HTTP call rather than
            // waiting out another timeout; the tile already shows this via
            // the red connectivity badge.
            return;
        }

        try {
            $api = $this->createApi();

            if ($ident === 'Maintenance') {
                $active = (bool) $value;
                $api->setMaintenance($active);
                $this->SetValue('Maintenance', $active);
                $this->pushValue('Maintenance', $active);

            } elseif (in_array($ident, ['Thunder', 'Moon', 'Clouds', 'Rain'], true)) {
                $active  = (bool) $value;
                $program = $this->ReadAttributeString('weather_program');
                if ($program !== '') {
                    $api->setWeatherEffect($program, strtolower($ident), $active);
                }
                $this->SetValue($ident, $active);
                $this->pushValue($ident, $active);

            } elseif (preg_match('/^CH(\d+)_Program$/', $ident, $m)) {
                $ch      = (int) $m[1];
                $program = trim((string) $value);
                $api->setChannelWeatherProgram($ch, $program);
                $this->SetValue($ident, $program);
                $this->pushValue($ident, $program);

            } elseif (preg_match('/^CH(\d+)_TestPwm$/', $ident, $m)) {
                $ch  = (int) $m[1];
                $pwm = max(0, min($this->deviceMaxPwm($ch), (int) $value));
                $api->setPwms([(string) $ch => $pwm]);

                $pct = $this->pwmToPercent($ch, $pwm);
                $this->SetValue("CH{$ch}_Brightness", $pct);
                $this->pushValue("CH{$ch}_Brightness", $pct);

                $rawMap      = json_decode($this->ReadAttributeString('channel_pwm_raw'), true) ?: [];
                $rawMap[$ch] = $pwm;
                $this->WriteAttributeString('channel_pwm_raw', json_encode($rawMap));
            }
        } catch (Throwable $e) {
            $this->setConnectivity(false, $e->getMessage());
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
                    $this->pushValue($ident, $toggles[$k]);
                }
            }

            $this->setConnectivity(true);
            $this->SetStatus(102);
        } catch (Throwable $e) {
            $this->setConnectivity(false, $e->getMessage());
            $this->SetStatus(200);
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    /**
     * Update the Connectivity flag and log only on state transitions — the
     * SunRiser's network interface drops out for minutes at a time, and
     * logging every failed 30s poll during an outage floods the log.
     */
    private function setConnectivity(bool $online, string $detail = ''): void
    {
        $prevState = $this->ReadAttributeString('connectivity_state');
        $newState  = $online ? '1' : '0';

        if ($prevState !== $newState) {
            $msg = $online
                ? 'SR8: Verbindung zum Gerät hergestellt'
                : 'SR8: Gerät nicht erreichbar' . ($detail !== '' ? " ({$detail})" : '')
                    . ' – weitere Meldungen werden unterdrückt bis zur Wiederverbindung';
            $this->LogMessage($msg, $online ? KL_MESSAGE : KL_ERROR);
            $this->WriteAttributeString('connectivity_state', $newState);
        }

        $this->SetValue('Connectivity', $online);
        $this->pushValue('Connectivity', $online);
    }

    /** The device's own reported PWM ceiling for a channel (not the display override). */
    private function deviceMaxPwm(int $channel): int
    {
        $deviceMaxMap = json_decode($this->ReadAttributeString('channel_pwm_max'), true) ?: [];
        $deviceMax    = (int) ($deviceMaxMap[$channel] ?? 0);
        return $deviceMax > 0 ? $deviceMax : 1000;
    }

    /**
     * The value that should count as 100% for a channel: the user-configured
     * display override if set (>0), otherwise the device's own reported
     * pwm#{i}#max (channels can have different native PWM ceilings), with a
     * last-resort fallback of 255 if the device never reported one.
     */
    private function effectiveMaxPwm(int $channel): int
    {
        $override = $this->ReadPropertyInteger("ch{$channel}_max_pwm");
        return $override > 0 ? $override : $this->deviceMaxPwm($channel);
    }

    private function pwmToPercent(int $channel, int $rawPwm): int
    {
        $maxPwm = $this->effectiveMaxPwm($channel);
        $pct    = (int) round(max(0, $rawPwm) / $maxPwm * 100);
        return max(0, min(100, $pct));
    }

    /**
     * Push a live value update to an already-open visualization tile.
     * Received client-side by window.handleMessage({key, value}) in buildHTML().
     */
    private function pushValue(string $key, $value): void
    {
        $this->UpdateVisualizationValue(json_encode(['key' => $key, 'value' => $value]));
    }

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
        $rawMap   = [];

        for ($i = 1; $i <= $channels; $i++) {
            $raw        = (int) ($pwms[(string) $i] ?? $pwms[$i] ?? 0);
            $rawMap[$i] = $raw;
            $pct        = $this->pwmToPercent($i, $raw);
            $this->SetValue("CH{$i}_Brightness", $pct);
            $this->pushValue("CH{$i}_Brightness", $pct);
        }
        $this->WriteAttributeString('channel_pwm_raw', json_encode($rawMap));

        if (isset($state['maintenance'])) {
            $active = (bool) $state['maintenance'];
            $this->SetValue('Maintenance', $active);
            $this->pushValue('Maintenance', $active);
        }

        foreach (['temperature', 'temp', 'water_temp'] as $key) {
            if (isset($state[$key])) {
                $temp = (float) $state[$key];
                $this->SetValue('Temperature', $temp);
                $this->pushValue('Temperature', $temp);
                break;
            }
        }
    }

    private function applyConfig(array $config, int $channels): void
    {
        $names   = [];
        $colors  = [];
        $maxPwms = [];

        for ($i = 1; $i <= $channels; $i++) {
            $names[$i]  = (string) ($config["pwm#{$i}#name"]  ?? "Kanal {$i}");
            $colors[$i] = (string) ($config["pwm#{$i}#color"] ?? '#ffffff');

            // pwm#{i}#max was expected to report each channel's native PWM ceiling,
            // but never returns a usable value on this device (see diagnostic log
            // in RefreshWeatherPrograms) — observed raw values reach up to 1000
            // (two channels seen pegged exactly at 1000), so that's the fallback
            // instead of the originally-assumed 255. Override per channel via
            // ch{i}_max_pwm in the instance settings if this default is wrong.
            $deviceMax   = (int) ($config["pwm#{$i}#max"] ?? 0);
            $maxPwms[$i] = $deviceMax > 0 ? $deviceMax : 1000;

            $prog = (string) ($config["pwm#{$i}#weather"] ?? '');
            $this->SetValue("CH{$i}_Program", $prog);
            $this->pushValue("CH{$i}_Program", $prog);

            if ($i === 1 && $prog !== '') {
                $this->WriteAttributeString('weather_program', $prog);
            }
        }

        $this->WriteAttributeString('channel_names',   json_encode($names));
        $this->WriteAttributeString('channel_colors',  json_encode($colors));
        $this->WriteAttributeString('channel_pwm_max', json_encode($maxPwms));

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
        $names    = json_decode($this->ReadAttributeString('channel_names'),   true) ?: [];
        $colors   = json_decode($this->ReadAttributeString('channel_colors'),  true) ?: [];
        $curves   = json_decode($this->ReadAttributeString('day_curves'),      true) ?: [];
        $programs = json_decode($this->ReadAttributeString('weather_programs'), true) ?: [];
        $rawPwms  = json_decode($this->ReadAttributeString('channel_pwm_raw'), true) ?: [];

        $temp        = (float) $this->GetValue('Temperature');
        $online      = (bool)  $this->GetValue('Connectivity');
        $maintenance = (bool)  $this->GetValue('Maintenance');
        $thunder     = (bool)  $this->GetValue('Thunder');
        $moon        = (bool)  $this->GetValue('Moon');
        $clouds      = (bool)  $this->GetValue('Clouds');
        $rain        = (bool)  $this->GetValue('Rain');

        // Initial state as JSON for JS
        $initJson = json_encode([
            'temp'         => $temp,
            'connectivity' => $online,
            'maintenance'  => $maintenance,
            'Thunder'      => $thunder,
            'Moon'         => $moon,
            'Clouds'       => $clouds,
            'Rain'         => $rain,
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
        $connCls   = $online ? 'badge badge-green' : 'badge badge-red';
        $connText  = $online ? '● Online' : '● Offline';

        // Per-channel settings: weather profile assignment + temporary PWM test override
        $settingsHtml = '';
        for ($i = 1; $i <= $channels; $i++) {
            $name           = htmlspecialchars($names[$i] ?? "K{$i}", ENT_QUOTES);
            $currentProgram = (string) $this->GetValue("CH{$i}_Program");
            $currentPwm     = (int) ($rawPwms[$i] ?? 0);
            $deviceMax      = $this->deviceMaxPwm($i);
            $effectiveMax   = $this->effectiveMaxPwm($i);

            $optionsHtml = "<option value=''" . ($currentProgram === '' ? ' selected' : '') . ">–</option>";
            foreach ($programs as $p) {
                $pEsc = htmlspecialchars((string) $p, ENT_QUOTES);
                $sel  = ($p === $currentProgram) ? ' selected' : '';
                $optionsHtml .= "<option value='{$pEsc}'{$sel}>{$pEsc}</option>";
            }

            $settingsHtml .= "<div class='setting-row'>"
                . "<span class='setting-name'>{$name}</span>"
                . "<select id='prog{$i}' onchange=\"requestAction('CH{$i}_Program', this.value)\">{$optionsHtml}</select>"
                . "<input id='pwm{$i}' type='number' min='0' max='{$deviceMax}' value='{$currentPwm}' title='PWM 0-{$deviceMax} (Geraete-Maximum). Anzeige-100% aktuell bei {$effectiveMax}.'>"
                . "<button onclick=\"requestAction('CH{$i}_TestPwm', parseInt(document.getElementById('pwm{$i}').value)||0)\">Test</button>"
                . "</div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
html{height:100%}
*{box-sizing:border-box;margin:0;padding:0}
body{overflow-y:auto;overflow-x:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;display:flex;flex-direction:column;padding:10px;gap:8px}
.header{display:flex;justify-content:flex-end;align-items:center;gap:6px;font-size:13px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px;flex:none}
.temp{font-size:13px;color:#7ec8e3}
.channels{display:flex;gap:10px;height:90px;align-items:flex-end;flex:none}
.ch{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px}
.bar-wrap{width:100%;height:70px;background:#1e2d40;border-radius:4px;display:flex;align-items:flex-end;overflow:hidden}
.bar-fill{width:100%;border-radius:4px;min-height:2px;transition:height .4s}
.ch-pct{font-size:11px;font-weight:700}
.ch-name{font-size:10px;color:#8aa8c8;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%}
.curve-wrap{flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden}
.curve-wrap svg{flex:1;min-height:0;width:100%;display:block}
.curve-labels{flex:none;display:flex;justify-content:space-between;font-size:10px;color:#4a6a8a;margin-top:2px}
.weather-row{display:flex;gap:6px;flex-wrap:wrap;align-items:center;flex:none}
.badge{padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent;cursor:pointer;user-select:none;transition:all .2s}
.badge-on{background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0}
.badge-off{background:#1a2535;border-color:#2a3a50;color:#4a6a8a}
.badge-warn{background:#4a2010;border-color:#8a4020;color:#f08060}
.badge-green{background:#124a1e;border-color:#2f8a44;color:#7ee89a;cursor:default}
.badge-red{background:#4a1010;border-color:#8a2020;color:#f06060;cursor:default}
.settings-panel{display:flex;flex-direction:column;gap:4px;border-top:1px solid #1e3a5f;padding-top:6px;overflow-y:auto}
.setting-row{display:flex;align-items:center;gap:6px}
.setting-name{flex:1;font-size:11px;color:#8aa8c8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.setting-row select{flex:1;min-width:0;background:#1a2535;color:#d0e8ff;border:1px solid #2a3a50;border-radius:4px;font-size:11px;padding:2px 4px}
.setting-row input{width:48px;background:#1a2535;color:#d0e8ff;border:1px solid #2a3a50;border-radius:4px;font-size:11px;padding:2px 4px}
.setting-row button{background:#1e4a6e;color:#7ec8f0;border:1px solid #3a8abf;border-radius:4px;font-size:11px;padding:2px 8px;cursor:pointer}
</style>
</head>
<body>
<div class="header">
  <span id="conn_badge" class="{$connCls}">{$connText}</span>
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
  <span class="badge badge-off" onclick="toggleSettings()">⚙ Einstellungen</span>
</div>
<div id="settings_panel" class="settings-panel" style="display:none">{$settingsHtml}</div>
<script>
// WebFront injects its own body{margin-top:...;margin-bottom:...} (reserved space
// for the tile's title/expand-icon overlay, value varies per tile/theme). Our own
// CSS reset can't win that specificity fight (element selector beats *), and simply
// zeroing it would let our content render under that overlay. So: measure whatever
// margin WebFront actually applied and size the body to exactly fill what's left,
// instead of guessing a fixed pixel value.
(function() {
  var cs = getComputedStyle(document.body);
  var vExtra = (parseFloat(cs.marginTop) || 0) + (parseFloat(cs.marginBottom) || 0);
  document.body.style.height = 'calc(100% - ' + vExtra + 'px)';
})();

var state = {$initJson};

window.handleMessage = function(raw) {
  var data = JSON.parse(raw);
  var key = data.key, val = data.value;
  if (key === 'Temperature') {
    document.getElementById('temp_display').textContent = '🌡 ' + (val > 0 ? val.toFixed(1) + ' °C' : '– °C');
  } else if (key === 'Connectivity') {
    var connEl = document.getElementById('conn_badge');
    if (connEl) {
      connEl.className = 'badge ' + (val ? 'badge-green' : 'badge-red');
      connEl.textContent = val ? '● Online' : '● Offline';
    }
    state.connectivity = val;
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
  } else if (key.indexOf('_Program') > 0) {
    var chP = key.replace('CH','').replace('_Program','');
    var sel = document.getElementById('prog' + chP);
    if (sel) sel.value = val;
  }
};

function toggleSettings() {
  var p = document.getElementById('settings_panel');
  if (!p) return;
  p.style.display = (p.style.display === 'none') ? 'flex' : 'none';
}

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
        $points = [];
        for ($i = 0; $i + 1 < count($markers); $i += 2) {
            $points[] = [
                max(0, min(1440, (int) $markers[$i])),
                max(0, min(100, (int) $markers[$i + 1])),
            ];
        }
        if (count($points) < 2) return '';

        usort($points, static fn($a, $b) => $a[0] <=> $b[0]);

        $first = $points[0];
        $last  = $points[count($points) - 1];

        // The day curve repeats every 24h — most channels don't have markers
        // covering the full night, which otherwise leaves the line floating
        // mid-canvas with a gap at both edges. Extend it to x=0 and x=1440 by
        // interpolating across the midnight wrap (last marker of "today" to
        // first marker of "tomorrow"), consistent with how the segments
        // between markers are already just straight-line interpolations.
        $span    = ($first[0] + 1440) - $last[0];
        $t       = $span > 0 ? (1440 - $last[0]) / $span : 0;
        $wrapPct = $last[1] + $t * ($first[1] - $last[1]);
        $wrapY   = (int) round(100 - max(0, min(100, $wrapPct)));

        $svgPoints = ["0,{$wrapY}"];
        foreach ($points as $p) {
            $svgPoints[] = "{$p[0]}," . (100 - $p[1]);
        }
        $svgPoints[] = "1440,{$wrapY}";

        return implode(' ', $svgPoints);
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) return $color;
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/', $color)) return $color;

        // Device reports LED colors as color temperature ("5500k") or
        // wavelength ("465nm") rather than CSS colors — convert those.
        if (preg_match('/^(\d+)\s*k$/i', $color, $m)) {
            return $this->kelvinToHex((int) $m[1]);
        }
        if (preg_match('/^(\d+)\s*nm$/i', $color, $m)) {
            return $this->wavelengthToHex((int) $m[1]);
        }

        return '#ffffff';
    }

    /** Tanner Helland's color-temperature-to-RGB approximation. */
    private function kelvinToHex(int $kelvin): string
    {
        $temp = max(1000, min(40000, $kelvin)) / 100;

        $red = $temp <= 66
            ? 255
            : 329.698727446 * (($temp - 60) ** -0.1332047592);

        $green = $temp <= 66
            ? 99.4708025861 * log($temp) - 161.1195681661
            : 288.1221695283 * (($temp - 60) ** -0.0755148492);

        if ($temp >= 66) {
            $blue = 255;
        } elseif ($temp <= 19) {
            $blue = 0;
        } else {
            $blue = 138.5177312231 * log($temp - 10) - 305.0447927307;
        }

        return sprintf(
            '#%02x%02x%02x',
            (int) max(0, min(255, round($red))),
            (int) max(0, min(255, round($green))),
            (int) max(0, min(255, round($blue)))
        );
    }

    /** Dan Bruton's visible-wavelength-to-RGB approximation (~380-780nm). */
    private function wavelengthToHex(int $nm): string
    {
        $wl = max(380, min(780, $nm));
        $r  = 0.0;
        $g  = 0.0;
        $b  = 0.0;

        if ($wl < 440) {
            $r = -($wl - 440) / (440 - 380);
            $b = 1.0;
        } elseif ($wl < 490) {
            $g = ($wl - 440) / (490 - 440);
            $b = 1.0;
        } elseif ($wl < 510) {
            $g = 1.0;
            $b = -($wl - 510) / (510 - 490);
        } elseif ($wl < 580) {
            $r = ($wl - 510) / (580 - 510);
            $g = 1.0;
        } elseif ($wl < 645) {
            $r = 1.0;
            $g = -($wl - 645) / (645 - 580);
        } else {
            $r = 1.0;
        }

        if ($wl < 420) {
            $factor = 0.3 + 0.7 * ($wl - 380) / (420 - 380);
        } elseif ($wl < 701) {
            $factor = 1.0;
        } else {
            $factor = 0.3 + 0.7 * (780 - $wl) / (780 - 701);
        }

        $gamma = 0.8;
        $r = $r > 0 ? 255 * (($r * $factor) ** $gamma) : 0;
        $g = $g > 0 ? 255 * (($g * $factor) ** $gamma) : 0;
        $b = $b > 0 ? 255 * (($b * $factor) ** $gamma) : 0;

        return sprintf('#%02x%02x%02x', (int) round($r), (int) round($g), (int) round($b));
    }
}
