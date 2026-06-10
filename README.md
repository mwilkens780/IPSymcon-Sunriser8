# SunRiser 8 – IP-Symcon Modul

IP-Symcon Modul für den SunRiser 8 LED-Controller von LEDaquaristik.

## Installation

Modulverwaltung → + → URL eintragen:
```
https://github.com/mwilkens780/IPSymcon-Sunriser8
```

## Konfiguration

| Feld | Beschreibung | Standard |
|---|---|---|
| Hostname / IP | Adresse des Geräts | sunriser.fritz.box |
| Port | HTTP-Port | 80 |
| Kanaele | Anzahl aktiver PWM-Ausgänge | 4 |
| Aktualisierungsintervall | Sekunden zwischen Abfragen | 30 |

## Kachel einrichten

Die Variable **"Aquarium"** in der Tile-Visualization hinzufügen und auf **mindestens 4 Spalten × 3 Zeilen** aufspannen. Die Kachel enthält:
- Farbige Helligkeitsbalken pro Kanal
- SVG-Tageskurven aller Kanäle
- Klickbare Wetter-Badges (Gewitter, Mond, Wolken, Regen)
- Wartungs-Button

---

## Entwickler-Dokumentation

### IPS-Modul Entwicklung – Lessons Learned

#### library.json – Pflichtfelder

```json
{
    "id": "{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}",
    "author": "...",
    "name": "...",
    "description": "...",
    "version": "1.0",
    "build": 1,
    "date": 1234567890,
    "url": "https://github.com/..."
}
```

**Kritische Punkte:**
- `"id"` ist **Pflicht** und muss ein GUID im Format `{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}` sein
- `"date"` muss ein **Unix-Timestamp als Integer** sein (kein leerer String)
- Das `"modules"`-Array gehört **nicht** in die library.json — es gehört ausschliesslich in module.json
- Ohne korrekte `id` + `date` verweigert IPS die Installation mit Code -32603

#### module.json – Pflichtfelder

```json
{
    "id": "{XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX}",
    "name": "...",
    "type": 3,
    "vendor": "...",
    "aliases": [],
    "dependencies": [],
    "parentRequirements": [],
    "childRequirements": [],
    "implemented": [],
    "prefix": "SR8"
}
```

**Kritische Punkte:**
- `"id"` muss mit der `id` in library.json übereinstimmen
- `"type": 3` für normale Device-Module (nicht 1)
- `"prefix"` ist erforderlich (Präfix für IPS-Funktionen, z.B. `SR8_UpdateAll`)
- `"dependencies"` muss als leeres Array vorhanden sein

#### Deploy-Workflow

IPS klon Module via git **innerhalb des Docker-Containers** auf dem NAS. Das erzeugt Dateien mit restrictiven Windows-ACLs (`RX` only) im modules-Verzeichnis. Von Windows aus können diese Dateien nicht überschrieben oder gelöscht werden.

**Korrekter Workflow:**
1. Code lokal in `C:\Users\marti\source\IPSymcon-Sunriser8\` entwickeln
2. `git commit && git push` zu GitHub
3. In IPS Modulverwaltung auf **Aktualisieren** klicken — IPS zieht das Update intern via git mit Docker-Rechten

**Stuck-Module löschen** (wenn library.json ungültig war und IPS das Modul nicht mehr entfernen kann):
```bash
# Per SSH auf dem NAS:
rm -rf /volume1/Symcon/INSTANZNAME/Data/modules/MODULNAME
```

#### Webhook-Registrierung

```php
// In Create():
$this->RegisterHook('/hook/ModulName_' . $this->InstanceID);

// Muss in Destroy() wieder deregistriert werden:
public function Destroy(): void {
    if (!IPS_InstanceExists($this->InstanceID)) {
        $this->UnregisterHook('/hook/ModulName_' . $this->InstanceID);
    }
    parent::Destroy();
}
```

Der Webhook ist erreichbar unter `/hook/ModulName_INSTANCEID` und wird in der Methode `ProcessHookData()` behandelt.

#### HTML-Kachel (HTMLBox)

```php
$this->RegisterVariableString('Visualization', 'Anzeigename', '~HTMLBox');
$this->SetValue('Visualization', '<html>...</html>');
```

- Profil `~HTMLBox` zeigt den String-Wert als HTML in der Tile-Visualization
- Tile mind. 4×3 aufspannen für sinnvolle Darstellung
- IPS WebSocket pushed Änderungen automatisch in die Kachel — kein Reload nötig
- JavaScript in der Kachel kann Webhook-URLs per `fetch()` aufrufen

#### Variablen mit Aktion

```php
$this->RegisterVariableBoolean('Maintenance', 'Wartung', '~Switch');
$this->EnableAction('Maintenance');
// → RequestAction('Maintenance', $value) wird aufgerufen wenn User schaltet
```

#### Funktionsprefix

Der `"prefix"` in module.json bestimmt den Präfix aller öffentlichen Funktionen:
- `prefix: "SR8"` → öffentliche Methode `UpdateAll()` wird zu `SR8_UpdateAll($id)`
- Timer-Callbacks: `'SR8_UpdateAll($_IPS[\'TARGET\']);'`
