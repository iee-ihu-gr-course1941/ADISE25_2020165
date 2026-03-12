# ADISE25_2020165

# Ξερή (Xeri) - ADISE Project

Υλοποίηση του παιχνιδιού **Ξερή** με αρχιτεκτονική **PHP Web API + MySQL + Browser GUI**

## Περιγραφή

Το σύστημα υποστηρίζει:
- Multiplayer για 2 παίκτες (P1/P2) από διαφορετικό browser/PC.
- Διαχείριση σειράς παιξιάς και έλεγχο κανόνων Ξερής.
- Πλήρη αποθήκευση κατάστασης παρτίδας σε βάση.
- Ζωντανό scoreboard και ιστορικό κινήσεων.

## Τεχνολογίες

- PHP
- MySQL / MariaDB
- Apache (XAMPP σε localhost)
- JavaScript (vanilla)

## Δομή αρχείων

```text
xeri/
├── index.php              # UI + API entrypoint
├── router.php             # Router για php -S
├── xeri.sql               # Schema βάσης
├── lib/
│   ├── dbconnect.php      # DB layer (SQLite fallback / MySQL)
│   ├── db_upass.php       # Τοπικές ρυθμίσεις DB (δεν γίνεται commit)
│   └── game_logic.php     # Κανόνες παιχνιδιού/score
└── README.md
```

## Κανόνες παιχνιδιού που υλοποιούνται

- 2 παίκτες.
- 1 τράπουλα 48 φύλλων (`2..10, J, Q, K` για κάθε suit).
- Στην αρχή γύρου: 6 φύλλα ανά παίκτη, 4 φύλλα στη στοίβα τραπεζιού.
- Ορατό/παίξιμο είναι μόνο το πάνω φύλλο της στοίβας.
- Συλλογή με:
  - ίδιο figure με πάνω φύλλο ή
  - Βαλέ.
- Ξερή: όταν πριν τη συλλογή υπάρχει 1 φύλλο στη στοίβα.
- Τέλος γύρου όταν αδειάσουν και τα 2 χέρια.
- Τα υπόλοιπα φύλλα τραπεζιού πάνε στον τελευταίο συλλέκτη.

## Βαθμολόγηση

- +3: περισσότερα χαρτιά (όχι σε ισοπαλία)
- +1: `2S`
- +1: `10D`
- +1: κάθε `K`, `Q`, `J`, `10` (εκτός `10D`)
- +10: κάθε Ξερή
- +20: Ξερή με Βαλέ

## API endpoints

- `GET /?request=status` - Επιστρέφει τη συνολική κατάσταση παιχνιδιού (status, σειρά, γύρος, deadlock).
- `GET /?request=players` - Επιστρέφει πληροφορίες παικτών (ποιοι έχουν συνδεθεί και βασικά στοιχεία).
- `PUT /?request=players/1` - Συνδέει/δηλώνει τον Παίκτη 1 και δημιουργεί token συνεδρίας.
- `PUT /?request=players/2` - Συνδέει/δηλώνει τον Παίκτη 2 και δημιουργεί token συνεδρίας.
- `GET /?request=board` - Επιστρέφει board view για τον τρέχοντα παίκτη (χέρι, top card, στοιχεία γύρου).
- `POST /?request=board` - Ελέγχει ροή παρτίδας: αρχικοποίηση, έναρξη, ή μετάβαση στον επόμενο γύρο.
- `POST /?request=move` - Εκτελεί κίνηση παίκτη (`throw` ή `collect`) με όλους τους απαραίτητους ελέγχους.
- `GET /?request=history` - Επιστρέφει το ιστορικό κινήσεων για παρακολούθηση/παρουσίαση.
- `POST /?request=reset` - Κάνει επαναφορά της παρτίδας στην αρχική κατάσταση.

## Ρύθμιση για localhost (XAMPP + MySQL tunnel προς users)

1. Στο Apache config (`httpd-xampp.conf`) πρόσθεσε:
   ```apache
   Alias /adise "C:/Users/manol/Desktop/Σημειωσεις Πανεπηστημιο/Οτι έμεινε/ADISE/SOLO/xeri"
   <Directory "C:/Users/manol/Desktop/Σημειωσεις Πανεπηστημιο/Οτι έμεινε/ADISE/SOLO/xeri">
       AllowOverride All
       Require all granted
   </Directory>
   ```
2. Επανεκκίνηση Apache από XAMPP.
3. Άνοιγμα SSH tunnel:
   ```bash
   ssh -L3307:/home/staff/USERNAME/mysql/run/mysql.sock USERNAME@users.iee.ihu.gr
   ```
4. Ρύθμιση `lib/db_upass.php`:
   - `$DB_DRIVER = 'mysql';`
   - `$DB_HOST = '127.0.0.1';`
   - `$DB_PORT = 3307;`
   - `$DB_NAME = 'xeri_db';`
   - `$DB_USER`, `$DB_PASS` με σωστά users credentials.
5. Import schema:
   ```bash
   mysql -u $USER -p xeri_db < xeri.sql
   ```
6. Άνοιγμα εφαρμογής:
   - `http://localhost/adise/`

## Έλεγχος ότι γράφει στη βάση

Εκτέλεσε στο HeidiSQL:

```sql
SELECT * FROM game_status;
SELECT position, username, token FROM players;
SELECT * FROM move_log ORDER BY id DESC LIMIT 20;
SELECT * FROM player_sessions ORDER BY id DESC LIMIT 20;
```

