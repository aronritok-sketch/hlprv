# L'INORA – Időpontfoglaló konfigurátor

WordPress bővítmény: `linora-booking/`. Egy blokk (`iucb_add_block`), ami a főoldalra és a fejléc „Időpontfoglalás” gombjára nyíló modalba is betehető. Bence Figma-tervének 2. verzióját követi („Egyedi kezelési konfigurátor + Amelia háttérben”), az árak a `LINORA_lezer_arlista_vegleges.xlsx` táblázatból jönnek.

## A foglalás menete

| Lépés | Mit lát a vendég | Ki intézi |
|---|---|---|
| 1. Terület | Női / Férfi → Arc / Felsőtest / Alsótest / Kombinált → terület (pl. Bajusz) | konfigurátor |
| 2. Alkalmak | 1 alkalom / 4 alkalmas bérlet / 8 alkalmas bérlet, alkalmankénti ár és kedvezmény | konfigurátor |
| 3. Időpont | naptár a szabad napokkal, majd a szabad időpontok | Amelia |
| 4. Összegzés | tételek, időpontok, végösszeg; **+ Újabb terület**, időpont módosítása, törlés | konfigurátor |
| 5. Adatok | név, e-mail, telefon | konfigurátor |
| Fizetés | számlázási adatok, fizetési mód, megrendelés | WooCommerce pénztár |

- A kosár közös: ha a főoldalon összerakott kezeléseket utána a fejléc modaljában nyitja meg a vendég, ott is ugyanazok látszanak.
- Egy vendég több kezelést is foglalhat ugyanarra a napra. A már kiválasztott kezelésekkel ütköző időpontokat a naptár nem kínálja fel.
- A pénztárban minden tétel saját néven szerepel (pl. „Bajusz (női) – 4 alkalmas bérlet”), alatta az Amelia adataival (időpont, munkatárs). A számlázási mezőket a megadott név, e-mail és telefonszám előre kitölti.
- A megrendeléskor az Amelia rögzíti a foglalásokat, a WooCommerce rendeléshez kötve.
- Bérletnél most csak az első alkalom időpontját foglalja le a vendég. A további alkalmakat Zsanett az Amelia admin felületén foglalja a bérlet terhére.
- Ha egy időpontot közben más lefoglalt, a vendég a konfigurátorban hibaüzenetet kap, és az adott tételnél új időpontot választhat.

## Telepítés

Szükséges: Amelia (Pro), WooCommerce, és a téma `iu_custom_blocks` mu-pluginja.

1. Bővítmények → Új hozzáadása → Bővítmény feltöltése: `linora-booking.zip` (a `linora/linora-booking` mappa zipelve), majd Bekapcsolás.
2. **Eszközök → Linora árlista → Importálás az Ameliába.** Előtte a táblázat mutatja, mi jön létre. Az importálás többször is lefuttatható: a meglévő tételeket név alapján felismeri, újakat nem hoz létre belőlük, legfeljebb az árukat frissíti.
3. **Főoldal:** a „Foglaljon időpontot online!” szekcióban az Amelia blokk helyére kerüljön az **Időpontfoglaló (konfigurátor)** blokk (Widgetek kategória).
4. **Fejléc modal:** Sablonok → **Footer** → a `booking-modal` modalban a „Most ön következik…” címsor alá kerüljön ugyanez a blokk.
5. Próbafoglalás banki átutalással, utána a rendelés törlése vagy lemondása (ez az Ameliában is lemondja a foglalást).

A blokk beállítása: **Kezdő lépés.** Alapból a nemmel indul, de beállítható, hogy rögtön a női vagy a férfi kezelésekkel kezdjen (pl. egy férfi kezelés aloldalán).

## Mit hoz létre az Ameliában

| Amelia | Példa | Darab |
|---|---|---|
| Kategória: nem + testtáj | `Női – Arc`, `Férfi – Kombinált` | 8 |
| Szolgáltatás: terület, 1 alkalom ára | `Bajusz (női)` – 8 000 Ft | 66 |
| Csomag: bérlet, egy szolgáltatás 4× vagy 8× | `Bajusz (női) – 4 alkalmas bérlet` – 28 800 Ft | 120 |

- Minden szolgáltatás és bérlet Zsanetthez (az aktív munkatársakhoz) kerül.
- Az Amelia WooCommerce beállításai közül bekapcsolja azt, hogy egy kosárban több foglalás lehessen (`bookMultiple`). E nélkül az Amelia minden új tételnél kiürítené a kosarat.
- A fejlesztői tesztadatokat (`Teszt`, `Teszt 2`, `Teszt … alkalaom` és a régi `Default`, `Női`, `Férfi` kategóriák) elrejti, nem törli.
- **A konfigurátor mindig az Amelia aktuális adataiból dolgozik.** Ha Zsanett az Ameliában átír egy árat, elrejt egy területet, vagy új szolgáltatást vesz fel egy `Női – …` / `Férfi – …` kategóriába, az a konfigurátorban is megjelenik. Új bérlethez elég egy csomag, amiben csak az adott szolgáltatás van N-szer.
- A `(női)` / `(férfi)` utótag csak az Amelia admin miatt kell (két „Bajusz” van); a vendég nem látja.

## Kezelési idők

A táblázatban nem volt kezelési idő, ezért ár-sáv alapján becsültem, 30 percre kerekítve:

- kis területek (bajusz, áll, hónalj, has stb.): 30 perc;
- nagyobb területek (teljes arc, teljes kar, hát, comb, teljes láb stb.): 60 perc;
- kombinált csomagok: 30–120 perc.

Az Ameliában szolgáltatásonként átírhatók, az újraimportálás nem írja felül őket.

**Miért 30 perc a legkisebb egység:**
- Az Amelia a következő szabad időpontot az előző foglalás végétől számolja. Egy 10:00-kor kezdődő 15 perces kezelés után a 10:30-as kezdés érvénytelenné válna.
- Ezért a bővítmény a foglalt időt mindig egész idősávra (most 30 perc) kerekíti. Így az egymás után választott kezelések időpontjai a rendeléskor is érvényesek maradnak.
- Kezelés előtti és utáni szünetet (Amelia „buffer”) csak 30 perc többszöröseként érdemes megadni.

## Nyitott kérdések

- **Kezelési idők:** Zsanettel érdemes átnézni őket, az Ameliában átírhatók.
- **Bérletek érvényessége:** most nincs lejárat. Ha kell, az Ameliában csomagonként beállítható.
- **RF és EMS árak:** a táblázat szerint később véglegesítik őket. Új kategóriaként (pl. `Női – Alakformálás`) felvehetők, és a konfigurátorban új testtájként jelennek meg.
- **Cache:** ha lesz oldal-gyorsítótár, árváltozás után üríteni kell, mert az árak a főoldal HTML-jébe kerülnek. A szabad időpontok mindig élőben jönnek.

## Fájlok

- `linora-booking.php`: a bővítmény belépési pontja.
- `includes/catalog.php`: a konfigurátor kínálata az Amelia táblákból.
- `includes/amelia.php`: az Amelia saját parancsainak hívása (szabad időpontok, WooCommerce kosár).
- `includes/checkout.php`: a két admin-ajax végpont (`lnr_booking_slots`, `lnr_booking_checkout`), a tételek ellenőrzése, a kosár- és rendelési tételnevek.
- `includes/block.php`: a blokk (`linora/booking`).
- `includes/importer.php`: az árlista importálása (Eszközök → Linora árlista).
- `data/pricelist.php`: a táblázat adatai.
- `assets/booking.js`, `assets/booking.css`: a konfigurátor (külső könyvtár nélkül).

Teszt: `php tests/linora.php`
