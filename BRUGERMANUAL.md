# Brugermanual — IT-Udlån Pro

**System til udlånsregistrering af skolens bærbare computere**

---

## Indholdsfortegnelse

1. [Introduktion](#1-introduktion)
2. [Systemkrav og opsætning](#2-systemkrav-og-opsætning)
3. [Log ind som administrator](#3-log-ind-som-administrator)
4. [Dashboard og oversigt](#4-dashboard-og-oversigt)
5. [Computere og hardware](#5-computere-og-hardware)
6. [Elevregister (med CPR)](#6-elevregister-med-cpr)
7. [Undervisere](#7-undervisere)
8. [Reservationer](#8-reservationer)
9. [Opret udlån](#9-opret-udlån)
10. [Registrer aflevering](#10-registrer-aflevering)
11. [Blacklist](#11-blacklist)
12. [Adminansøgninger](#12-adminansøgninger)
13. [Administratorer](#13-administratorer)
14. [Grafiske statistikker](#14-grafiske-statistikker)
15. [Automatiske rykkere](#15-automatiske-rykkere)
16. [Elevportalen](#16-elevportalen)
17. [Database og MySQL Workbench](#17-database-og-mysql-workbench)
18. [Fejlfinding](#18-fejlfinding)

---

## 1. Introduktion

IT-Udlån Pro er et webbaseret system til registrering af udlån af skolens bærbare computere og andet udstyr. Systemet giver administratorerne et samlet overblik over udstyr, elever, lån, afleveringer og reservationer.

**Grænsefladetype:** Webbaseret løsning (åbnes i browseren).

**Primære funktioner:**
- Registrering af computere, mus og andet hardware
- Registrering af elever (inkl. CPR) og undervisere
- Oprettelse og aflevering af udlån
- Automatiske rykkere på e-mail
- Blacklisting af elever med gentagne forsinkede afleveringer
- Grafiske udlånsstatistikker
- Elevportal hvor elever selv kan se og aflevere deres lån

---

## 2. Systemkrav og opsætning

**Krav:**
- PHP 8.0+ (testet med PHP 8.4)
- MySQL 8.0+ (testet med MySQL 8.4)
- En webbrowser (Chrome, Edge, Firefox)

**Start af systemet:**

1. Start MySQL-tjenesten (`MySQL84`).
2. Start PHP-webserveren fra projektmappen:
   ```
   php -S localhost:8000
   ```
3. Åbn browseren og gå til: `http://localhost:8000/admin_login.php`

**Databasekonfiguration** foretages i filen `php_config.php` (værtsnavn, port, databasenavn, bruger og adgangskode).

---

## 3. Log ind som administrator

1. Gå til `http://localhost:8000/admin_login.php`.
2. Indtast dit **brugernavn** og **adgangskode**.
3. Systemet sender en **6-cifret 2-faktorkode** til din registrerede e-mail.
4. Indtast koden på den næste side for at fuldføre login.

> **Bemærk:** Log ind kræver en gyldig personlig e-mail tilknyttet admin-kontoen. Kontakt en eksisterende administrator, hvis din konto mangler en e-mail.

**Log ud:** Klik på "Log ud" i topmenuen eller sidebaren.

---

## 4. Dashboard og oversigt

Dashboardet viser et samlet overblik:

- **Statistik-kort:** Totale PC'er, Ledige PC'er, Aktive udlån og Overskredne frister.
- **Graf:** Udlån pr. måned (søjlediagram).
- **Elevstatus:** Oversigt pr. elev over afleveringer til tiden og forsinkede afleveringer.
- **Aktive udlån:** Tabel over alle nuværende lån med elev, klasse, computer, udlånsdato og afleveringsfrist. Her kan du registrere aflevering af enkelte lån eller flere lån fra samme elev samlet.

**Dage over tid:** For overskredne frister vises antal dage forsinkelsen i rødt.

### Tidligere udlån og scan

Gå til **Tidligere udlån** i sidebaren for at se en samlet liste over alle udlån (aktive og afsluttede).

**Scan udstyr:**
1. Scan QR/stregkoden på udstyret med kameraet (**Scan med kamera**), eller indtast/scan computernummeret i søgefeltet.
2. Tabellen filtreres automatisk, så du kun ser lån for det scannede udstyr.
3. Du ser nu hvilken elev der har lånt udstyret, med navn, klasse og e-mail.

**Aflevering med mailgodkendelse:**
- For **aktive lån** vises en knap **Registrer aflevering**. Når du klikker, registreres afleveringen, og eleven modtager en e-mail, hvor eleven skal godkende afleveringen.
- Afsluttede lån vises med status **til tiden** eller **forsinket**.

**Afventer elevens godkendelse:**
- Når en aflevering er registreret, men eleven ikke har godkendt den endnu, vises lånet i boksen **Afventer elevens godkendelse** øverst på siden.
- Eleven har 7 dages frist til at godkende via mailen.
- Hvis eleven ikke svarer inden fristen, kan du klikke **Godkend aflevering** for at godkende den manuelt på elevens vegne.

> Eleven har sin egen konto i elevportalen, hvor eleven kan se sine egne (aktive og tidligere) lån.

---

## 5. Computere og hardware

Gå til **Computere & hardware** i sidebaren.

### Opret en ny computer
1. Udfyld **Fabrikat** (fx DELL), **Model** (fx Inspiron 9300) og vælg **Mus** (Ja / Almindelig / Nej).
2. **Udstyrsnummer (computernummer)** genereres automatisk (fx `PC-A1B2C3D4`) og kan sættes på en stregkode.
3. Klik **Opret computer**.

### Registrer andet hardware
1. Vælg **Udstyrstype** (skærm, tastatur, mus, printer, tablet, projektor, headset, dockingstation, netværksudstyr eller andet).
2. Udfyld **Navn**, fabrikat, model, serienummer og status.
3. Klik **Registrer hardware**. Udstyret får automatisk et `HW`-nummer.

### Slet computer/hardware
Brug **Slet** på den ønskede række. En computer kan ikke slettes, mens den er udlånt, eller mens en afleveringsgodkendelse afventer.

### Søgning og stregkoder
- Brug søgefeltet til at søge på udstyrsnummer, serienummer, type, navn, fabrikat, model eller status.
- Klik **Print label** for at udskrive en stregkode til udstyret.
- **Scan med kamera** kan læse stregkoder direkte via kameraet.

---

## 6. Elevregister (med CPR)

Gå til **Elevregister** i sidebaren.

### Opret en ny elev
1. Udfyld **Elevnummer**, **Fulde navn** og **Stamklasse**.
2. **CPR-nummer** er valgfrit, men hvis det udfyldes, skal det være 10 cifre (fx `010190-1234`).
3. Udfyld **Adresse**, **Postnummer og by** samt **E-mail**.
4. Klik **Opret elev**.

> Eleven opretter selv sin adgangskode via elevportalen (se afsnit 16).

### Se og administrer elever
Tabellen viser elevnummer, navn, CPR, klasse, e-mail, kontostatus, adresse og handlinger.

### Skift elevens adgangskode
Vælg eleven i listen, indtast en ny adgangskode (min. 6 tegn), gentag den, og klik **Gem ny adgangskode**.

### Slet en elev
Klik **Slet** på eleven. Eleven kan ikke slettes, mens der er aktive lån — aflever først udstyret.

> **GDPR:** CPR-numre er følsomme personoplysninger. De gemmes i klartekst i databasen. Beskyt databaseadgangen godt, og opbevar kun CPR så længe det er nødvendigt.

---

## 7. Undervisere

Gå til **Undervisere** i sidebaren.

1. Udfyld **Navn**, **E-mail** og **Afdeling**.
2. Klik **Opret underviser**.

Undervisere kan bruges til at oprette computerreservationer. Når en underviser slettes, slettes tilhørende reservationer automatisk.

---

## 8. Reservationer

Gå til **Reservationer** i sidebaren.

1. Vælg **Underviser**.
2. Angiv **Antal computere**.
3. Vælg **Fra dato** og **Til dato**.
4. Skriv evt. et **Formål**.
5. Klik **Opret reservation**.

Reservationer tæller med i den samlede kapacitet, så systemet forhindrer overbookning. Aktive reservationer kan **annulleres**.

---

## 9. Opret udlån

Gå til **Nyt udlån** i sidebaren.

1. Vælg **Låner** (elev). Elever på blacklist vises ikke.
2. Vælg en **Ledig computer**.
3. Vælg **Udlånsdato** og **Afleveringsfrist**.
4. Klik **Opret udlån**.

Systemet kontrollerer automatisk, at eleven ikke er blacklistet, at computeren er ledig, og at der er nok ledige computere i hele perioden. Eleven modtager en kvittering på e-mail.

---

## 10. Registrer aflevering

Når en elev afleverer en computer:

1. Gå til **Dashboard** (eller elevportalen).
2. Find det aktive lån i tabellen **Aktive udlån**.
3. Klik **Registrer aflevering**.

Systemet markerer lånet som afleveret, gør computeren tilgængelig igen og sender en godkendelsesmail til eleven.

**Samlet aflevering:** Vælg flere lån fra samme elev i tabellen, og klik **Registrer valgte afleveringer**.

**Gentagne forsinkede afleveringer:** Eleven bliver automatisk sat på blacklist, hvis antallet af forsinkede afleveringer når en grænse (standard: 3).

---

## 11. Blacklist

Gå til **Blacklist** i sidebaren.

### Sæt en elev på blacklist
1. Vælg **Elev**.
2. Skriv en **Årsag**.
3. Klik **Sæt på blacklist**.

Elever på blacklist kan ikke få nye udlån, men kan stadig aflevere eksisterende lån.

### Fjern en elev fra blacklist
Klik **Fjern blacklist** på eleven.

Elever sættes automatisk på listen efter gentagne forsinkede afleveringer.

---

## 12. Adminansøgninger

Gå til **Adminansøgninger** i sidebaren (kun Nikolaj og admins med rettighed).

- **Behandle ansøgninger:** Godkend eller afvis ventende adminansøgninger.
- **Rettigheder:** Giv eller fjern adgang til at se og behandle adminansøgninger for enkelte admins.

Når en ansøgning godkendes, oprettes admin-kontoen, og ansøgeren modtager en besked på e-mail.

---

## 13. Administratorer

Gå til **Administratorer** i sidebaren (kun Nikolaj).

### Se administratorer
Tabellen viser alle administratorer i systemet.

### Opret ny administrator
1. Udfyld **Brugernavn**, **Fulde navn** og **E-mail**.
2. Klik **Opret administrator**.

Den nye admin får en midlertidig adgangskode og kan logge ind efter at have oprettet kontoen.

### Slet administrator
Klik **Slet admin** på den ønskede administrator, eller vælg flere og klik **Slet valgte administratorer**. Du kan ikke slette dig selv.

---

## 14. Grafiske statistikker

Dashboardet indeholder grafer, der viser:

- **Udlån pr. måned** (søjlediagram over de seneste 6 måneder).
- **Elevstatus** (fremskridtslinjer pr. elev med andel af afleveringer til tiden).

---

## 15. Automatiske rykkere

Systemet kan sende automatiske rykkere på e-mail til elever, der ikke har afleveret til tiden.

Kørsel foregår via kommandolinjen:

```
php automatic_reminders.php
```

- Rykkere sendes én gang på afleveringsdatoen.
- Overskredne lån rykkes med en advarsel.
- Gentagne forsinkede afleveringer fører til automatisk blacklisting.

**Testkørsel** (sender ingen mails):
```
php automatic_reminders.php --dry-run
```

Kørsler logges i `automatic_reminders.log` i AppData-mappen.

---

## 16. Elevportalen

Elever logger ind på elevportalen (`elev_login.php`) med deres **elevnummer** og den adgangskode, de selv har oprettet.

### Opret elevkonto
1. Gå til `elev_opret_konto.php`.
2. Indtast **elevnummer** og den **e-mail**, der står i elevregisteret.
3. Vælg en **adgangskode** (min. 10 tegn).
4. Eleven logger herefter ind med elevnummer og adgangskode + 2-faktorkode på e-mail.

### Elevens muligheder
- Se egne lån (aktive og tidligere) med afleveringsdato.
- Se reputation, badges og statistik.
- Registrere aflevering af eget udstyr.
- Skifte adgangskode.
- Slette egen konto (hvis der ikke er aktive lån).

---

## 17. Database og MySQL Workbench

Systemet bruger MySQL-databasen `skole_udlaan`. Du kan se data i MySQL Workbench:

1. Åbn din forbindelse (localhost, port 3306, bruger `root`).
2. Udvid `skole_udlaan` → **Tables**.
3. Højreklik på en tabel → **Select Rows - Limit 1000**.

### Nyttige queries

```sql
USE skole_udlaan;

-- Alle elever (med CPR)
SELECT elevnummer, navn, cpr, email, stamklasse FROM brugere WHERE rolle = 'elev';

-- Alle admins
SELECT elevnummer, navn, email FROM brugere WHERE rolle = 'admin';

-- Computere
SELECT * FROM computere;

-- Hardware
SELECT * FROM hardware_inventory;

-- Udlån
SELECT * FROM udlaan;

-- Undervisere
SELECT * FROM undervisere;
```

### Vigtige tabeller

| Tabel | Indhold |
|---|---|
| `brugere` | Elever og admins ( CPR, rolle) |
| `computere` | Computere/bærbare |
| `hardware_inventory` | Andet udstyr |
| `udlaan` | Udlån |
| `undervisere` | Undervisere |
| `computer_reservationer` | Reservationer |
| `admin_profiles` | Admin-profiler |
| `elev_profiler` | Elev-profiler |

---

## 18. Fejlfinding

| Problem | Løsning |
|---|---|
| "Kunne ikke forbinde til databasen" | Tjek at MySQL-tjenesten kører, og at `php_config.php` har korrekte oplysninger. |
| "Formularen er udløbet" | Genindlæs siden, og prøv igen (CSRF-token udløb). |
| Elev kan ikke oprettes | Tjek at elevnummer og e-mail ikke allerede findes. |
| Computer kan ikke slettes | Computerens skal være ledig, og ingen afleveringsgodkendelse må afvente. |
| Underviser kan ikke slettes | Underviseren slettes nu automatisk sammen med sine reservationer. |
| Admin-login kræver e-mail | Admin-kontoen mangler en gyldig personlig e-mail. Kontakt en administrator. |
| Automatiske rykkere sendes ikke | Kør `automatic_reminders.php`, og tjek logfilen i AppData. |

---

**IT-Udlån Pro — Udlånsregistrering af skolens bærbare computere**