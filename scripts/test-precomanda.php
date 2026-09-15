<?php

declare(strict_types=1);

/**
 * Test cap-coada pentru precomanda.
 *
 * Trece prin tot drumul, cu clasele reale si cu baza reala:
 *
 *   1. un produs bifat „valabil pentru precomanda", fara stoc, cu plafoane —
 *      2 bucati pe comanda, 5 bucati cu totul;
 *   2. limita efectiva e cea mai mica dintre ele si scade pe masura ce se
 *      precomanda;
 *   3. comanda cu un astfel de produs intra in asteptare si NU pleaca in ERP:
 *      motivul blocarii se citeste explicit, fara sa se trimita nimic;
 *   4. comanda fara produse de precomanda nu e atinsa deloc;
 *   5. cand plafonul total se umple, limita ajunge la zero — nu se mai poate
 *      precomanda;
 *   6. o comanda anulata elibereaza locul inapoi in plafon;
 *   7. eliberarea scoate comanda din asteptare, iar dupa ea drumul spre ERP e
 *      liber.
 *
 * NU trimite nimic in ERP: pasul 7 se verifica pe starea din baza si pe motivul
 * blocarii, nu apasand butonul — un test nu are ce cauta cu o comanda inventata
 * in evidenta contabila.
 *
 * Curatenia se face la final, si daca pica ceva la mijloc: se sterg comenzile,
 * liniile lor si produsul de test.
 *
 * Rulare:
 *   php scripts/test-precomanda.php
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Support\Database;
use App\Support\ErpSync;
use App\Support\Precomanda;

$config = require __DIR__ . '/../config/app.php';
$db = Database::connection($config['db']);

if (!$db instanceof PDO) {
    fwrite(STDERR, "Conexiunea la baza de date nu este disponibila.\n");
    exit(1);
}

$caderi = 0;
$verifica = static function (bool $conditie, string $mesaj, string $detaliu = '') use (&$caderi): void {
    if ($conditie) {
        echo "  ✓ {$mesaj}\n";
        return;
    }
    $caderi++;
    echo "  ✗ {$mesaj}" . ($detaliu !== '' ? " — {$detaliu}" : '') . "\n";
};

Precomanda::ensureSchema($db);
ErpSync::ensureSchema($db);

$produsId = 0;
$produsSimpluId = 0;
$comenzi = [];

/** Face o comanda de test cu o singura linie. */
$faComanda = static function (PDO $db, int $produsId, int $cantitate, string $sufix) use (&$comenzi): int {
    $numar = 'ZZPRE-' . $sufix . '-' . substr((string) time(), -5);
    // Adresa si telefonul sunt obligatorii in schema; se completeaza cu
    // valori de test, ca inserarea sa nu cada pe o coloana NOT NULL.
    $db->prepare(
        "INSERT INTO orders (order_number, status, payment_method, payment_status, subtotal, total, created_at,
                             billing_first_name, billing_last_name, billing_phone, billing_email,
                             billing_address_line1, billing_city, billing_county, billing_postcode)
         VALUES (:nr, 'pending', 'cod', 'unpaid', 100, 100, NOW(),
                 'ZZ', 'TEST PRECOMANDA', '0700000000', 'zz-test@example.invalid',
                 'Strada Testelor 1', 'Bucuresti', 'Bucuresti', '000000')"
    )->execute(['nr' => $numar]);
    $orderId = (int) $db->lastInsertId();
    $db->prepare(
        'INSERT INTO order_items (order_id, product_id, product_name, quantity, unit_price, line_total)
         VALUES (:oid, :pid, :nume, :cant, 50, :total)'
    )->execute([
        'oid' => $orderId,
        'pid' => $produsId,
        'nume' => 'ZZ TEST PRECOMANDA',
        'cant' => $cantitate,
        'total' => 50 * $cantitate,
    ]);
    $comenzi[] = $orderId;
    return $orderId;
};

try {
    // ── Decor ────────────────────────────────────────────────
    $slug = 'zz-test-precomanda-' . substr((string) time(), -6);
    $db->prepare(
        'INSERT INTO products (name, slug, price, stock, out_of_stock, preorder_enabled,
                               preorder_max_per_order, preorder_max_total, is_active, created_at)
         VALUES (:nume, :slug, 50, 0, 1, 1, 2, 5, 1, NOW())'
    )->execute(['nume' => 'ZZ TEST PRECOMANDA', 'slug' => $slug]);
    $produsId = (int) $db->lastInsertId();

    $db->prepare(
        'INSERT INTO products (name, slug, price, stock, out_of_stock, preorder_enabled, is_active, created_at)
         VALUES (:nume, :slug, 50, 10, 0, 0, 1, NOW())'
    )->execute(['nume' => 'ZZ TEST NORMAL', 'slug' => $slug . '-normal']);
    $produsSimpluId = (int) $db->lastInsertId();

    $citesteProdus = static function (PDO $db, int $id): array {
        $stmt = $db->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (array) ($stmt->fetch() ?: []);
    };

    // ── 1. Produsul e de precomanda si are plafoanele lui ─────
    echo "1) Produsul de precomanda\n";
    $produs = $citesteProdus($db, $produsId);
    $verifica(Precomanda::estePrecomanda($produs), 'produsul e marcat „valabil pentru precomanda"');
    $verifica(Precomanda::limitaPerComanda($produs) === 2, 'limita pe comanda e 2', (string) Precomanda::limitaPerComanda($produs));
    $verifica(Precomanda::maximTotal($produs) === 5, 'plafonul total e 5', (string) Precomanda::maximTotal($produs));
    $verifica(
        Precomanda::limitaEfectiva($db, $produs) === 2,
        'la inceput un client poate lua 2 bucati (cea mai mica dintre plafoane)',
        (string) Precomanda::limitaEfectiva($db, $produs)
    );

    $produsSimplu = $citesteProdus($db, $produsSimpluId);
    $verifica(!Precomanda::estePrecomanda($produsSimplu), 'produsul obisnuit nu e de precomanda');
    $verifica(
        Precomanda::limitaEfectiva($db, $produsSimplu) === null,
        'produsul obisnuit n-are limita de precomanda'
    );

    // ── 2. Prima comanda intra in asteptare ───────────────────
    echo "\n2) Comanda cu produs de precomanda\n";
    $comanda1 = $faComanda($db, $produsId, 2, 'A');
    $marcata = Precomanda::marcheazaComanda($db, $comanda1);
    $verifica($marcata, 'comanda a fost pusa in asteptare');

    $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
    $stmt->execute(['id' => $comanda1]);
    $randComanda1 = (array) $stmt->fetch();
    $verifica(
        Precomanda::esteInAsteptare($randComanda1),
        'starea comenzii e „asteptare"',
        (string) ($randComanda1['preorder_status'] ?? 'gol')
    );

    $motiv = ErpSync::motivBlocare($db, $comanda1);
    $verifica(
        $motiv !== null && str_contains(mb_strtolower($motiv), 'precomand'),
        'comanda NU pleaca in ERP: motivul spune de ce',
        $motiv ?? 'nimic nu o blocheaza'
    );

    $verifica(
        Precomanda::cantitatePrecomandata($db, $produsId) === 2,
        's-au precomandat 2 bucati',
        (string) Precomanda::cantitatePrecomandata($db, $produsId)
    );
    $verifica(
        Precomanda::ramasDinTotal($db, $citesteProdus($db, $produsId)) === 3,
        'au mai ramas 3 bucati din plafonul total',
        (string) Precomanda::ramasDinTotal($db, $citesteProdus($db, $produsId))
    );

    // ── 3. Comanda fara produse de precomanda nu se atinge ────
    echo "\n3) Comanda obisnuita\n";
    $comandaSimpla = $faComanda($db, $produsSimpluId, 1, 'B');
    $verifica(
        !Precomanda::marcheazaComanda($db, $comandaSimpla),
        'comanda fara produse de precomanda nu e pusa in asteptare'
    );
    $motivSimplu = ErpSync::motivBlocare($db, $comandaSimpla);
    $verifica(
        $motivSimplu === null,
        'si nimic n-o opreste sa plece in ERP',
        $motivSimplu ?? ''
    );

    // ── 4. Plafonul total se umple ────────────────────────────
    echo "\n4) Plafonul total\n";
    $comanda2 = $faComanda($db, $produsId, 3, 'C');
    Precomanda::marcheazaComanda($db, $comanda2);
    $verifica(
        Precomanda::cantitatePrecomandata($db, $produsId) === 5,
        's-au precomandat 5 bucati cu totul',
        (string) Precomanda::cantitatePrecomandata($db, $produsId)
    );
    $verifica(
        Precomanda::limitaEfectiva($db, $citesteProdus($db, $produsId)) === 0,
        'plafonul s-a umplut: nu se mai poate precomanda nimic',
        (string) Precomanda::limitaEfectiva($db, $citesteProdus($db, $produsId))
    );
    $verifica(
        str_contains(Precomanda::mesajLimita(0), 's-a inchis')
            || str_contains(Precomanda::mesajLimita(0), 's-a închis'),
        'clientul primeste un mesaj care spune ca precomanda s-a inchis',
        Precomanda::mesajLimita(0)
    );

    // ── 5. O comanda anulata elibereaza locul ─────────────────
    echo "\n5) Comanda anulata\n";
    $db->prepare("UPDATE orders SET status = 'cancelled' WHERE id = :id")->execute(['id' => $comanda2]);
    $verifica(
        Precomanda::cantitatePrecomandata($db, $produsId) === 2,
        'cele 3 bucati anulate nu mai ocupa plafonul',
        (string) Precomanda::cantitatePrecomandata($db, $produsId)
    );
    $verifica(
        Precomanda::limitaEfectiva($db, $citesteProdus($db, $produsId)) === 2,
        'se poate precomanda din nou, pana la limita pe comanda',
        (string) Precomanda::limitaEfectiva($db, $citesteProdus($db, $produsId))
    );

    // ── 6. Eliberarea deschide drumul spre ERP ────────────────
    // Butonul din admin cheama `Precomanda::elibereaza`, care si trimite in
    // ERP. Aici se scrie doar starea: un test n-are ce cauta cu o comanda
    // inventata in evidenta contabila a firmei.
    echo "\n6) Eliberarea\n";
    $db->prepare(
        "UPDATE orders SET preorder_status = :st, preorder_released_at = NOW() WHERE id = :id"
    )->execute(['st' => Precomanda::ELIBERATA, 'id' => $comanda1]);

    $stmt = $db->prepare('SELECT * FROM orders WHERE id = :id');
    $stmt->execute(['id' => $comanda1]);
    $randEliberata = (array) $stmt->fetch();
    $verifica(
        !Precomanda::esteInAsteptare($randEliberata),
        'comanda nu mai e in asteptare'
    );
    $motivDupa = ErpSync::motivBlocare($db, $comanda1);
    $verifica(
        $motivDupa === null,
        'dupa eliberare, nimic n-o mai opreste sa plece in ERP',
        $motivDupa ?? ''
    );
    $verifica(
        Precomanda::cantitatePrecomandata($db, $produsId) === 2,
        'comanda eliberata ramane la socoteala plafonului — marfa tot trebuie adusa',
        (string) Precomanda::cantitatePrecomandata($db, $produsId)
    );

    $rezultatGresit = Precomanda::elibereaza($db, $comandaSimpla);
    $verifica(
        ($rezultatGresit['ok'] ?? true) === false,
        'o comanda care nu e in precomanda nu se poate „elibera"',
        (string) ($rezultatGresit['message'] ?? '')
    );
} finally {
    echo "\nCuratenie…\n";
    foreach ($comenzi as $id) {
        try {
            $db->prepare('DELETE FROM order_items WHERE order_id = :id')->execute(['id' => $id]);
            $db->prepare('DELETE FROM orders WHERE id = :id')->execute(['id' => $id]);
        } catch (Throwable) {
        }
    }
    foreach ([$produsId, $produsSimpluId] as $id) {
        if ($id > 0) {
            try {
                $db->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $id]);
            } catch (Throwable) {
            }
        }
    }

    $ramase = 0;
    try {
        $ramase = (int) $db->query(
            "SELECT COUNT(*) FROM orders WHERE order_number LIKE 'ZZPRE-%'"
        )->fetchColumn();
        $ramase += (int) $db->query(
            "SELECT COUNT(*) FROM products WHERE name LIKE 'ZZ TEST %'"
        )->fetchColumn();
    } catch (Throwable) {
    }
    echo "  randuri de test ramase: {$ramase}\n";
    if ($ramase > 0) {
        $caderi++;
    }
}

echo $caderi === 0
    ? "\nTOTUL E IN REGULA — precomanda se limiteaza si asteapta butonul.\n"
    : "\n{$caderi} verificari au picat (vezi ✗ mai sus).\n";
exit($caderi === 0 ? 0 : 1);
