# Phase 1 — sandbox átvételi teszt

Az aktuális fejlesztési állapot és a tényleges teszteredmények egyetlen nyilvántartása: [PROJECT_STATUS.md](PROJECT_STATUS.md). Ez a dokumentum a kézi átvétel végrehajtási útmutatója.

## Tesztelendő változat

- Repository: `vanyolai/dolibarr-warrantysvc`.
- Ág: `feature/rma-integrity-audit-concurrency`; kapcsolódó **draft PR #1**.
- Rögzített alkalmazáskód: `fe7cc8ac29b28b5a4a4a223d189e1150b309088b` (a későbbi teszt/dokumentáció commitok nem változtatják meg a telepítendő modulkódot).
- Telepítendő tartalom: kizárólag a repository **`module/`** könyvtára.
- A teljes automatizált tesztkészlet már sikeresen futott a Dolibarr 23.0.4 natív telepítőjével, PHP 8.4 és MariaDB 11.4.5 alatt; a futás linkjét és a pontos verziókat a PROJECT_STATUS tartalmazza.

A `tests/integration/run.sh` és a migrációs tesztszkriptek kizárólag eldobható tesztadatbázisra valók. **Ne futtasd őket a meglévő sandboxon**: a teszt szándékosan módosítja a szintetikus adatbázis sémáját és SQL-hibákat idéz elő. A meglévő sandboxot az alábbi natív felületi próbákkal ellenőrizzük.

## 1. Mentés és a cél ellenőrzése

A projekt korábban rögzített sandbox kódútvonala:
`/mnt/pool1/work/code/web/dolibarr-sandbox/htdocs/custom/warrantysvc/`.
Ha a telepítés óta ez megváltozott, az aktuális **sandbox** útvonalat add meg. A produkciós Dolibarr checkout nem célja ennek a telepítésnek.

Telepítés előtt szükséges:

1. Teljes sandbox adatbázis-mentés, a megszokott mentési eljárással. Az adatbázisnevet a sandbox `htdocs/conf/conf.php` / konténerkonfigurációja alapján ellenőrizd. Ne feltételezd, hogy a központi MariaDB-konténer neve az adatbázis neve is.
2. A jelenlegi modulállományok mentése (az alábbi blokk ezt elvégzi).
3. A sandbox meglévő WarrantySvc dokumentumainak mentése a konfigurált dokumentumkönyvtárból; konténer esetén a tényleges host mountot használd.

Ha a hoston elérhető a MariaDB dump kliens, a DB-mentés például:

```bash
# A három értéket a sandbox tényleges adatbázis-konfigurációjából add meg.
SANDBOX_DB_HOST='127.0.0.1'
SANDBOX_DB_USER='IDE_A_SANDBOX_DB_FELHASZNALO'
SANDBOX_DB_NAME='IDE_A_SANDBOX_ADATBAZIS'

umask 077
BACKUP_DIR="$HOME/backups/warrantysvc-sandbox-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# A --password interaktívan kér jelszót; ne írd bele a parancsba.
mariadb-dump --host="$SANDBOX_DB_HOST" --user="$SANDBOX_DB_USER" --password \
  --single-transaction --routines --triggers "$SANDBOX_DB_NAME" \
  > "$BACKUP_DIR/database.sql"
test -s "$BACKUP_DIR/database.sql"
```

Ha a kliens csak a DB-konténerben érhető el, használd az ott már kialakított mentési módszert ugyanerre a **sandbox sémára**. A fenti példa nem ír az adatbázisba. A teljes mentési folyamat hibamentes lefutását ellenőrizd; egy nem üres fájl önmagában nem bizonyít sikeres dumpot.

## 2. Modulállományok frissítése

Az alábbi blokk a modul tartalmát cseréli ki; a DB-migrációt még nem futtatja. A `BACKUP_DIR` az előző lépésben létrehozott, adatbázis-mentést is tartalmazó könyvtár legyen.

```bash
set -euo pipefail
: "${BACKUP_DIR:?Add meg az ellenőrzött sandbox mentés könyvtárát}"
test -s "$BACKUP_DIR/database.sql"

SANDBOX_ROOT='/mnt/pool1/work/code/web/dolibarr-sandbox'
TARGET="$SANDBOX_ROOT/htdocs/custom/warrantysvc"
SOURCE=$(mktemp -d /tmp/warrantysvc-phase1-source.XXXXXX)

test -f "$SANDBOX_ROOT/htdocs/main.inc.php"
test -f "$TARGET/core/modules/modWarrantySvc.class.php"

git clone --branch feature/rma-integrity-audit-concurrency \
  https://github.com/vanyolai/dolibarr-warrantysvc.git "$SOURCE"
git -C "$SOURCE" checkout --detach fe7cc8ac29b28b5a4a4a223d189e1150b309088b
test "$(git -C "$SOURCE" rev-parse HEAD)" = fe7cc8ac29b28b5a4a4a223d189e1150b309088b

tar -C "$TARGET" -czf "$BACKUP_DIR/module-before.tar.gz" .
test -s "$BACKUP_DIR/module-before.tar.gz"

# Először nézd meg a tényleges fájlváltozásokat.
rsync -a --delete --dry-run --itemize-changes "$SOURCE/module/" "$TARGET/"
# A cél kizárólag a fent ellenőrzött custom/warrantysvc kódkönyvtár.
rsync -a --delete --itemize-changes "$SOURCE/module/" "$TARGET/"
```

A két rsync parancs közül a másodikat az előnézet ellenőrzése után indítsd. A kódkönyvtárba kézzel tett, nem repositoryból származó fájlokat a `--delete` eltávolíthatja; az archívum őrzi a korábbi állapotot. A konfigurált dokumentumkönyvtár nem rsync célpont. A temp checkout és a mentés az ellenőrzés végéig megmarad.

Ha a sandbox PHP OPcache beállítása nem követi automatikusan a fájlváltozásokat, a sandbox szokásos PHP/container újraindításával töltsd újra a kódot.

## 3. Natív aktiválás és migráció

1. Sandbox admin felhasználóval nyisd meg a modulbeállításokat.
2. Kapcsold ki, majd vissza a WarrantySvc modult. Ez a natív `init()` útvonalon futtatja az idempotens migrációt.
3. Nyiss meg egy **korábban már létező** beszállítói RMA-t. Ellenőrizd a státuszt, a dátumokat, a beszállítót, a terméket/szériaszámot, a „Beszállító által elvégzett munka” mezőt és a korábbi történeti bejegyzéseket.
4. Ismételd meg a kikapcsolás/bekapcsolás lépést. A korábbi rekordok, módosított dátumok és naplóbejegyzések maradjanak változatlanok; ne jelenjen meg SQL-hiba.

Opcionális, csak olvasó SQL-ellenőrzés (a `llx_` prefixet a tényleges Dolibarr prefixre cseréld):

```sql
SHOW COLUMNS FROM llx_svc_supplier_rma LIKE 'revision';
SHOW COLUMNS FROM llx_svc_supplier_rma_log LIKE 'date_effective';
SELECT rowid, ref, status, revision, date_authorized, date_supplier_completed
FROM llx_svc_supplier_rma ORDER BY rowid;
```

A `revision` oszlop `NOT NULL`, alapértéke 1. A migráció új oszlopot ad a régi rekordokhoz; a későbbi újraaktiválás nem állítja vissza 1-re a már növelt revíziókat.

## 4. Kézi elfogadási esetek

Használj külön, felismerhető sandbox teszt-RMA-kat. A meglévő üzleti rekordoknál elsősorban olvasási/adatmegőrzési ellenőrzést végezz.

| Eset | Lépések | Elvárt eredmény |
| --- | --- | --- |
| Mezőszintű napló | Változtasd meg a diagnózist és a beszállítói munkaleírást, ments és töltsd újra. | Mindkét új érték megmarad; a napló külön mutatja a régi/új értékeket és a felhasználót. Nem jelenik meg nyers JSON. |
| Két megnyitott szerkesztő | Nyisd meg ugyanazt az RMA-t szerkesztésre két böngészőfülön. Az A fülön ments egy változtatást, majd a B fülön egy másikat. | A B mentése ütközést jelez; az A változtatása megmarad. A B értékei nem írják felül az adatokat. Új mentéshez töltsd újra a rekordot. |
| Státusz vs. régi szerkesztő | B fülön hagyd nyitva a szerkesztést. A fülön léptesd a státuszt, majd B-n próbálj menteni. | A régi szerkesztő ütközést kap; a státusz és a friss adatok megmaradnak. |
| Visszadátumozás | Egy státusz elérése után korrigáld annak üzleti dátumát korábbira. | Az üzleti dátum változik, a történeti „rögzítve” idő nem; külön DATE esemény készül. |
| Automatikus dátum visszavonása | Külön új RMA-n lépj Engedélyezett állapotba dátum előzetes megadása nélkül, majd natív megerősítéssel lépj vissza. | A státusz Tervezetre vált; az automatikusan létrehozott engedélyezési dátum törlődik, a korábbi státuszesemény megmarad. |
| Előre kézzel megadott dátum | Tervezeten előre add meg az engedélyezés dátumát, lépj Engedélyezett állapotba, majd vissza. | A kézzel megadott dátum megmarad. |
| Utólag korrigált dátum | Automatikus engedélyezési dátumot javíts kézzel, majd lépj vissza. | A kézzel korrigált dátum megmarad. |
| Új kör a visszaléptetés után | Visszaléptetés után adj meg másik engedélyezési dátumot, majd újra lépj Engedélyezett állapotba. | A korábbi, visszavont státuszesemény két időpontja megmarad; az új státuszesemény az új dátumot használja. |
| Régi napló kompatibilitása | Régi, egyszerű szöveges státuszbejegyzésű sandbox RMA-n próbálj visszaléptetést. | A régi bejegyzés olvasható marad; az ismeretlen eredetű dátumot megőrizzük. Nem találgatjuk, hogy automatikus volt-e. |
| Előre megadott befejezés | Teszt-RMA-t léptess Szervizelés állapotba, előre add meg a befejezés dátumát, majd válaszd a Javított eredményt. | A dátum megmarad, az üres eredmény Javítottra áll. Visszaléptetésnél a kézi dátum megmarad, az automatikus eredmény visszavonódik. |
| Üres opcionális dátum | Törölj egy opcionális dátumot, majd aktiváld újra a modult. | A dátum és a hozzá tartozó effektív idő üres marad; a rögzített idő megmarad. A kérés dátumát nem lehet törölni. |
| Érintetlen tervezet törlése | Külön, még nem szerkesztett új RMA-t törölj a natív megerősítéssel. | A rekord és az egyetlen CREATE esemény együtt eltűnik. |
| Történettel rendelkező tervezet | Egy másik RMA-t szerkessz, vagy léptesd és visszaléptesd, majd próbáld törölni. | A törlés nem engedélyezett; a rekord és az összes naplóbejegyzés megmarad. |
| Dokumentumok és kapcsolatok | Teszt-RMA-hoz adj PDF-et, külső dokumentumlinket és/vagy kontaktot. | A dokumentumok a saját RMA-hoz tartoznak, letölthetők; a rekord nem törölhető véglegesen. |
| Jogosultságok | Külön tesztfelhasználókkal vizsgáld: csak olvasás; olvasás+szerkesztés; olvasás+törlés. | Csak olvasó nem módosít; szerkesztő nem töröl; csak törlő nem módosít üzleti adatot, de érintetlen tervezetet törölhet. |
| Kapcsolódó modulok | Nyisd meg a kapcsolódó ügyféligényt, garanciát, garancialevelet és beszállítói visszárut. | Az oldalak, korábbi adatok és kapcsolatok továbbra is elérhetők. |

Ebben a szakaszban a beszállítói RMA státuszaihoz még nem tartoznak új fizikai készletmozgások. Ennek a kidolgozása a 2. szakasz feladata; a mostani tesztből ne várj készletcsökkenést/növekedést.

## 5. Eredmény és folytatás

A visszajelzéshez elegendő a tesztelt commit, Dolibarr/PHP/DB verzió, az eset neve, az RMA hivatkozása és a tapasztalt eredmény. Hiba esetén add meg a pontos lépéseket, a megjelenő hibát és a releváns sandbox PHP/SQL naplórészletet; jelszót vagy hozzáférési tokent ne csatolj.

A kézi elfogadást a PROJECT_STATUS-ban rögzítjük. A draft PR és az 1. szakasz a reprezentatív sandbox tesztelés lezárásáig nyitva marad.

## Visszaállítás, ha a sandbox regressziót mutat

Őrizd meg a hibás állapotot/naplót a reprodukcióhoz. A javításig szüneteltesd a sandbox használatát. A kód visszaállítható a `module-before.tar.gz` mentésből, de a régi kód és az új séma együttese nem helyettesíti az ellenőrzött visszaállítást. Ha teljes visszaállítás kell, a **sandbox** adatbázisát, modulkódját és dokumentumait ugyanabból a mentési időpontból állítsd helyre a meglévő restore eljárással. A produkciós adatbázist ez a folyamat nem érinti. Ne távolíts el kézzel revízióoszlopot vagy történeti naplót.
