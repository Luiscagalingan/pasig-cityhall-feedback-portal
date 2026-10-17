# Gabay sa Pag-set Up ng System sa Bagong Laptop o PC

## Pinakasimpleng setup (inirerekomenda)

Gumamit lamang ng **isang Server/Main PC** na laging naka-on habang ginagamit ang system. Dito naka-install ang system, database, XAMPP, at Python.

Ang Administrator, Office Head, at Office Staff ay puwedeng gumamit ng ibang laptop o PC sa pamamagitan lamang ng web browser.

| Uri ng computer | Kailangang i-install |
|---|---|
| Server/Main PC | XAMPP at Python (one-time setup lamang) |
| Admin/Office Head/Staff PC | Wala; Chrome, Edge, o Firefox lang |

**Sagot sa tanong tungkol sa Python:** Hindi kailangang mag-install o gumamit ng Python ang bawat user. Kailangan lamang ito sa Server/Main PC dahil ginagamit ito ng sentiment analysis ng system. Kapag online ang system sa Railway o katulad na hosting, kasama na ang Python sa deployment at browser na lang ang kailangan ng lahat ng users.

---

## A. Setup ng Server/Main PC (gagawin isang beses)

### 1. Ihanda ang installer at system files

Ihanda ang mga sumusunod:

- XAMPP na may PHP 8.2, 8.3, o 8.4
- Python 3 (64-bit)
- Kopya ng buong `pasig-cityhall-feedback-portal` project folder
- Pinakabagong encrypted system backup (`.pasigbak`) kung maglilipat mula sa lumang PC
- Backup passphrase, na dapat hiwalay na ibinigay sa authorized administrator

Huwag kopyahin ang `.venv` folder mula sa lumang computer. Gumawa ng bago sa bagong Server/Main PC.

### 2. I-install ang XAMPP

1. I-install ang XAMPP sa default location: `C:\xampp`.
2. Buksan ang **XAMPP Control Panel**.
3. I-start ang **Apache** at **MySQL**.
4. Kapag may Windows Firewall prompt, payagan ang access sa **Private networks** lamang kung gagamitin ang system sa office LAN/Wi-Fi.
5. Sa browser, buksan ang `http://localhost/`. Dapat lumabas ang XAMPP page.

### 3. Ilagay ang project

1. Kopyahin ang project folder sa:

   `C:\xampp\htdocs\pasig-cityhall-feedback-portal`

2. Siguraduhing naroon ang mahahalagang files:

   - `index.php`
   - `database\schema.sql`
   - `ml\predict.py`
   - `ml\models\svm_sentiment.joblib`
   - `ml\models\model_metrics.json`

### 4. Gumawa o mag-restore ng database

#### Para sa fresh installation

1. Buksan ang `http://localhost/phpmyadmin/`.
2. Piliin ang **Import**.
3. Piliin ang file na `database\schema.sql` mula sa project.
4. I-click ang **Import/Go**.
5. Dapat malikha ang database na `pasig_feedback_portal`.

#### Para sa paglipat mula sa lumang PC

Huwag gumamit ng fresh schema kung kailangang mapanatili ang tunay na records. Ipa-decrypt muna sa authorized technical administrator ang `.pasigbak`, pagkatapos ay i-import ang resulting SQL file sa MySQL. Huwag ibahagi o itabi sa parehong lugar ang backup file at passphrase.

### 5. I-install ang Python sa Server/Main PC

1. I-install ang 64-bit Python 3 gamit ang official installer.
2. Sa installer, i-check ang **Add Python to PATH**.
3. Buksan ang **Command Prompt** bilang normal user.
4. Patakbuhin nang sunod-sunod:

```bat
cd C:\xampp\htdocs\pasig-cityhall-feedback-portal
python -m venv .venv
.venv\Scripts\python.exe -m pip install -r ml\requirements.txt
```

Hindi na ito kailangang ulitin araw-araw. One-time installation ito, maliban kung buburahin o papalitan ang Python environment.

### 6. I-test ang system

Habang naka-start ang Apache at MySQL, patakbuhin:

```bat
cd C:\xampp\htdocs\pasig-cityhall-feedback-portal
C:\xampp\php\php.exe tests\smoke_test.php
C:\xampp\php\php.exe tests\svm_bridge_test.php
```

Pagkatapos, buksan:

`http://localhost/pasig-cityhall-feedback-portal/`

Mag-login gamit ang initial administrator account mula sa fresh schema:

- Username: `admin`
- Temporary/default password: `Admin123!`

**Palitan agad ang default password bago gamitin sa actual operation.** Huwag gamitin ang demo Office Head at Staff accounts sa production; palitan, i-archive, o gumawa ng authorized accounts mula sa administrator console.

### 7. Final checks sa administrator account

1. Buksan ang **System & Audit**.
2. Siguraduhing ang **SVM Bridge** ay `Working`.
3. Gumawa o i-check ang mga office.
4. Gumawa ng Office Head at Office Staff accounts gamit ang temporary passwords.
5. Ipagawa sa bawat user ang password change sa unang login.
6. Gumawa ng encrypted backup at itago ito sa secure at hiwalay na storage.

---

## B. Pag-access mula sa ibang laptop o PC (walang Python at walang XAMPP)

### 1. Ikonekta sa parehong network

Ikonekta ang Server/Main PC at user laptop sa parehong trusted office LAN o Wi-Fi.

### 2. Kunin ang IP address ng Server/Main PC

Sa Server/Main PC:

1. Buksan ang Command Prompt.
2. I-type ang `ipconfig`.
3. Hanapin ang **IPv4 Address**, halimbawa `192.168.1.50`.

Mas mainam na bigyan ang Server/Main PC ng reserved/static LAN IP sa router para hindi pabago-bago ang address.

### 3. Buksan ang system sa user PC

Sa Chrome, Edge, o Firefox ng user PC, buksan ang:

`http://SERVER-IP/pasig-cityhall-feedback-portal/`

Halimbawa:

`http://192.168.1.50/pasig-cityhall-feedback-portal/`

I-bookmark ang address. Mag-login gamit ang account na ibinigay ng administrator.

### 4. Kapag hindi mabuksan mula sa ibang PC

I-check ang sumusunod:

- Naka-on ang Server/Main PC.
- Naka-start ang Apache at MySQL sa XAMPP.
- Parehong office network ang dalawang computer.
- Tama ang IPv4 address.
- Pinayagan ang Apache sa Windows Firewall para sa Private network.
- Walang ibang application na gumagamit ng Apache/MySQL ports.

Huwag basta buksan ang system sa public internet gamit ang router port forwarding. Gumamit ng properly configured HTTPS hosting o humingi ng tulong sa authorized IT administrator.

---

## C. Araw-araw na paggamit

### Server/Main PC operator

1. I-on ang Server/Main PC.
2. Buksan ang XAMPP Control Panel.
3. I-start ang Apache at MySQL.
4. I-check na nabubuksan ang system.
5. Panatilihing naka-on ang PC habang may gumagamit.
6. Sa pagtatapos ng araw, siguraduhing walang active import o backup bago i-shut down.

### Administrator, Office Head, at Staff

1. Buksan ang saved system address sa browser.
2. Mag-login sa sariling account.
3. Mag-logout pagkatapos gamitin, lalo na sa shared PC.

Walang kailangang i-type na Python, PHP, o database command sa normal na araw-araw na paggamit.

---

## D. Paglipat sa panibagong Server/Main PC

1. Sa lumang system, gumawa ng bagong encrypted backup sa **System & Audit**.
2. Kopyahin ang project folder, ngunit huwag isama ang lumang `.venv`.
3. I-install ang XAMPP at Python sa bagong Server/Main PC.
4. Ilagay ang project sa tamang `htdocs` folder.
5. Gumawa ng bagong `.venv` at i-install ang `ml\requirements.txt`.
6. I-decrypt at i-import ang authorized database backup.
7. Patakbuhin ang smoke test at SVM bridge test.
8. I-check ang accounts, feedback totals, reports, at **SVM Bridge: Working**.
9. I-update ang bookmark ng mga user kung nagbago ang server IP address.
10. Huwag burahin o i-reformat ang lumang server hangga't hindi kumpleto ang validation at secure backup.

---

## E. Mas madaling option: hosted/online server

Kung ayaw mag-maintain ng XAMPP at Python sa opisina, maaaring i-deploy ang system sa Railway gamit ang kasalukuyang `Dockerfile`. Sa ganitong setup:

- Walang Python o XAMPP na i-install sa laptops ng administrator at staff.
- Browser at internet connection lang ang kailangan.
- Awtomatikong kasama sa server image ang PHP, Apache, Python, at ML dependencies.
- Kailangan pa ring i-configure nang tama ang MySQL, HTTPS, persistent storage, environment variables, access control, at backups.

Ang production deployment ay dapat gawin ng authorized technical administrator dahil may database, privacy, security, at backup configuration ito.

---

## Quick acceptance checklist

- [ ] Gumagana ang Apache at MySQL.
- [ ] Nabubuksan ang login page sa Server/Main PC.
- [ ] Nabubuksan ang system mula sa isang ibang PC sa office network.
- [ ] Napalitan ang default administrator password.
- [ ] Tama ang Office Head at Staff accounts at office assignments.
- [ ] `SVM Bridge: Working` ang status.
- [ ] Pumasa ang smoke test at SVM bridge test.
- [ ] Nasubukan ang sample survey submission at dashboard result.
- [ ] May encrypted backup at hiwalay na backup passphrase.
- [ ] Alam ng operator na browser lang ang kailangan ng regular users.
