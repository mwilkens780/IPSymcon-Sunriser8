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

        // ── Display tile (HTML) ────────────────────────────────────────────────
        $this->RegisterVariableString('Visualization', 'Aquarium', '~HTMLBox');

        // ── Read-only values ───────────────────────────────────────────────────
        $this->RegisterVariableFloat('Temperature', 'Wassertemperatur', '~Temperature');

        // ── Actionable controls ────────────────────────────────────────────────
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

            $this->rebuildTile();
        } catch (Throwable $e) {
            $this->LogMessage('SR8 RequestAction ' . $ident . ': ' . $e->getMessage(), KL_ERROR);
        }
    }

    // ─── Public methods ───────────────────────────────────────────────────────

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
                $this->SetValue('Thunder', $toggles['thunder']);
                $this->SetValue('Moon',    $toggles['moon']);
                $this->SetValue('Clouds',  $toggles['clouds']);
                $this->SetValue('Rain',    $toggles['rain']);
            }

            $this->SetStatus(102);
            $this->rebuildTile();
        } catch (Throwable $e) {
            $this->LogMessage('SR8 UpdateAll: ' . $e->getMessage(), KL_ERROR);
            $this->SetStatus(200);
        }
    }

    // ─── Private: state + config ──────────────────────────────────────────────

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
            $this->SetValue("CH{$i}_Brightness", (int) round($raw / 255 * 100));
        }

        if (isset($state['maintenance'])) {
            $this->SetValue('Maintenance', (bool) $state['maintenance']);
        }

        foreach (['temperature', 'temp', 'water_temp'] as $key) {
            if (isset($state[$key])) {
                $this->SetValue('Temperature', (float) $state[$key]);
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

    // ─── HTML tile ────────────────────────────────────────────────────────────

    private function rebuildTile(): void
    {
        $this->SetValue('Visualization', $this->buildHTML());
    }

    private function buildHTML(): string
    {
        $channels = $this->ReadPropertyInteger('channels');
        $names    = json_decode($this->ReadAttributeString('channel_names'),  true) ?: [];
        $colors   = json_decode($this->ReadAttributeString('channel_colors'), true) ?: [];
        $curves   = json_decode($this->ReadAttributeString('day_curves'),     true) ?: [];

        $temp        = $this->GetValue('Temperature');
        $maintenance = $this->GetValue('Maintenance');
        $thunder     = $this->GetValue('Thunder');
        $moon        = $this->GetValue('Moon');
        $clouds      = $this->GetValue('Clouds');
        $rain        = $this->GetValue('Rain');

        // Channel bars
        $barsHtml = '';
        for ($i = 1; $i <= $channels; $i++) {
            $pct   = max(0, min(100, (int) $this->GetValue("CH{$i}_Brightness")));
            $name  = htmlspecialchars($names[$i] ?? "K{$i}");
            $color = $this->sanitizeColor($colors[$i] ?? '#ffffff');

            $barsHtml .= "<div class=\"ch\">"
                . "<div class=\"bar-wrap\"><div class=\"bar-fill\" style=\"height:{$pct}%;background:{$color};\"></div></div>"
                . "<div class=\"ch-pct\">{$pct}%</div>"
                . "<div class=\"ch-name\">{$name}</div>"
                . "</div>";
        }

        // Day curve SVG
        $svgLines = '';
        for ($i = 1; $i <= $channels; $i++) {
            $pts = $this->markersToSvgPoints($curves[$i] ?? []);
            if ($pts !== '') {
                $c = $this->sanitizeColor($colors[$i] ?? '#ffffff');
                $svgLines .= "<polyline points=\"{$pts}\" fill=\"none\" stroke=\"{$c}\" stroke-width=\"2\" opacity=\"0.85\"/>";
            }
        }

        // Weather badges (display only)
        $wb = $this->badge('⛈', 'Gewitter', $thunder)
            . $this->badge('🌙', 'Mond',     $moon)
            . $this->badge('☁',  'Wolken',   $clouds)
            . $this->badge('🌧', 'Regen',    $rain);

        $maintBadge  = $maintenance
            ? '<span class="badge badge-warn">🔧 Wartung aktiv</span>'
            : '';
        $tempDisplay = $temp > 0 ? number_format($temp, 1) . ' °C' : '– °C';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . '* {box-sizing:border-box;margin:0;padding:0}'
            . 'body {font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;background:#0d1b2a;color:#d0e8ff;height:100vh;display:flex;flex-direction:column;padding:10px;gap:8px}'
            . '.header {display:flex;justify-content:space-between;align-items:center;font-size:15px;font-weight:600;border-bottom:1px solid #1e3a5f;padding-bottom:6px}'
            . '.temp {font-size:13px;color:#7ec8e3}'
            . '.channels {display:flex;gap:10px;height:90px;align-items:flex-end}'
            . '.ch {flex:1;display:flex;flex-direction:column;align-items:center;gap:2px}'
            . '.bar-wrap {width:100%;height:70px;background:#1e2d40;border-radius:4px;display:flex;align-items:flex-end;overflow:hidden}'
            . '.bar-fill {width:100%;border-radius:4px;min-height:2px}'
            . '.ch-pct {font-size:11px;font-weight:700}'
            . '.ch-name {font-size:10px;color:#8aa8c8;text-align:center;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;width:100%}'
            . '.curve-wrap {flex:1;min-height:0}'
            . '.curve-wrap svg {width:100%;height:100%}'
            . '.curve-labels {display:flex;justify-content:space-between;font-size:10px;color:#4a6a8a;margin-top:2px}'
            . '.weather-row {display:flex;gap:6px;flex-wrap:wrap;align-items:center}'
            . '.badge {padding:3px 8px;border-radius:12px;font-size:12px;border:1px solid transparent}'
            . '.badge-on {background:#1e4a6e;border-color:#3a8abf;color:#7ec8f0}'
            . '.badge-off {background:#1a2535;border-color:#2a3a50;color:#4a6a8a}'
            . '.badge-warn {background:#4a2010;border-color:#8a4020;color:#f08060}'
            . '</style></head><body>'
            . "<div class=\"header\"><span>🐠 Aquarium</span><span class=\"temp\">🌡 {$tempDisplay}</span></div>"
            . "<div class=\"channels\">{$barsHtml}</div>"
            . '<div class="curve-wrap">'
            . '<svg viewBox="0 0 1440 100" preserveAspectRatio="none"><rect width="1440" height="100" fill="#0d1420"/>' . $svgLines . '</svg>'
            . '<div class="curve-labels"><span>0:00</span><span>6:00</span><span>12:00</span><span>18:00</span><span>24:00</span></div>'
            . '</div>'
            . "<div class=\"weather-row\">{$wb}{$maintBadge}</div>"
            . '</body></html>';
    }

    private function badge(string $icon, string $label, bool $active): string
    {
        $cls  = $active ? 'badge badge-on' : 'badge badge-off';
        $text = htmlspecialchars($icon . ' ' . $label);
        return "<span class=\"{$cls}\">{$text}</span>";
    }

    private function markersToSvgPoints(array $markers): string
    {
        if (count($markers) < 2) {
            return '';
        }
        $points = [];
        for ($i = 0; $i + 1 < count($markers); $i += 2) {
            $x        = max(0, min(1440, (int) $markers[$i]));
            $y        = max(0, min(100, 100 - (int) $markers[$i + 1]));
            $points[] = "{$x},{$y}";
        }
        usort($points, static fn($a, $b) => (int) explode(',', $a)[0] - (int) explode(',', $b)[0]);
        return implode(' ', $points);
    }

    private function sanitizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) {
            return $color;
        }
        if (preg_match('/^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/', $color)) {
            return $color;
        }
        return '#ffffff';
    }
}
